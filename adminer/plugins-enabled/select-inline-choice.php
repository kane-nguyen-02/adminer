<?php

/**
 * Ctrl+click inline editing: give enum and boolean cells a dropdown.
 *
 * Adminer's inline editor (selectClick) turns a Ctrl+clicked result cell into a
 * plain <input>/<textarea> named after the cell id. Typing the exact spelling
 * of an enum label, or 1/0/t/f for a boolean, is error-prone. This swaps that
 * editor for a <select> of the real choices:
 *
 *   - enum    -> every label of that enum type
 *   - boolean -> true / false
 *
 * A nullable column also gets a "(NULL)" option. That is safe because Adminer's
 * inline save stores an empty value as NULL for any non char/text column
 * (see the `$X != "" ? processInput : "NULL"` branch in the core), so an empty
 * <option> is exactly how you clear an enum/bool inline. A NOT NULL column omits
 * it, so a boolean there shows only true / false, as requested.
 *
 * Types come from the catalog (PostgreSQL only) - two small queries, no user
 * data. The swap itself is a MutationObserver, the same tactic select-json-edit
 * uses, so it rides on top of Adminer's own editor instead of racing its click
 * handler. The <select> keeps the editor's name (the cell id), so Save picks it
 * up unchanged.
 */
final class AdminerSelectInlineChoice extends Adminer\Plugin {
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

	/**
	 * @return array<string,array{kind:string,values:list<string>,nullable:bool}>
	 */
	private function columnMeta(): array {
		if (!$this->connected() || Adminer\DRIVER !== 'pgsql') {
			return array();
		}
		$table = isset($_GET['select']) ? (string) $_GET['select'] : '';
		if ($table === '') {
			return array();
		}
		$schema = Adminer\q($this->schema());
		$rel = Adminer\q($table);

		try {
			$conn = Adminer\connection();
			$res = $conn->query("
				SELECT a.attname, t.typname, t.typtype, a.attnotnull, a.attgenerated
				FROM pg_attribute a
				JOIN pg_class c ON c.oid = a.attrelid
				JOIN pg_namespace n ON n.oid = c.relnamespace
				JOIN pg_type t ON t.oid = a.atttypid
				WHERE n.nspname = $schema AND c.relname = $rel
					AND a.attnum > 0 AND NOT a.attisdropped
			");
			if (!$res) {
				return array();
			}
			$cols = array();
			$needEnum = false;
			while ($row = $res->fetch_row()) {
				// A generated column can never be written, so offering a dropdown
				// on it would only produce edits that silently fail to save.
				if ((string) $row[4] !== '') {
					continue;
				}
				$notnull = ($row[3] === true || $row[3] === 't' || $row[3] === '1');
				$cols[(string) $row[0]] = array(
					'typname' => (string) $row[1],
					'typtype' => (string) $row[2],
					'nullable' => !$notnull,
				);
				if ($row[2] === 'e') {
					$needEnum = true;
				}
			}
		} catch (\Throwable $e) {
			return array();
		}

		$labels = array();
		if ($needEnum) {
			try {
				$res2 = $conn->query("
					SELECT t.typname, e.enumlabel
					FROM pg_type t
					JOIN pg_enum e ON e.enumtypid = t.oid
					JOIN pg_namespace n ON n.oid = t.typnamespace
					WHERE n.nspname = $schema
					ORDER BY t.typname, e.enumsortorder
				");
				if ($res2) {
					while ($row = $res2->fetch_row()) {
						$labels[(string) $row[0]][] = (string) $row[1];
					}
				}
			} catch (\Throwable $e) {
				// no enum labels: those columns simply fall through to plain text.
			}
		}

		$out = array();
		foreach ($cols as $name => $c) {
			if ($c['typtype'] === 'e' && isset($labels[$c['typname']])) {
				$out[$name] = array('kind' => 'enum', 'values' => $labels[$c['typname']], 'nullable' => $c['nullable']);
			} elseif (preg_match('~bool~i', $c['typname'])) {
				$out[$name] = array('kind' => 'bool', 'values' => array('true', 'false'), 'nullable' => $c['nullable']);
			}
		}
		return $out;
	}

	function head($dark = null) {
		if (!isset($_GET['select'])) {
			return;
		}
		$cols = $this->columnMeta();
		if (!$cols) {
			return;                              // non-pgsql or no enum/bool columns
		}
		$json = json_encode($cols, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
		?>
<style<?php echo Adminer\nonce(); ?>>
#table td[id^="val["] > select.sic-select {
	font: inherit;
	max-width: 100%;
	padding: 2px 4px;
	color: var(--fg, inherit);
	background: var(--bg, #fff);
	border: 1px solid var(--accent, #3574f0);
	border-radius: var(--radius-sm, 4px);
}
</style>
<script<?php echo Adminer\nonce(); ?>>
(() => {
	const COLS = <?php echo $json; ?>;

	const boot = () => {
		const grid = document.getElementById('table');
		if (!grid) {
			return;
		}

		const columnOf = (td) => {
			const m = td.id.match(/\[([^\[\]]+)\]$/);
			return m ? m[1] : '';
		};

		const enhance = (ed) => {
			if (ed.dataset.sicDone) {
				return;
			}
			// Only Adminer's own text editor, never a checkbox / search box.
			const isText = ed.tagName === 'TEXTAREA'
				|| (ed.tagName === 'INPUT' && (ed.type === 'text' || ed.type === ''));
			if (!isText) {
				return;
			}
			const td = ed.closest('td[id^="val["]');
			if (!td) {
				return;
			}
			const meta = COLS[columnOf(td)];
			if (!meta) {
				return;                          // not an enum / bool column
			}
			ed.dataset.sicDone = '1';

			const cur = (ed.value || '').trim();
			const sel = document.createElement('select');
			sel.className = 'sic-select';
			sel.name = ed.name || td.id;         // Save reads the field by this name

			const add = (value, label) => {
				const o = document.createElement('option');
				o.value = value;
				o.textContent = label;
				sel.append(o);
			};

			if (meta.nullable) {
				add('', '(NULL)');               // empty inline value -> stored as NULL
			}
			for (const v of meta.values) {
				add(v, v);
			}
			// Keep an unexpected current value selectable rather than silently
			// rewriting it (data that predates an enum change, say).
			if (cur !== '' && !meta.values.includes(cur)) {
				add(cur, cur + '  (current)');
			}

			sel.value = cur;

			// Make the dropdown at least as wide as the cell it replaces, the
			// way Adminer sizes its own inline input, so it does not shrink to
			// the width of the shortest option. Measured before replacing, while
			// the cell still has its editing width.
			const style = window.getComputedStyle(td);
			const width = td.clientWidth
				- parseFloat(style.paddingLeft || '0')
				- parseFloat(style.paddingRight || '0');
			if (width > 0) {
				sel.style.minWidth = width + 'px';
			}

			// Save the moment a value is picked, instead of relying on the
			// separate "Save" button (easy to miss - the reported symptom was
			// "chọn xong nhưng không lưu"). Submitting the results form the cell
			// lives in triggers Adminer's normal inline save: it POSTs
			// val[<where>][col]=<value> + token, which updates exactly this row.
			// A change only fires on a real new choice, so reselecting the same
			// value is a no-op and never navigates.
			sel.addEventListener('change', () => {
				const form = sel.closest('form');
				if (!form) {
					return;
				}
				if (typeof form.requestSubmit === 'function') {
					form.requestSubmit();
				} else {
					form.submit();
				}
			});
			// Escape cancels the edit without saving, matching Adminer's editor.
			sel.addEventListener('keydown', (e) => {
				if (e.key === 'Escape') {
					e.preventDefault();
					e.stopPropagation();
					sel.blur();
				}
			});

			ed.replaceWith(sel);
			sel.focus();
		};

		const scan = (node) => {
			if (node.nodeType !== 1) {
				return;
			}
			if (node.matches && node.matches('input, textarea')) {
				enhance(node);
			}
			if (node.querySelectorAll) {
				for (const ed of node.querySelectorAll('input, textarea')) {
					enhance(ed);
				}
			}
		};

		new MutationObserver((records) => {
			for (const rec of records) {
				for (const node of rec.addedNodes) {
					scan(node);
				}
			}
		}).observe(grid, { childList: true, subtree: true });
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
		'en' => array('' => 'Inline-edit enum and boolean cells with a dropdown of valid values'),
		'vi' => array('' => 'Sửa nhanh ô enum/boolean bằng dropdown các giá trị hợp lệ'),
	);
}

return new AdminerSelectInlineChoice();
