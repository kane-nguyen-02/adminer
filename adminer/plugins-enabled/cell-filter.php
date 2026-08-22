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
</style>
<script<?php echo Adminer\nonce(); ?>>
(() => {
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
