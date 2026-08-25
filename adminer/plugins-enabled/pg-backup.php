<?php

/**
 * Real PostgreSQL backup and restore, from inside Adminer, via pg_dump/pg_restore.
 *
 * Why this exists rather than more patches to Adminer's SQL export: that export
 * is a table-structure-and-rows dump, not a backup, and it cannot round trip a
 * non-trivial schema. Measured against f3s (155 tables), a full Adminer dump
 * restores with 149 errors - booleans dumped as 0/1, enum defaults emitted
 * unquoted (`DEFAULT FACELOG`, a column reference), partition children never
 * re-attached, `ALTER TABLE ONLY` foreign keys on partitioned parents, no
 * CREATE EXTENSION - and it carries no owners, grants or sequence values.
 * See plugins-enabled/dump-pgsql-fix.php.
 *
 * RESTORE NEVER OVERWRITES. It creates a new database and loads the archive into
 * that, refusing to run if the name is already taken. Two reasons:
 *
 * 1. Safety. An in-place restore has to empty the target first, and emptying is
 *    not reversible if the load then fails. That is exactly how the live f3s
 *    database got wiped earlier: a destructive step ran through a path that
 *    could not roll it back.
 * 2. `pg_restore --clean` does not work on this schema at all. It emits
 *    `ALTER TABLE ONLY <partition> DROP CONSTRAINT <partition>_pkey`, and a
 *    constraint inherited from a partitioned parent cannot be dropped on its
 *    own: `ERROR: cannot drop inherited constraint`. Reproduced on a two-table
 *    partitioned schema - `--clean` into a non-empty target fails, no `--clean`
 *    into an empty target succeeds with partitions re-attached. f3s has 5
 *    partitioned parents and 422 attached partitions, so --clean is unusable
 *    here. An empty target needs no --clean.
 *
 * To replace a database, restore into a new one and rename with Adminer's own
 * database page (`&database=`), or point the application at the new name. The
 * old database stays until you delete it, which is the rollback.
 *
 * Design notes:
 *
 * - No shell string is ever built. The script handed to `sh -c` is a fixed
 *   literal; the binary, its arguments and the log paths arrive through argv and
 *   the environment, so nothing user-controlled is ever parsed by a shell.
 * - Jobs are detached (`setsid` + `&`) and write their combined output to a
 *   `.log` and their exit status to a `.done`. The container serves requests
 *   with `php -S`, which is single-threaded: a synchronous multi-minute restore
 *   would freeze the whole console. Job state is just those files.
 * - Backup and restore keep separate state. They used to share a stem, so a
 *   failed restore marked the archive itself "failed" when the archive was fine.
 * - The password comes from Adminer's session and is passed as PGPASSWORD in the
 *   child's environment - never in argv (readable through /proc), never on disk.
 * - Names of existing files are matched against a strict pattern and then
 *   re-checked with realpath() against the backup directory, so a crafted `../`
 *   cannot escape. Generated names are never taken from the client.
 * - Download runs from the headers() hook, not homepage(). page_headers() calls
 *   `adminer()->headers()` after the response headers are set but before any body
 *   output and before ob_start(), which is the only point where a plugin can
 *   still replace Content-Type and stream a file. homepage() is far too late.
 *
 * PostgreSQL only, and silent unless pg_dump is actually present, so an older
 * image without postgresql18-client degrades to no feature rather than a fatal.
 */
final class AdminerPgBackup extends Adminer\Plugin {
	private const DIR = '/backups';
	private const PG_DUMP = '/usr/bin/pg_dump';
	private const PG_RESTORE = '/usr/bin/pg_restore';

	/** Archive names, generated and accepted, only ever look like this. */
	private const NAME_RE = '~^[A-Za-z0-9][A-Za-z0-9._-]{0,120}\.dump$~';

	/** PostgreSQL identifier, conservative: no quoting games, fits in 63 bytes. */
	private const DBNAME_RE = '~^[A-Za-z_][A-Za-z0-9_]{0,62}$~';

	/** Archives kept per source database; older finished ones are pruned. */
	private const KEEP = 10;

	/** Refuse to start a dump with less than this much room left. */
	private const MIN_FREE = 1073741824; // 1 GiB

	/** Restore state lives on a dotfile stem so NAME_RE never lists it. */
	private const RESTORE = self::DIR . '/.restore';

	/** Fixed literal. $1 is the binary, the rest its argv; paths come from env. */
	private const RUNNER = 'BIN="$1"; shift; { "$BIN" "$@" >"$PGB_LOG" 2>&1; echo $? >"$PGB_DONE"; } &';

	/**
	 * Deliberately does NOT require a database to be selected: the whole point of
	 * living on the server page is that you can dump any database without first
	 * connecting into another one.
	 */
	private function usable(): bool {
		return defined('Adminer\DRIVER')
			&& Adminer\DRIVER === 'pgsql'
			&& isset($_GET['username'])
			&& is_executable(self::PG_DUMP)
			&& is_dir(self::DIR) && is_writable(self::DIR);
	}

	/** The "Select database" page - DB empty and no other action claimed it. */
	private function onServerPage(): bool {
		return defined('Adminer\DB') && Adminer\DB === '';
	}

	/** @return list<string> databases that can be dumped */
	private function databaseList(): array {
		try {
			return Adminer\get_vals('SELECT datname FROM pg_database WHERE NOT datistemplate ORDER BY 1');
		} catch (\Throwable $e) {
			error_log('pg-backup: pg_database list failed: ' . $e->getMessage());
			return array();
		}
	}

	// ---------------------------------------------------------------- job files

	/** Resolve a client-supplied archive name to a contained path, or null. */
	private function path(string $name): ?string {
		if (!preg_match(self::NAME_RE, $name)) {
			return null;
		}
		$base = realpath(self::DIR);
		$full = realpath(self::DIR . '/' . $name);
		if ($base === false || $full === false || strpos($full, $base . '/') !== 0) {
			return null;
		}
		return $full;
	}

	/** @return array{state:string,log:string} state of the job at $stem */
	private function jobState(string $stem, bool $expectFile = false): array {
		$done = @file_get_contents("$stem.done");
		if ($done === false) {
			$state = file_exists("$stem.log") ? 'running' : ($expectFile ? 'ok' : 'none');
		} else {
			$state = trim($done) === '0' ? 'ok' : 'failed';
		}
		return array('state' => $state, 'log' => trim((string) @file_get_contents("$stem.log")));
	}

	/** @return list<array{name:string,size:int,time:int,state:string,log:string}> newest first */
	private function backups(): array {
		$out = array();
		foreach ((array) @scandir(self::DIR) as $entry) {
			if (!preg_match(self::NAME_RE, (string) $entry)) {
				continue;
			}
			$full = self::DIR . '/' . $entry;
			$job = $this->jobState(substr($full, 0, -5), true);
			$out[] = array(
				'name' => (string) $entry,
				'size' => (int) @filesize($full),
				'time' => (int) @filemtime($full),
				'state' => $job['state'],
				'log' => $job['log'],
			);
		}
		usort($out, function ($a, $b) { return $b['time'] <=> $a['time']; });
		return $out;
	}

	/** Description of a job still running, or null. Only one runs at a time. */
	private function busy(): ?string {
		if ($this->jobState(self::RESTORE)['state'] === 'running') {
			return 'restore';
		}
		foreach ($this->backups() as $b) {
			if ($b['state'] === 'running') {
				return $b['name'];
			}
		}
		return null;
	}

	// ------------------------------------------------------------------ spawning

	/** @param list<string> $args */
	private function spawn(string $bin, array $args, string $stem): bool {
		$password = Adminer\get_password();
		if (!is_string($password)) {
			return false;
		}
		@file_put_contents("$stem.log", '');
		@unlink("$stem.done");
		$devnull = array(
			array('file', '/dev/null', 'r'),
			array('file', '/dev/null', 'a'),
			array('file', '/dev/null', 'a'),
		);
		$proc = @proc_open(
			array_merge(array('setsid', 'sh', '-c', self::RUNNER, 'sh', $bin), $args),
			$devnull,
			$pipes,
			self::DIR,
			array(
				'PGB_LOG' => "$stem.log",
				'PGB_DONE' => "$stem.done",
				'PGPASSWORD' => $password,
				'PATH' => '/usr/bin:/bin',
			)
		);
		if (!is_resource($proc)) {
			return false;
		}
		proc_close($proc); // returns at once: setsid's child is already detached
		return true;
	}

	/** @return list<string> connection flags for one database */
	private function target(string $db): array {
		list($host, $port) = Adminer\host_port((string) Adminer\SERVER);
		return array(
			'-h', ($host !== '' ? $host : 'localhost'),
			'-p', ($port !== '' ? $port : '5432'),
			'-U', (string) $_GET['username'],
			'-d', $db,
		);
	}

	// ------------------------------------------------------------------ database

	private function dbExists(string $name): bool {
		try {
			return (string) Adminer\get_val('SELECT 1 FROM pg_database WHERE datname = ' . Adminer\q($name)) === '1';
		} catch (\Throwable $e) {
			error_log('pg-backup: pg_database lookup failed: ' . $e->getMessage());
			return true; // fail closed: never proceed on an unknown target
		}
	}

	private function createDb(string $name): bool {
		try {
			$ok = (bool) Adminer\connection()->query('CREATE DATABASE ' . Adminer\idf_escape($name));
		} catch (\Throwable $e) {
			error_log('pg-backup: CREATE DATABASE failed: ' . $e->getMessage());
			return false;
		}
		if ($ok) {
			// Adminer caches the database list in the session and only clears it
			// when CREATE/DROP DATABASE goes through its own SQL page.
			Adminer\restart_session();
			Adminer\set_session('dbs', null);
			Adminer\stop_session();
		}
		return $ok;
	}

	/**
	 * Keep only the newest KEEP finished archives for one source database.
	 * Returns how many were removed - reported to the user, never silent: a
	 * backup tool that quietly throws away history is worse than none.
	 */
	private function prune(string $prefix): int {
		$finished = array();
		foreach ($this->backups() as $b) { // newest first
			if ($b['state'] !== 'running' && strpos($b['name'], $prefix . '-') === 0) {
				$finished[] = $b['name'];
			}
		}
		$removed = 0;
		foreach (array_slice($finished, self::KEEP) as $name) {
			$full = $this->path($name);
			if ($full === null) {
				continue;
			}
			$stem = substr($full, 0, -5);
			@unlink($full);
			@unlink("$stem.log");
			@unlink("$stem.done");
			$removed++;
		}
		return $removed;
	}

	/**
	 * The database an archive came from, recovered from the generated file name.
	 * Used as the default restore target: restoring `f3s-...dump` should offer
	 * `f3s`, not a stamped variant nobody asked for. It is only accepted if no
	 * such database exists yet - drop the old one first, which is what
	 * pg-drop-force.php is for.
	 */
	private function sourceDb(string $archive): string {
		$prefix = preg_replace('~-\d{8}-\d{6}\.dump$~', '', $archive);
		$prefix = preg_replace('~[^A-Za-z0-9_]+~', '_', (string) $prefix);
		$prefix = ltrim($prefix, '0123456789');
		return substr($prefix !== '' ? $prefix : 'restore', 0, 63);
	}

	// ------------------------------------------------------- command text (display)

	/**
	 * Quote one argument for display. This output is never executed - it exists so
	 * the same job can be reproduced in a terminal against a downloaded file - but
	 * it is quoted properly anyway so copy-paste cannot misfire.
	 */
	private function shellArg(string $arg): string {
		return preg_match('~^[A-Za-z0-9_.:/=-]+$~', $arg)
			? $arg
			: "'" . str_replace("'", "'\\''", $arg) . "'";
	}

	/** @param list<string> $args */
	private function cmd(string $bin, array $args): string {
		$out = array(basename($bin));
		foreach ($args as $a) {
			$out[] = $this->shellArg($a);
		}
		return implode(' ', $out);
	}

	/** Connection flags without the trailing -d <db>. */
	private function conn(): array {
		return array_slice($this->target('x'), 0, -2);
	}

	private function cmdBackup(string $db): string {
		// --file is appended raw so the $(date) stays a shell substitution: the
		// point of showing the command is that it can be pasted and run.
		return $this->cmd(self::PG_DUMP, array_merge(
			array('--format=custom', '--compress=6'),
			$this->target($db)
		)) . ' --file=' . $this->shellArg($db) . '-$(date -u +%Y%m%d-%H%M%S).dump';
	}

	/** createdb + pg_restore, against a file in the current directory. */
	private function cmdRestore(string $archive, string $targetDb): string {
		return $this->cmd('/usr/bin/createdb', array_merge($this->conn(), array($targetDb)))
			. "\n"
			. $this->cmd(self::PG_RESTORE, array_merge(
				array('--single-transaction', '--no-owner', '--no-privileges'),
				$this->target($targetDb),
				array($archive)
			));
	}

	// ------------------------------------------------------------------- actions

	/** @return string message, '' if nothing happened */
	private function act(): string {
		if (!$_POST) {
			return '';
		}
		if (!Adminer\verify_token()) {
			return $this->lang('bad-token');
		}
		if ($this->busy() !== null) {
			return $this->lang('busy');
		}

		if (isset($_POST['pgb_create'])) {
			// Only a name the server itself reports: the select is a convenience,
			// not the boundary.
			$db = (string) ($_POST['pgb_db'] ?? '');
			if (!in_array($db, $this->databaseList(), true)) {
				return $this->lang('bad-source');
			}
			$free = @disk_free_space(self::DIR);
			if ($free !== false && $free < self::MIN_FREE) {
				return $this->lang('no-space');
			}
			$prefix = preg_replace('~[^A-Za-z0-9_-]+~', '_', $db);
			$stem = self::DIR . '/' . $prefix . '-' . gmdate('Ymd-His');
			$ok = $this->spawn(self::PG_DUMP, array_merge(
				array('--format=custom', '--compress=6', '--file=' . "$stem.dump"),
				$this->target($db)
			), $stem);
			if (!$ok) {
				return $this->lang('spawn-failed');
			}
			$pruned = $this->prune((string) $prefix);
			return $this->lang('started-dump') . ' ' . $db
				. ($pruned ? ' (' . $this->lang('pruned') . ' ' . $pruned . ')' : '');
		}

		$full = $this->path((string) ($_POST['pgb_file'] ?? ''));

		if (isset($_POST['pgb_delete'])) {
			if ($full === null) {
				return $this->lang('bad-name');
			}
			$stem = substr($full, 0, -5);
			@unlink($full);
			@unlink("$stem.log");
			@unlink("$stem.done");
			return $this->lang('deleted');
		}

		if (isset($_POST['pgb_restore'])) {
			if ($full === null) {
				return $this->lang('bad-name');
			}
			$want = trim((string) ($_POST['pgb_target'] ?? ''));
			if (!preg_match(self::DBNAME_RE, $want)) {
				return $this->lang('bad-target');
			}
			// Refusing an existing name is what makes restore non-destructive.
			if ($this->dbExists($want)) {
				return $this->lang('target-exists');
			}
			if (!$this->createDb($want)) {
				return $this->lang('create-failed');
			}
			@file_put_contents(self::RESTORE . '.meta', json_encode(
				array('archive' => basename($full), 'target' => $want, 'at' => gmdate('c'))
			));
			// No --clean: the target was just created, and --clean cannot drop
			// constraints inherited from partitioned parents.
			$ok = $this->spawn(self::PG_RESTORE, array_merge(
				array('--single-transaction', '--no-owner', '--no-privileges'),
				$this->target($want),
				array($full)
			), self::RESTORE);
			return $ok
				? $this->lang('started-restore') . ' ' . $want
				: $this->lang('spawn-failed');
		}

		return '';
	}

	// -------------------------------------------------------------------- download

	/**
	 * Called from page_headers(): response headers are set, nothing is buffered
	 * yet. The only place a plugin can still send an attachment.
	 */
	function headers() {
		// Post/Redirect/Get. This is not cosmetic: the page polls and reloads
		// itself when a job finishes, and reloading a page whose last navigation
		// was a POST re-submits that POST. That turned one click on "Create
		// backup" into a backup every poll interval - 28 archives and 261 MB in
		// 46 seconds - until the disk would have filled. Acting here, before any
		// output, means the browser is always left on a GET.
		if ($_POST && isset($_GET['pgbackup']) && $this->onServerPage() && $this->usable()) {
			$message = $this->act();
			if ($message !== '') {
				Adminer\redirect(Adminer\ME . 'pgbackup=', $message);
				exit; // redirect() exits; never rely on that before a side effect
			}
		}
		if (isset($_GET['pgb_status']) && $this->usable() && $this->getToken()) {
			$this->status();
		}
		$name = (string) ($_GET['pgb_get'] ?? '');
		if ($name === '' || !$this->usable() || !$this->getToken()) {
			return null;
		}
		$full = $this->path($name);
		if ($full === null) {
			return null;
		}
		header('Content-Type: application/octet-stream');
		header('Content-Length: ' . (int) filesize($full));
		header('Content-Disposition: attachment; filename="' . basename($full) . '"');
		header('Cache-Control: no-store');
		while (ob_get_level() > 0) {
			ob_end_clean();
		}
		readfile($full);
		exit;
	}

	/**
	 * Machine-readable job state, so the page can show a backup or restore
	 * finishing without the user reloading to find out. Emitted from headers()
	 * for the same reason as the download: nothing is buffered yet, so the
	 * Content-Type set by page_headers() can still be replaced.
	 */
	private function status(): void {
		$restore = $this->jobState(self::RESTORE);
		$meta = json_decode((string) @file_get_contents(self::RESTORE . '.meta'), true);
		$payload = array(
			'busy' => $this->busy(),
			'restore' => array(
				'state' => $restore['state'],
				'label' => $this->lang('state-' . $restore['state']),
				'log' => $restore['log'],
				'archive' => is_array($meta) ? (string) ($meta['archive'] ?? '') : '',
				'target' => is_array($meta) ? (string) ($meta['target'] ?? '') : '',
			),
			'archives' => array(),
		);
		foreach ($this->backups() as $b) {
			$payload['archives'][] = array(
				'name' => $b['name'],
				'state' => $b['state'],
				'label' => $this->lang('state-' . $b['state']),
				'log' => $b['log'],
				'size' => round($b['size'] / 1048576, 2),
			);
		}
		header('Content-Type: application/json; charset=utf-8');
		header('Cache-Control: no-store');
		while (ob_get_level() > 0) {
			ob_end_clean();
		}
		echo json_encode($payload);
		exit;
	}

	/** Same arithmetic as Adminer's verify_token(), read from the query string. */
	private function getToken(): bool {
		$parts = explode(':', (string) ($_GET['pgb_token'] ?? ''), 2);
		if (count($parts) !== 2 || !isset($_SESSION['token'])) {
			return false;
		}
		return ((int) $parts[1] ^ (int) $_SESSION['token']) === (int) $parts[0];
	}

	private function downloadUrl(string $name): string {
		return Adminer\ME . 'pgbackup=&pgb_get=' . urlencode($name)
			. '&pgb_token=' . urlencode(Adminer\get_token());
	}

	// ---------------------------------------------------------------------- view

	function head($dark = null) {
		if (!$this->usable() || !$this->onServerPage()) {
			return;
		}
		$cfg = json_encode(
			array(
				'label' => $this->lang('page-title'),
				'href' => Adminer\ME . 'pgbackup=',
				'status' => Adminer\ME . 'pgbackup=&pgb_status=1&pgb_token=' . urlencode(Adminer\get_token()),
				'active' => isset($_GET['pgbackup']),
			),
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
		);
		if ($cfg === false) {
			return;
		}
		?>
<script<?php echo Adminer\nonce(); ?>>
(() => {
	const CFG = <?php echo $cfg; ?>;

	const addLink = () => {
		const links = document.querySelector('#content p.links');
		if (!links || links.dataset.pgbLink) {
			return;
		}
		links.dataset.pgbLink = '1';
		const a = document.createElement('a');
		a.href = CFG.href;
		a.textContent = CFG.label;
		if (CFG.active) {
			a.className = 'active';
		}
		links.append(document.createTextNode(' '), a, document.createTextNode('\n'));
	};

	// Poll unconditionally on the backup page: head() runs before the POST has
	// spawned anything, so asking busy() here would always say "idle".
	const poll = (() => {
		let wasBusy = false;
		const paint = (row, label, state, log) => {
			if (!row) {
				return;
			}
			row.textContent = label;
			row.className = 'pgb-' + state;
			const pre = row.closest('tr')?.querySelector('pre');
			if (pre && log) {
				pre.textContent = log;
			}
		};
		const tick = async () => {
			let d;
			try {
				const r = await fetch(CFG.status, { credentials: 'same-origin' });
				d = await r.json();
			} catch (e) {
				setTimeout(tick, 4000); // transient: keep watching, do not reload
				return;
			}
			for (const a of d.archives || []) {
				paint(document.querySelector('[data-pgb-name="' + CSS.escape(a.name) + '"]'), a.label, a.state, a.log);
			}
			const rs = document.querySelector('#pgb-restore-state');
			if (rs && d.restore) {
				paint(rs, d.restore.label, d.restore.state, d.restore.log);
			}
			if (d.busy) {
				wasBusy = true;
				setTimeout(tick, 1500);
			} else if (wasBusy) {
				// replace(), not reload(): reload() would re-send a POST if one
				// is still the current navigation. Belt and braces on top of the
				// redirect in headers().
				location.replace(CFG.href);
			} else {
				setTimeout(tick, 3000);
			}
		};
		return tick;
	})();

	const boot = () => {
		addLink();
		if (CFG.active) {
			poll();
		}
	};

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
</script>
		<?php
	}

	/**
	 * Rendering hook. There is no content hook on the "Select database" page, but
	 * Adminer calls adminer()->databases() there once, immediately below the
	 * Create database / Process list / Variables links - exactly where this
	 * belongs. Returning null lets Adminer's own databases() produce the list, so
	 * the database table still renders underneath.
	 *
	 * databasesPrint() calls this again for the sidebar, from page_footer(), i.e.
	 * after the content - hence the one-shot flag rather than a page test.
	 */
	function databases($flush = true) {
		if ($this->rendered || !isset($_GET['pgbackup']) || !$this->onServerPage() || !$this->usable()) {
			return null;
		}
		$this->rendered = true;
		// The action already ran in headers() and redirected; Adminer prints the
		// flash message from the session itself.
		$this->render('');
		return null;
	}

	private $rendered = false;

	private function render(string $message): void {
		$backups = $this->backups();
		$busy = $this->busy();
		$restore = $this->jobState(self::RESTORE);
		$meta = json_decode((string) @file_get_contents(self::RESTORE . '.meta'), true);
		?>
<style<?php echo Adminer\nonce(); ?>>
.pgb-msg { padding: 6px 10px; border-radius: 4px; background: color-mix(in srgb, var(--accent, #3574f0) 12%, transparent); }
.pgb table { border-collapse: collapse; margin-top: 10px; }
.pgb td, .pgb th { padding: 5px 10px; text-align: left; vertical-align: top; }
.pgb .pgb-ok { color: #2e7d32; font-weight: 600; }
.pgb .pgb-failed { color: #c0392b; font-weight: 600; }
.pgb .pgb-running { color: #b26a00; font-weight: 600; }
.pgb pre { max-width: 60em; overflow-x: auto; margin: 4px 0 0; font-size: 12px; opacity: .85; white-space: pre-wrap; }
.pgb-note { display: block; margin-top: 6px; color: var(--muted, #6b7280); font-size: 12px; line-height: 1.5; }
.pgb-target { width: 17em; }
.pgb-cmd { margin-top: 4px; }
.pgb-cmd > summary { cursor: pointer; color: var(--muted, #6b7280); font-size: 12px; width: fit-content; }
.pgb-cmd > pre { margin: 4px 0 0; padding: 6px 8px; border-radius: 4px;
	background: color-mix(in srgb, currentColor 7%, transparent);
	font-size: 12px; line-height: 1.6; white-space: pre-wrap; word-break: break-all; max-width: 62em; }
</style>
<div class="pgb">
<h3><?php echo Adminer\h($this->lang('page-title')); ?></h3>
<?php if ($message !== '') { ?>
<p class="pgb-msg"><?php echo Adminer\h($message); ?>
<?php } ?>

<form action="" method="post">
<p><label><?php echo Adminer\h($this->lang('source')); ?>
	<select name="pgb_db"><?php foreach ($this->databaseList() as $d) { ?>
		<option value="<?php echo Adminer\h($d); ?>"><?php echo Adminer\h($d); ?></option>
	<?php } ?></select></label>
<input type="submit" name="pgb_create" value="<?php echo Adminer\h($this->lang('create')); ?>"
	<?php echo $busy !== null ? 'disabled' : ''; ?>>
<small class="pgb-note"><?php echo Adminer\h($this->lang('create-note')); ?></small>
<?php echo Adminer\input_token(); ?></form>
<?php $first = $this->databaseList(); if ($first) { ?>
<details class="pgb-cmd"><summary><?php echo Adminer\h($this->lang('cmd-backup')); ?></summary>
<pre><?php echo Adminer\h($this->cmdBackup($first[0])); ?></pre>
<small class="pgb-note"><?php echo Adminer\h($this->lang('cmd-note')); ?></small></details>
<?php } ?>

<?php if ($restore['state'] !== 'none') { ?>
<h4><?php echo Adminer\h($this->lang('last-restore')); ?></h4>
<p><span id="pgb-restore-state" class="pgb-<?php echo Adminer\h($restore['state']); ?>"><?php
	echo Adminer\h($this->lang('state-' . $restore['state'])); ?></span>
	<?php if (is_array($meta)) { ?>
		— <code><?php echo Adminer\h((string) ($meta['archive'] ?? '')); ?></code>
		→ <code><?php echo Adminer\h((string) ($meta['target'] ?? '')); ?></code>
	<?php } ?>
<pre<?php echo $restore['log'] === '' ? ' hidden' : ''; ?>><?php echo Adminer\h($restore['log']); ?></pre>
<?php } ?>

<h4><?php echo Adminer\h($this->lang('archives')); ?></h4>
<?php if (!$backups) { ?>
<p class="message"><?php echo Adminer\h($this->lang('none')); ?>
<?php } else { ?>
<table class="odds">
<thead><tr>
	<th><?php echo Adminer\h($this->lang('col-file')); ?>
	<th><?php echo Adminer\h($this->lang('col-when')); ?>
	<th><?php echo Adminer\h($this->lang('col-size')); ?>
	<th><?php echo Adminer\h($this->lang('col-state')); ?>
	<th><?php echo Adminer\h($this->lang('col-actions')); ?>
<tbody>
<?php foreach ($backups as $b) { ?>
<tr>
	<td><code><?php echo Adminer\h($b['name']); ?></code>
		<pre<?php echo $b['log'] === '' ? ' hidden' : ''; ?>><?php echo Adminer\h($b['log']); ?></pre>
	<td><?php echo Adminer\h(gmdate('Y-m-d H:i:s', $b['time'])); ?> UTC
	<td><?php echo Adminer\h(number_format($b['size'] / 1048576, 2)); ?> MB
	<td data-pgb-name="<?php echo Adminer\h($b['name']); ?>" class="pgb-<?php echo Adminer\h($b['state']); ?>"><?php
		echo Adminer\h($this->lang('state-' . $b['state'])); ?>
	<td>
		<?php if ($b['state'] === 'ok') { ?>
		<a href="<?php echo Adminer\h($this->downloadUrl($b['name'])); ?>"><?php echo Adminer\h($this->lang('download')); ?></a>
		<?php } ?>
		<form action="" method="post">
		<input type="hidden" name="pgb_file" value="<?php echo Adminer\h($b['name']); ?>">
		<input name="pgb_target" class="pgb-target" autocapitalize="off" autocomplete="off"
			value="<?php echo Adminer\h($this->sourceDb($b['name'])); ?>">
		<input type="submit" name="pgb_restore" value="<?php echo Adminer\h($this->lang('restore')); ?>"
			<?php echo $busy !== null || $b['state'] !== 'ok' ? 'disabled' : ''; ?>>
		<input type="submit" name="pgb_delete" value="<?php echo Adminer\h($this->lang('delete')); ?>"
			<?php echo $busy !== null ? 'disabled' : ''; ?>>
		<?php echo Adminer\input_token(); ?></form>
		<details class="pgb-cmd"><summary><?php echo Adminer\h($this->lang('cmd-restore')); ?></summary>
		<pre><?php echo Adminer\h($this->cmdRestore($b['name'], $this->sourceDb($b['name']))); ?></pre>
		<small class="pgb-note"><?php echo Adminer\h($this->lang('cmd-copy')); ?>
			<code>docker cp adminer:/backups/<?php echo Adminer\h($b['name']); ?> .</code></small></details>
<?php } ?>
</table>
<?php } ?>

<p><small class="pgb-note"><?php echo Adminer\h($this->lang('restore-note')); ?></small>
<p><small class="pgb-note"><?php echo Adminer\h($this->lang('rename-note')); ?>
	<a href="<?php echo Adminer\h(Adminer\ME); ?>database="><?php echo Adminer\h($this->lang('rename-link')); ?></a></small>
<?php if ($busy !== null) { ?>
<p><small class="pgb-note"><?php echo Adminer\h($this->lang('reload-note')); ?></small>
<?php } ?>
</div>
		<?php
	}

	protected $translations = array(
		'en' => array(
			'' => 'Backup and restore with pg_dump / pg_restore',
			'page-title' => 'Backups',
			'archives' => 'Archives',
			'source' => 'Database:',
			'bad-source' => 'Refused: that is not a database on this connection.',
			'no-space' => 'Refused: less than 1 GiB free on the backup volume.',
			'pruned' => 'older archives removed:',
			'last-restore' => 'Last restore',
			'create' => 'Create backup now',
			'create-note' => 'pg_dump --format=custom --compress=6. Keeps owners, grants, sequence values, extensions and partition attachment - everything the SQL export drops.',
			'none' => 'No backups yet.',
			'col-file' => 'File', 'col-when' => 'Created', 'col-size' => 'Size',
			'col-state' => 'State', 'col-actions' => 'Restore into a new database',
			'state-ok' => 'ok', 'state-failed' => 'failed', 'state-running' => 'running...', 'state-none' => '-',
			'restore' => 'Restore', 'delete' => 'Delete', 'download' => 'Download',
			'cmd-backup' => 'Command',
			'cmd-restore' => 'Command',
			'cmd-note' => 'Runs inside the adminer container. PGPASSWORD comes from your Adminer session; export it yourself when running this by hand.',
			'cmd-copy' => 'The archive is inside the container - copy it out first:',
			'started-dump' => 'Backup started.',
			'started-restore' => 'Restore started into the new database:',
			'deleted' => 'Backup deleted.',
			'busy' => 'Another job is still running - wait for it to finish.',
			'bad-name' => 'Refused: that is not a backup file in the backup directory.',
			'bad-target' => 'Refused: the target name is not a plain identifier (letter or underscore, then letters, digits, underscores).',
			'target-exists' => 'Refused: that database already exists. Restore only ever creates a new one, so nothing can be overwritten - drop it first with Drop (force) below, or restore under a different name.',
			'create-failed' => 'Could not create the target database - see the PHP error log.',
			'bad-token' => 'Invalid token - the request was not accepted.',
			'spawn-failed' => 'Could not start the job. Is the session password still available?',
			'restore-note' => 'Restore always creates the database you name and loads into that, with pg_restore --single-transaction --no-owner --no-privileges. Nothing existing is touched, so a failed restore cannot lose data. --clean is deliberately not used: it cannot drop constraints inherited from partitioned parents.',
			'rename-note' => 'To put a restored copy in place of the original, rename them on Adminer\'s own database page:',
			'rename-link' => 'Alter database',
			'reload-note' => 'A job is running. Reload this page to see the result.',
		),
		'vi' => array(
			'' => 'Backup và restore bằng pg_dump / pg_restore',
			'page-title' => 'Backup',
			'archives' => 'Các bản backup',
			'source' => 'Database:',
			'bad-source' => 'Từ chối: đây không phải database trên connection này.',
			'no-space' => 'Từ chối: volume backup còn dưới 1 GiB trống.',
			'pruned' => 'đã xoá bản backup cũ:',
			'last-restore' => 'Lần restore gần nhất',
			'create' => 'Tạo backup ngay',
			'create-note' => 'pg_dump --format=custom --compress=6. Giữ owner, quyền, giá trị sequence, extension và attachment của partition - toàn bộ những thứ bản export SQL làm mất.',
			'none' => 'Chưa có backup nào.',
			'col-file' => 'File', 'col-when' => 'Tạo lúc', 'col-size' => 'Kích thước',
			'col-state' => 'Trạng thái', 'col-actions' => 'Restore vào database mới',
			'state-ok' => 'ok', 'state-failed' => 'thất bại', 'state-running' => 'đang chạy...', 'state-none' => '-',
			'restore' => 'Restore', 'delete' => 'Xoá', 'download' => 'Tải về',
			'cmd-backup' => 'Câu lệnh',
			'cmd-restore' => 'Câu lệnh',
			'cmd-note' => 'Chạy trong container adminer. PGPASSWORD lấy từ session Adminer; chạy tay thì bạn tự export nó.',
			'cmd-copy' => 'File nằm trong container - copy ra trước:',
			'started-dump' => 'Đã bắt đầu backup.',
			'started-restore' => 'Đã bắt đầu restore vào database mới:',
			'deleted' => 'Đã xoá backup.',
			'busy' => 'Còn một job đang chạy - đợi nó xong đã.',
			'bad-name' => 'Từ chối: đây không phải file backup trong thư mục backup.',
			'bad-target' => 'Từ chối: tên database không phải identifier thường (chữ hoặc gạch dưới, rồi chữ/số/gạch dưới).',
			'target-exists' => 'Từ chối: database đó đã tồn tại. Restore chỉ tạo database mới nên không thể ghi đè - xoá nó trước bằng Drop (force) ở dưới, hoặc restore với tên khác.',
			'create-failed' => 'Không tạo được database đích - xem PHP error log.',
			'bad-token' => 'Token không hợp lệ - request bị từ chối.',
			'spawn-failed' => 'Không khởi động được job. Mật khẩu trong session còn không?',
			'restore-note' => 'Restore luôn tạo database bạn đặt tên rồi nạp vào đó, bằng pg_restore --single-transaction --no-owner --no-privileges. Không chạm vào gì đang có, nên restore fail cũng không mất dữ liệu. Cố ý KHÔNG dùng --clean: nó không drop được constraint kế thừa từ bảng partition cha.',
			'rename-note' => 'Muốn đưa bản restore vào thay bản gốc thì đổi tên ở trang database của Adminer:',
			'rename-link' => 'Alter database',
			'reload-note' => 'Có job đang chạy. Tải lại trang này để xem kết quả.',
		),
	);
}

return new AdminerPgBackup();
