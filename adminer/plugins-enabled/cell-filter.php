<?php

/**
 * Right-click a result cell → filter that column (DBeaver-style).
 *
 * Only hooks contextmenu on select-page value cells (td[id^="val["]).
 * Shift+right-click keeps the browser menu.
 *
 * Text/FK cells often contain <a>; focusing the menu input used to scroll
 * the page and our scroll listener closed the menu immediately — that is
 * why number cells felt fine and UUID/text cells felt flaky.
 */
final class AdminerCellFilter extends Adminer\Plugin {
	/**
	 * Guarded like select-smart-filter/structure-enum: head() also runs on the
	 * login page where $_GET survives but no connection exists, and an
	 * unguarded query there fatals the whole console.
	 */
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
	 * Per-column type info for the current Select table, so the menu can offer
	 * type-aware suggestions (enum siblings, numeric/date comparisons, booleans)
	 * instead of only the clicked value.
	 *
	 * PostgreSQL only, straight from the catalog - two small queries, no user
	 * data. Other drivers get the generic menu unchanged (empty map).
	 *
	 * @return array<string,array{kind:string,type:string,values:list<string>}>
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
				SELECT a.attname, t.typname, t.typtype
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
				$cols[(string) $row[0]] = array('typname' => (string) $row[1], 'typtype' => (string) $row[2]);
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
				// Enum siblings are a bonus; fall through with what we have.
			}
		}

		$numeric = array('int2', 'int4', 'int8', 'float4', 'float8', 'numeric', 'decimal', 'real', 'money');
		$temporal = array('date', 'time', 'timetz', 'timestamp', 'timestamptz');
		$out = array();
		foreach ($cols as $name => $c) {
			$t = strtolower($c['typname']);
			if ($c['typtype'] === 'e' && isset($labels[$c['typname']])) {
				$out[$name] = array('kind' => 'enum', 'type' => $c['typname'], 'values' => $labels[$c['typname']]);
			} else {
				$kind = ($t === 'bool' ? 'bool'
					: (in_array($t, $numeric, true) ? 'number'
					: (in_array($t, $temporal, true) ? 'date' : 'text')));
				$out[$name] = array('kind' => $kind, 'type' => $c['typname'], 'values' => array());
			}
		}
		return $out;
	}

	function head($dark = null) {
		if (!isset($_GET['select'])) {
			return;
		}
		?>
<style<?php echo Adminer\nonce(); ?>>
td[id^="val["].cell-filter-target {
	outline: 1px solid var(--accent, #3574f0);
	outline-offset: -1px;
	background: color-mix(in srgb, var(--accent, #3574f0) 12%, transparent);
}
#cell-filter-menu {
	position: fixed;
	z-index: 10050;
	min-width: 18em;
	/* A uuid is 36 chars; at 28em the operator select + Filter button left the
	   value input ~248px against ~266px of text, clipping the last 18px of
	   every uuid (measured). Widen, and stack the input on its own row below. */
	max-width: 34em;
	margin: 0;
	padding: .25em 0;
	border: 1px solid var(--border, #888);
	background: var(--bg, #fff);
	color: var(--fg, inherit);
	box-shadow: 0 2px 8px rgba(0,0,0,.25);
	font: inherit;
}
#cell-filter-menu button,
#cell-filter-menu .cell-filter-row {
	display: block;
	width: 100%;
	box-sizing: border-box;
	margin: 0;
	padding: .35em .75em;
	border: 0;
	background: transparent;
	color: inherit;
	font: inherit;
	text-align: left;
	cursor: pointer;
}
#cell-filter-menu button:hover,
#cell-filter-menu button:focus {
	background: rgba(127,127,127,.2);
	outline: 0;
}
#cell-filter-menu .cell-filter-sep {
	height: 0;
	margin: .25em 0;
	border: 0;
	border-top: 1px solid var(--border, #888);
}
/* Explicit 2x2 grid: operator + Filter share the top row, the value input
   spans the full width beneath them. DOM order stays select -> input -> button
   (keyboard order), so the areas are assigned explicitly rather than relying
   on auto-placement. */
#cell-filter-menu .cell-filter-row {
	cursor: default;
	display: grid;
	grid-template-columns: 1fr auto;
	gap: .35em;
	align-items: center;
}
#cell-filter-menu .cell-filter-row select,
#cell-filter-menu .cell-filter-row input {
	font: inherit;
	min-width: 0;
	max-width: 100%;
	color: inherit;
	background: var(--bg, #fff);
	border: 1px solid var(--border, #888);
	border-radius: var(--radius-sm, 4px);
	padding: .2em .35em;
}
#cell-filter-menu .cell-filter-row select {
	grid-area: 1 / 1 / 2 / 2;
}
#cell-filter-menu .cell-filter-row button {
	grid-area: 1 / 2 / 2 / 3;
	width: auto;
	padding: .25em .8em;
	border: 1px solid var(--border, #888);
	border-radius: var(--radius-sm, 4px);
}
#cell-filter-menu .cell-filter-row input[type="search"] {
	grid-area: 2 / 1 / 3 / 3;
	width: 100%;
	box-sizing: border-box;
	/* monospace makes uuids/ids scannable and the width predictable */
	font-family: var(--mono-stack, ui-monospace, SFMono-Regular, Menlo, Consolas, monospace);
	/* Size off the content, not the container: a uuid is 36 chars, so 40ch of
	   monospace guarantees it fits with room for the padding. This is what
	   actually widens the menu - max-width alone is only a cap, never a target.
	   Clamped against the viewport so it cannot overflow on a phone. */
	min-width: min(40ch, 74vw);
}
#cell-filter-menu .cell-filter-row input[type="search"]:disabled {
	opacity: .5;
}
#cell-filter-menu .cell-filter-hint {
	padding: .25em .75em .4em;
	opacity: .75;
	font-size: smaller;
	word-break: break-all;
}
/* Small heading above a type-aware group (enum siblings, comparisons, …). */
#cell-filter-menu .cell-filter-group {
	padding: .35em .75em .1em;
	margin-top: .15em;
	border-top: 1px solid var(--border, #888);
	opacity: .6;
	font-size: smaller;
	text-transform: uppercase;
	letter-spacing: .03em;
}
</style>
<script<?php echo Adminer\nonce(); ?>>
(() => {
	// Per-column type info (enum values, numeric/date/bool) for this table, so
	// the menu can suggest siblings and comparisons. Empty on non-pgsql.
	const COLTYPES = <?php echo json_encode($this->columnMeta(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
	const MENU_ID = 'cell-filter-menu';
	let ignoreScrollUntil = 0;
	let activeTd = null;

	const isValueCell = (el) => {
		if (!el || !el.closest) {
			return null;
		}
		// Text nodes / SVG / nested <a>/<code>/<span> inside the cell
		const node = el.nodeType === 3 ? el.parentElement : el;
		const td = node && node.closest('td');
		return td && typeof td.id === 'string' && td.id.startsWith('val[') ? td : null;
	};

	const columnFromTd = (td) => {
		const m = td.id.match(/\[([^\[\]]+)\]$/);
		return m ? m[1] : '';
	};

	const valueFromTd = (td) => {
		if (td.querySelector(':scope > i') && /^\s*NULL\s*$/i.test(td.textContent || '')) {
			return { isNull: true, value: '' };
		}
		// Prefer the FK/link text when present (ignore href noise).
		const link = td.querySelector(':scope > a');
		const raw = (link ? link.textContent : td.textContent) || '';
		const text = raw.replace(/\u00a0/g, ' ').replace(/\s+/g, ' ').trim();
		return { isNull: false, value: text };
	};

	const operators = () => {
		const sel = document.querySelector('#fieldset-search [name$="[op]"]');
		if (!sel) {
			return ['=', '!=', 'LIKE %%', 'IS NULL', 'IS NOT NULL'];
		}
		return [...sel.options].map((o) => o.value || o.textContent).filter(Boolean);
	};

	const preview = (value, max = 40) => {
		if (value.length <= max) {
			return value;
		}
		return value.slice(0, max - 1) + '…';
	};

	const clearTarget = () => {
		if (activeTd) {
			activeTd.classList.remove('cell-filter-target');
			activeTd = null;
		}
	};

	const closeMenu = () => {
		document.getElementById(MENU_ID)?.remove();
		clearTarget();
	};

	const applyFilter = (col, op, value, isNull) => {
		if (!col || !document.getElementById('fieldset-search')) {
			return;
		}
		const url = new URL(location.href);
		let max = -1;
		for (const key of [...url.searchParams.keys()]) {
			const m = key.match(/^where\[(\d+)\]/);
			if (m) {
				max = Math.max(max, Number(m[1]));
			}
		}
		const i = max + 1;
		url.searchParams.set(`where[${i}][col]`, col);
		url.searchParams.set(`where[${i}][op]`, op);
		if (op === 'IS NULL' || op === 'IS NOT NULL' || isNull) {
			url.searchParams.set(`where[${i}][val]`, '');
			if (isNull && op !== 'IS NULL' && op !== 'IS NOT NULL') {
				url.searchParams.set(`where[${i}][op]`, 'IS NULL');
			}
		} else {
			url.searchParams.set(`where[${i}][val]`, value);
		}
		url.searchParams.delete('page');
		url.searchParams.delete('next');
		location.href = url.toString();
	};

	const placeMenu = (menu, x, y) => {
		menu.style.left = '0px';
		menu.style.top = '0px';
		document.body.appendChild(menu);
		const rect = menu.getBoundingClientRect();
		const left = Math.min(x, Math.max(0, window.innerWidth - rect.width - 4));
		const top = Math.min(y, Math.max(0, window.innerHeight - rect.height - 4));
		menu.style.left = left + 'px';
		menu.style.top = top + 'px';
	};

	const openMenu = (td, x, y) => {
		closeMenu();
		const col = columnFromTd(td);
		if (!col) {
			return;
		}

		activeTd = td;
		td.classList.add('cell-filter-target');

		const { isNull, value } = valueFromTd(td);
		const ops = operators();
		const has = (op) => ops.includes(op);

		const menu = document.createElement('div');
		menu.id = MENU_ID;
		menu.setAttribute('role', 'menu');

		const addBtn = (label, fn) => {
			const btn = document.createElement('button');
			btn.type = 'button';
			btn.setAttribute('role', 'menuitem');
			btn.textContent = label;
			btn.addEventListener('click', (e) => {
				e.preventDefault();
				e.stopPropagation();
				closeMenu();
				fn();
			});
			menu.appendChild(btn);
		};

		const addGroup = (label) => {
			const g = document.createElement('div');
			g.className = 'cell-filter-group';
			g.textContent = label;
			menu.appendChild(g);
		};

		const hint = document.createElement('div');
		hint.className = 'cell-filter-hint';
		hint.textContent = col + (isNull ? ' · NULL' : ' · ' + preview(value));
		menu.appendChild(hint);

		if (isNull) {
			if (has('IS NULL')) {
				addBtn('IS NULL', () => applyFilter(col, 'IS NULL', '', true));
			}
			if (has('IS NOT NULL')) {
				addBtn('IS NOT NULL', () => applyFilter(col, 'IS NOT NULL', '', false));
			}
		} else {
			if (has('=')) {
				addBtn('= ' + preview(value), () => applyFilter(col, '=', value, false));
			}
			if (has('!=')) {
				addBtn('≠ ' + preview(value), () => applyFilter(col, '!=', value, false));
			}
			const likeOp = has('ILIKE %%') ? 'ILIKE %%' : (has('LIKE %%') ? 'LIKE %%' : null);
			if (likeOp) {
				addBtn('Contains…', () => applyFilter(col, likeOp, value, false));
			}

			// Type-aware suggestions on top of the value the user clicked.
			const meta = COLTYPES && COLTYPES[col] ? COLTYPES[col] : null;
			if (meta && meta.kind === 'enum' && Array.isArray(meta.values) && has('=')) {
				// The request: for an enum, also offer "= <each other value>".
				const others = meta.values.filter((v) => v !== value);
				if (others.length) {
					addGroup('Other ' + meta.type + ' values');
					for (const v of others) {
						addBtn('= ' + preview(v), () => applyFilter(col, '=', v, false));
					}
				}
			} else if (meta && (meta.kind === 'number' || meta.kind === 'date')) {
				const cmp = ['>', '>=', '<', '<='].filter(has);
				if (cmp.length) {
					addGroup(meta.kind === 'date' ? 'Compare (time)' : 'Compare');
					for (const op of cmp) {
						addBtn(op + ' ' + preview(value), () => applyFilter(col, op, value, false));
					}
				}
			} else if (meta && meta.kind === 'bool' && has('=')) {
				addGroup('Boolean');
				addBtn('= true', () => applyFilter(col, '=', 'true', false));
				addBtn('= false', () => applyFilter(col, '=', 'false', false));
			}
		}

		const sep = document.createElement('hr');
		sep.className = 'cell-filter-sep';
		menu.appendChild(sep);

		const row = document.createElement('div');
		row.className = 'cell-filter-row';

		const opSel = document.createElement('select');
		opSel.setAttribute('aria-label', 'Operator');
		for (const op of ops) {
			const opt = document.createElement('option');
			opt.value = op;
			opt.textContent = op;
			opSel.appendChild(opt);
		}
		opSel.value = isNull && has('IS NULL') ? 'IS NULL' : (has('=') ? '=' : ops[0]);

		const valInput = document.createElement('input');
		valInput.type = 'search';
		valInput.value = isNull ? '' : value;
		valInput.setAttribute('aria-label', 'Value');
		valInput.placeholder = 'value';

		const syncValEnabled = () => {
			const nullOp = opSel.value === 'IS NULL' || opSel.value === 'IS NOT NULL';
			valInput.disabled = nullOp;
		};
		opSel.addEventListener('change', syncValEnabled);
		syncValEnabled();

		const applyBtn = document.createElement('button');
		applyBtn.type = 'button';
		applyBtn.textContent = 'Filter';
		applyBtn.addEventListener('click', (e) => {
			e.preventDefault();
			e.stopPropagation();
			const op = opSel.value;
			const nullOp = op === 'IS NULL' || op === 'IS NOT NULL';
			closeMenu();
			applyFilter(col, op, valInput.value, nullOp);
		});

		row.appendChild(opSel);
		row.appendChild(valInput);
		row.appendChild(applyBtn);
		menu.appendChild(row);

		valInput.addEventListener('keydown', (e) => {
			if (e.key === 'Enter') {
				e.preventDefault();
				applyBtn.click();
			}
		});

		// Opening the menu + focusing the input can scroll the page; ignore
		// those scroll events so the menu does not close on text/UUID cells.
		ignoreScrollUntil = Date.now() + 500;
		placeMenu(menu, x, y);

		requestAnimationFrame(() => {
			try {
				valInput.focus({ preventScroll: true });
				valInput.select();
			} catch (_) {
				/* older browsers */
			}
		});
	};

	const onContextMenu = (e) => {
		if (e.shiftKey) {
			return;
		}
		if (!document.getElementById('fieldset-search')) {
			return;
		}
		const td = isValueCell(e.target);
		if (!td) {
			return;
		}
		e.preventDefault();
		e.stopPropagation();
		if (typeof e.stopImmediatePropagation === 'function') {
			e.stopImmediatePropagation();
		}
		openMenu(td, e.clientX, e.clientY);
	};

	// Prevent FK <a> from taking focus / browser link menu fighting us.
	document.addEventListener('mousedown', (e) => {
		if (e.button !== 2 || e.shiftKey) {
			return;
		}
		if (!document.getElementById('fieldset-search')) {
			return;
		}
		const td = isValueCell(e.target);
		if (!td) {
			return;
		}
		e.preventDefault();
	}, true);

	document.addEventListener('contextmenu', onContextMenu, true);

	document.addEventListener('click', (e) => {
		const menu = document.getElementById(MENU_ID);
		if (!menu) {
			return;
		}
		if (!menu.contains(e.target)) {
			closeMenu();
		}
	}, true);

	document.addEventListener('keydown', (e) => {
		if (e.key === 'Escape') {
			closeMenu();
		}
	}, true);

	// capture:true on window catches scroll from ANY element, and `scroll` does
	// not bubble - so pasting a value longer than the input fired the input's
	// own horizontal scroll and closed the menu mid-paste. That is why
	// "right-click then Ctrl+V" appeared not to work: the paste succeeded and
	// the menu vanished. Ignore scrolls originating inside the menu.
	window.addEventListener('scroll', (e) => {
		const menu = document.getElementById(MENU_ID);
		if (!menu) {
			return;
		}
		const target = e.target;
		if (target instanceof Node && (menu === target || menu.contains(target))) {
			return;
		}
		if (Date.now() < ignoreScrollUntil) {
			return;
		}
		closeMenu();
	}, true);
	window.addEventListener('resize', closeMenu);
})();
</script>
		<?php
	}

	protected $translations = array(
		'en' => array('' => 'Right-click a result cell to filter by its column/value'),
		'vi' => array('' => 'Chuột phải ô dữ liệu để lọc theo cột/giá trị'),
	);
}

return new AdminerCellFilter();
