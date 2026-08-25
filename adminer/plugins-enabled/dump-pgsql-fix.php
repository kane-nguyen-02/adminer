<?php

/**
 * Stop Adminer's PostgreSQL export from producing a dump that cannot be loaded.
 *
 * Two defects, both triggered by the "Database" select on the export page:
 *
 * 1. The dump header is unusable. adminer.php's pgsql driver builds it as
 *
 *        function use_sql($db, $db_style) {
 *            if (preg_match('~CREATE~', $db_style)) {
 *                if ($db_style == "DROP+CREATE") $I = "DROP DATABASE IF EXISTS $C;\n";
 *                $I .= "CREATE DATABASE $C;\n";
 *            }
 *            return "$I\\connect $C";
 *        }
 *
 *    Every non-blank option ends in `\connect`, a psql meta-command, not SQL -
 *    the server answers `syntax error at or near "\"`. DROP+CREATE additionally
 *    fails with `cannot drop the currently open database` (the import runs
 *    through the PHP connection already inside that database) and `being
 *    accessed by other users` (Adminer cannot terminate backends). All three
 *    options are dead ends on PostgreSQL; only the blank one is loadable.
 *
 * 2. Choosing DROP+CREATE also suppresses the `DROP TYPE IF EXISTS` guards in
 *    front of every `CREATE TYPE` (the `$vk != 'DROP+CREATE'` ternary in the
 *    export loop). Adminer assumes the database was recreated from scratch;
 *    since defect 1 means it never was, the restore fails with
 *    `type "ATTENDANCE_SOURCE" already exists` on every enum.
 *
 * The trap is sticky: the choice is persisted in the `adminer_export` settings
 * cookie, so once picked it silently poisons every later export.
 *
 * So, PostgreSQL only:
 *
 *   - Force `db_style` blank, in the form (only the blank option is left, so the
 *     bad value cannot be re-saved) and server-side in dumpHeaders(), which runs
 *     before the export loop reads $_POST.
 *   - Tick the "types" box when it is off. Adminer's factory defaults leave it
 *     unchecked, and CREATE TABLEs referencing enum types that were never
 *     dumped cannot be restored at all.
 *
 * Nothing here makes Adminer's SQL dump a backup, and it must not be mistaken
 * for one. Measured against the f3s schema, a full Adminer dump restores with
 * 149 errors: boolean columns are dumped as 0/1 (`column "x" is of type boolean
 * but expression is of type integer`, 21 INSERT statements lost outright), enum
 * column defaults are emitted unquoted (`DEFAULT FACELOG` - a column reference),
 * partition children are dumped as standalone tables and never re-attached
 * (`PARTITION BY` 5, `ATTACH PARTITION` 0), foreign keys on partitioned parents
 * use the invalid `ALTER TABLE ONLY`, and `CREATE EXTENSION` is never emitted so
 * a `gin_trgm_ops` index has no operator class. Owners, grants and sequence
 * values are absent too. Use pg_dump/pg_restore for backups.
 *
 * HISTORY - do not re-add a schema reset here. An earlier version of this plugin
 * offered a checkbox that prepended `DROP SCHEMA <ns> CASCADE; CREATE SCHEMA
 * <ns>;` to the dump. Adminer imports statement-by-statement with autocommit and
 * no surrounding transaction, so when the import later hit a fatal PHP error the
 * drop had already committed and the restore had not: it emptied the live f3s
 * database. Destructive statements must never be handed to a runner that cannot
 * roll them back. `pg_restore --clean --if-exists --single-transaction` is the
 * correct way to get the same effect atomically.
 *
 * Guarded like structure-enum.php: head() also runs where no connection exists,
 * so DRIVER is checked with defined() - a fatal here would take the whole console
 * down instead of costing one feature.
 */
final class AdminerDumpPgsqlFix extends Adminer\Plugin {
	private function isPgsql(): bool {
		return defined('Adminer\DRIVER') && Adminer\DRIVER === 'pgsql';
	}

	/**
	 * Runs inside the export request, before the loop reads $_POST["db_style"],
	 * and only there. Returning null lets Adminer's own dumpHeaders() take over
	 * and produce the format string (Plugins::__call - first non-null wins).
	 */
	function dumpHeaders($identifier, $multi_table = false) {
		if ($this->isPgsql()) {
			$_POST['db_style'] = '';
		}
		return null;
	}

	function head($dark = null) {
		if (!isset($_GET['dump']) || !$this->isPgsql()) {
			return;
		}
		$note = json_encode($this->lang('db-note'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
		if ($note === false) {
			return;
		}
		?>
<style<?php echo Adminer\nonce(); ?>>
.pgfix-note {
	display: block;
	margin-top: 4px;
	color: var(--muted, #6b7280);
	font-size: 12px;
	line-height: 1.5;
}
</style>
<script<?php echo Adminer\nonce(); ?>>
(() => {
	const NOTE = <?php echo $note; ?>;

	const boot = () => {
		const select = document.querySelector('select[name=db_style]');
		if (!select || select.dataset.pgfix) {
			return;
		}
		select.dataset.pgfix = '1';

		// Leave only the blank option: USE / CREATE / DROP+CREATE all emit
		// `\connect`, and stripping them stops the bad value being re-saved
		// into the adminer_export settings cookie.
		for (const option of [...select.options]) {
			if (option.value !== '') {
				option.remove();
			}
		}
		select.value = '';

		// A dump missing its enum/domain types cannot be restored, and
		// Adminer's factory default leaves this off.
		const types = document.querySelector('input[name=types]');
		if (types && !types.checked) {
			types.checked = true;
		}

		const cell = select.closest('td');
		if (cell) {
			const note = document.createElement('small');
			note.className = 'pgfix-note';
			note.textContent = NOTE;
			cell.append(note);
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

	protected $translations = array(
		'en' => array(
			'' => 'Keep the PostgreSQL SQL dump loadable',
			'db-note' => 'PostgreSQL: DROP+CREATE / CREATE / USE all emit a psql-only \\connect line and disable the DROP TYPE guards, producing a dump that cannot be imported. Locked to blank; types are exported. This is an export of table structure and rows - not a backup. Use pg_dump/pg_restore for that.',
		),
		'vi' => array(
			'' => 'Giữ cho file dump SQL của PostgreSQL còn nạp được',
			'db-note' => 'PostgreSQL: DROP+CREATE / CREATE / USE đều sinh dòng \\connect (chỉ psql hiểu) và tắt luôn phần DROP TYPE, tạo ra file dump không import lại được. Đã khoá về rỗng; các kiểu dữ liệu được export kèm. Đây là bản export cấu trúc bảng và dữ liệu - KHÔNG phải backup. Muốn backup thì dùng pg_dump/pg_restore.',
		),
	);
}

return new AdminerDumpPgsqlFix();
