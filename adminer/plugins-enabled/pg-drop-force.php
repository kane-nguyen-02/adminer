<?php

/**
 * "Drop (force)" on the database list: terminate the other sessions, then drop.
 *
 * Adminer's own Drop runs a bare `DROP DATABASE`, which PostgreSQL refuses while
 * anything else is connected:
 *
 *     ERROR: database "f3s" is being accessed by other users
 *     DETAIL: There are 2 other sessions using the database.
 *
 * In a dev setup those sessions are usually your own application, a second
 * Adminer tab or pgAdmin, and there is no way to close them from here - so the
 * button is unusable exactly when you need it.
 *
 * Why this cannot just be another submit in the same form: Adminer's dispatcher
 * fires on the presence of the field, not on which button was pressed -
 *
 *     if($_POST["db"] && !$l) queries_redirect(substr(ME,0,-1), lang(132), drop_databases($_POST["db"]));
 *
 * so any extra submit that still carried `db[]` would run the plain drop first
 * and fail before this plugin saw anything. The click handler therefore copies
 * the checked names into `pgbf_db[]` and clears the `db[]` checkboxes, which
 * leaves Adminer's branch untaken.
 *
 * The work then happens in headers(), called from page_headers() after the
 * response headers are set but before any body output - late enough that auth
 * and the connection are established, early enough to still send a redirect.
 *
 * This is irreversible and it kills other people's connections, so all four
 * guards are deliberate:
 *
 *   - `verify_token()`, using the token Adminer already puts in that form.
 *   - System databases (`postgres`, `template0`, `template1`) are refused, on
 *     the client for feedback and again on the server because the client cannot
 *     be trusted.
 *   - A confirm() listing the exact names, so a mis-click on the wrong row is
 *     visible before anything happens.
 *   - Names are intersected with what the server itself reports and only used
 *     after idf_escape(), so a forged POST cannot name something that is not a
 *     database on this connection.
 *
 * `WITH (FORCE)` (PostgreSQL 13+) does the terminate-and-drop atomically. The
 * explicit pg_terminate_backend() beforehand is kept for older servers, where
 * the drop would otherwise race a reconnecting client.
 */
final class AdminerPgDropForce extends Adminer\Plugin {
	/** Never droppable, whatever the client says. */
	private const PROTECTED_DBS = array('postgres', 'template0', 'template1');

	private function usable(): bool {
		return defined('Adminer\DRIVER')
			&& Adminer\DRIVER === 'pgsql'
			&& defined('Adminer\DB') && Adminer\DB === '' // the database list page only
			&& isset($_GET['username']);
	}

	/**
	 * Called from page_headers(): headers are set, nothing is buffered yet, so a
	 * redirect still works. Adminer's own drop branch was skipped because the
	 * click handler cleared `db[]`.
	 */
	function headers() {
		$names = $_POST['pgbf_db'] ?? null;
		if (!is_array($names) || !$names || !$this->usable()) {
			return null;
		}
		if (!Adminer\verify_token()) {
			return null; // let the page render Adminer's own invalid-token error
		}

		$known = array();
		try {
			$known = Adminer\get_vals('SELECT datname FROM pg_database WHERE NOT datistemplate');
		} catch (\Throwable $e) {
			error_log('pg-drop-force: pg_database lookup failed: ' . $e->getMessage());
			return null;
		}
		$targets = array_values(array_intersect(
			array_map('strval', $names),
			array_diff($known, self::PROTECTED_DBS)
		));
		if (!$targets) {
			$this->done($this->lang('nothing'));
		}

		$force = Adminer\min_version(13);
		$dropped = array();
		$failed = array();
		foreach ($targets as $name) {
			try {
				// Kill first: on servers without WITH (FORCE) the drop would
				// otherwise lose a race against a reconnecting client.
				Adminer\connection()->query(
					'SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '
					. Adminer\q($name) . ' AND pid <> pg_backend_pid()'
				);
				$ok = (bool) Adminer\connection()->query(
					'DROP DATABASE ' . Adminer\idf_escape($name) . ($force ? ' WITH (FORCE)' : '')
				);
			} catch (\Throwable $e) {
				$ok = false;
				Adminer\connection()->error = $e->getMessage();
			}
			if ($ok) {
				$dropped[] = $name;
			} else {
				$failed[] = $name . ': ' . trim((string) Adminer\connection()->error);
			}
		}

		$message = ($dropped ? $this->lang('dropped') . ' ' . implode(', ', $dropped) : '')
			. ($failed ? ($dropped ? ' | ' : '') . $this->lang('failed') . ' ' . implode('; ', $failed) : '');
		$this->done($message);
	}

	/** Clear Adminer's cached database list, then redirect back with a message. */
	private function done(string $message): void {
		Adminer\restart_session();
		Adminer\set_session('dbs', null);
		Adminer\stop_session();
		Adminer\redirect(substr(Adminer\ME, 0, -1), $message);
		exit; // redirect() exits, but do not rely on that on a destructive path
	}

	function head($dark = null) {
		if (!$this->usable()) {
			return;
		}
		$payload = json_encode(
			array(
				'label' => $this->lang('button'),
				'confirm' => $this->lang('confirm'),
				'pick' => $this->lang('pick'),
				'protectedMsg' => $this->lang('protected'),
				'protected' => self::PROTECTED_DBS,
			),
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
		);
		if ($payload === false) {
			return;
		}
		?>
<script<?php echo Adminer\nonce(); ?>>
(() => {
	const CFG = <?php echo $payload; ?>;

	const boot = () => {
		const boxes = [...document.querySelectorAll('input[type=checkbox][name="db[]"]')];
		if (!boxes.length) {
			return;
		}
		const form = boxes[0].form;
		if (!form) {
			return;
		}
		// Adminer's own Drop lives in the "Selected" fieldset; sit next to it.
		const plain = form.querySelector('input[type=submit][name=drop]')
			|| [...form.querySelectorAll('input[type=submit]')].pop();
		if (!plain || !plain.parentNode || plain.dataset.pgbfDone) {
			return;
		}
		plain.dataset.pgbfDone = '1';

		const button = document.createElement('input');
		button.type = 'submit';
		button.value = CFG.label;
		button.name = 'pgbf_go';
		button.style.marginLeft = '6px';
		button.addEventListener('click', (event) => {
			event.preventDefault();
			const picked = boxes.filter((b) => b.checked).map((b) => b.value);
			if (!picked.length) {
				alert(CFG.pick);
				return;
			}
			const blocked = picked.filter((n) => CFG.protected.includes(n));
			if (blocked.length) {
				alert(CFG.protectedMsg + ' ' + blocked.join(', '));
				return;
			}
			if (!confirm(CFG.confirm + '\n\n' + picked.join('\n'))) {
				return;
			}
			// Carry the names under our own field and clear db[], so Adminer's
			// `if($_POST["db"])` branch - the plain drop - is never entered.
			for (const name of picked) {
				const hidden = document.createElement('input');
				hidden.type = 'hidden';
				hidden.name = 'pgbf_db[]';
				hidden.value = name;
				form.append(hidden);
			}
			for (const b of boxes) {
				b.checked = false;
				b.disabled = true;
			}
			form.submit();
		});
		plain.after(button);
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

	protected $translations = array(
		'en' => array(
			'' => 'Drop databases even while other sessions are connected',
			'button' => 'Drop (force)',
			'confirm' => 'Terminate all other sessions and permanently drop these databases? This cannot be undone.',
			'pick' => 'Select at least one database first.',
			'protected' => 'Refused, these are system databases:',
			'nothing' => 'Nothing was dropped: no matching database on this connection.',
			'dropped' => 'Dropped:',
			'failed' => 'Failed:',
		),
		'vi' => array(
			'' => 'Xoá database ngay cả khi còn session khác đang kết nối',
			'button' => 'Drop (force)',
			'confirm' => 'Ngắt toàn bộ session khác và xoá vĩnh viễn các database này? Không thể hoàn tác.',
			'pick' => 'Chọn ít nhất một database trước đã.',
			'protected' => 'Từ chối, đây là database hệ thống:',
			'nothing' => 'Không xoá gì: không có database nào khớp trên connection này.',
			'dropped' => 'Đã xoá:',
			'failed' => 'Thất bại:',
		),
	);
}

return new AdminerPgDropForce();
