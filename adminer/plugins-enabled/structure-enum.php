<?php

/**
 * Show the allowed values of a PostgreSQL enum type on the structure page.
 *
 * A named enum column renders as just the type name:
 *
 *     leaveType    "HRM_LEAVE_TYPE"   [ANNUAL]
 *
 * which says nothing about what may go in it - you had to look the type up
 * separately. This appends the labels inline:
 *
 *     leaveType    "HRM_LEAVE_TYPE"   ANNUAL · SICK · UNPAID   [ANNUAL]
 *
 * PostgreSQL only, and read from the catalog (pg_type / pg_enum), so it costs
 * one small query and touches no user data.
 *
 * Every DB call is guarded the way select-smart-filter.php is: head() also runs
 * on the login page, where $_GET['table'] survives but no connection exists,
 * and an unguarded query there takes the whole console down with a fatal
 * instead of costing one feature.
 */
final class AdminerStructureEnum extends Adminer\Plugin {
	private function connected(): bool {
		if (!isset($_GET['username'])) {
			return false;
		}
		try {
			return (bool) Adminer\connection();
		} catch (\Throwable $e) {
			return false;
		}
	}

	private function schema(): string {
		$ns = isset($_GET['ns']) ? (string) $_GET['ns'] : '';
		return $ns !== '' ? $ns : 'public';
	}

	/** @return array<string, list<string>> typname => ordered labels */
	private function enumTypes(): array {
		if (!$this->connected() || Adminer\DRIVER !== 'pgsql') {
			return array();
		}
		$sql = "
			SELECT t.typname, e.enumlabel
			FROM pg_type t
			JOIN pg_enum e ON e.enumtypid = t.oid
			JOIN pg_namespace n ON n.oid = t.typnamespace
			WHERE n.nspname = " . Adminer\q($this->schema()) . "
			ORDER BY t.typname, e.enumsortorder
		";
		try {
			$result = Adminer\connection()->query($sql);
			if (!$result) {
				return array();
			}
			$out = array();
			while ($row = $result->fetch_row()) {
				$out[(string) $row[0]][] = (string) $row[1];
			}
			return $out;
		} catch (\Throwable $e) {
			error_log('structure-enum: enum lookup failed: ' . $e->getMessage());
			return array();
		}
	}

	function head($dark = null) {
		if (!isset($_GET['table'])) {
			return;
		}
		$types = $this->enumTypes();
		if (!$types) {
			return;
		}
		$json = json_encode($types, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
		if ($json === false) {
			return;
		}
		?>
<style<?php echo Adminer\nonce(); ?>>
.se-enum {
	display: inline-flex;
	flex-wrap: wrap;
	gap: 3px 4px;
	margin-left: 6px;
	vertical-align: baseline;
}
.se-enum-val {
	padding: 1px 6px;
	border: 1px solid color-mix(in srgb, var(--accent, #3574f0) 35%, transparent);
	border-radius: 999px;
	background: color-mix(in srgb, var(--accent, #3574f0) 12%, transparent);
	color: var(--fg, inherit);
	font: 11px/1.5 var(--mono-stack, ui-monospace, monospace);
	white-space: nowrap;
}
.se-enum-more {
	padding: 1px 4px;
	color: var(--muted, #6b7280);
	font: 11px/1.5 var(--mono-stack, ui-monospace, monospace);
	cursor: help;
}
</style>
<script<?php echo Adminer\nonce(); ?>>
(() => {
	const ENUMS = <?php echo $json; ?>;
	const MAX_INLINE = 8;      // beyond this, summarise and keep the rest in the tooltip

	const boot = () => {
		const table = document.querySelector('#content table.nowrap.odds')
			|| document.querySelector('#content table');
		if (!table) {
			return;
		}
		for (const span of table.querySelectorAll('tbody tr td > span')) {
			if (span.dataset.seDone) {
				continue;
			}
			// Adminer prints a named type quoted: "HRM_LEAVE_TYPE"
			const raw = (span.textContent || '').trim().replace(/^"(.*)"$/, '$1');
			const values = ENUMS[raw];
			if (!values || !values.length) {
				continue;
			}
			span.dataset.seDone = '1';
			const wrap = document.createElement('span');
			wrap.className = 'se-enum';
			wrap.title = raw + ': ' + values.join(', ');
			for (const v of values.slice(0, MAX_INLINE)) {
				const chip = document.createElement('span');
				chip.className = 'se-enum-val';
				chip.textContent = v;
				wrap.append(chip);
			}
			if (values.length > MAX_INLINE) {
				const more = document.createElement('span');
				more.className = 'se-enum-more';
				more.textContent = '+' + (values.length - MAX_INLINE);
				wrap.append(more);
			}
			span.after(wrap);
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
		'en' => array('' => 'Show enum type values on the structure page'),
		'vi' => array('' => 'Hiện các giá trị của kiểu enum ở trang cấu trúc'),
	);
}

return new AdminerStructureEnum();
