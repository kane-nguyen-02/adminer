<?php

/**
 * Searchable dropdowns for the Select filter form only.
 *
 * Targets: Search col, Sort order, Select columns[n][col].
 * (Operator select stays native — short fixed list, no search needed.)
 * Keeps the native <select> in place (no wrap) so Adminer's selectAddRow /
 * firstChild / cloneNode assumptions stay intact. Filter UI is a body portal.
 */
final class AdminerSelectSearchable extends Adminer\Plugin {
	function head($dark = null) {
		if (!isset($_GET['select'])) {
			return;
		}
		?>
<style<?php echo Adminer\nonce(); ?>>
#ss-portal {
	position: fixed;
	z-index: 10040;
	display: flex;
	flex-direction: column;
	min-width: 12em;
	max-width: min(28em, calc(100vw - 16px));
	max-height: min(18em, calc(100vh - 24px));
	margin: 0;
	padding: .25rem;
	border: 1px solid color-mix(in srgb, var(--border, #888) 80%, transparent);
	border-radius: .5rem;
	background: var(--bg, #fff);
	color: var(--fg, inherit);
	box-shadow: 0 10px 38px -10px rgba(0, 0, 0, .35), 0 0 0 1px color-mix(in srgb, var(--border, #888) 40%, transparent);
	font: inherit;
	overflow: hidden;
}
#ss-portal .ss-filter {
	flex: 0 0 auto;
	display: block;
	width: 100%;
	box-sizing: border-box;
	margin: 0 0 .2rem;
	padding: .45em .6em;
	border: 1px solid color-mix(in srgb, var(--border, #888) 70%, transparent);
	border-radius: .375rem;
	background: var(--dim, #f6f6f6);
	color: inherit;
	font: inherit;
	outline: none;
}
#ss-portal .ss-filter:focus {
	border-color: var(--accent, #3574f0);
	box-shadow: 0 0 0 2px color-mix(in srgb, var(--accent, #3574f0) 25%, transparent);
}
#ss-portal .ss-list {
	flex: 1 1 auto;
	overflow: auto;
	margin: 0;
	padding: .15rem 0;
	list-style: none;
	overscroll-behavior: contain;
	scrollbar-width: thin;
}
#ss-portal .ss-item {
	display: block;
	box-sizing: border-box;
	margin: 0 .15rem;
	width: calc(100% - .3rem);
	padding: .38em .55em;
	border: 0;
	border-radius: .35rem;
	background: transparent;
	color: inherit;
	font: inherit;
	text-align: left;
	cursor: pointer;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}
#ss-portal .ss-item:hover,
#ss-portal .ss-item[aria-selected="true"] {
	background: color-mix(in srgb, var(--accent, #3574f0) 18%, transparent);
}
#ss-portal .ss-empty {
	padding: .5em .65em;
	color: var(--muted, #888);
	font-size: smaller;
}
#form select.ss-open {
	outline: 1px solid var(--accent, #3574f0);
	outline-offset: 1px;
}
#fieldset-sort label:has(input[name^="desc["]) {
	display: inline-flex;
	align-items: center;
	gap: .25em;
	white-space: nowrap;
	cursor: pointer;
	user-select: none;
}
#fieldset-sort .ss-desc-label {
	font-size: 0.85em;
	color: var(--muted, #888);
	letter-spacing: 0.02em;
}
</style>
<script<?php echo Adminer\nonce(); ?>>
(() => {
	const PORTAL_ID = 'ss-portal';
	const TARGETS = [
		'#form #fieldset-search select[name^="where["][name$="[col]"]',
		'#form #fieldset-sort select[name^="order["]',
		'#form #fieldset-select select[name^="columns["][name$="[col]"]',
	].join(',');

	let activeSelect = null;
	let activeIndex = -1;
	let ignoreScrollUntil = 0;

	const isTarget = (el) => {
		if (!el || el.tagName !== 'SELECT' || !el.matches(TARGETS)) {
			return false;
		}
		if (el.closest('#' + PORTAL_ID) || el.closest('#cell-filter-menu')) {
			return false;
		}
		return true;
	};

	const optionLabel = (opt) => {
		const t = (opt.textContent || '').replace(/\s+/g, ' ').trim();
		return t || opt.value || '(empty)';
	};

	const collectOptions = (sel) => {
		const out = [];
		for (const opt of sel.options) {
			out.push({
				value: opt.value,
				label: optionLabel(opt),
				disabled: !!opt.disabled,
				index: opt.index,
			});
		}
		return out;
	};

	const closePortal = () => {
		document.getElementById(PORTAL_ID)?.remove();
		if (activeSelect) {
			activeSelect.classList.remove('ss-open');
			activeSelect = null;
		}
		activeIndex = -1;
	};

	const fireChange = (sel) => {
		sel.dispatchEvent(new Event('change', { bubbles: true, cancelable: true }));
	};

	const pick = (sel, value) => {
		sel.value = value;
		if (sel.value !== value) {
			for (const opt of sel.options) {
				if (opt.value === value) {
					opt.selected = true;
					break;
				}
			}
		}
		closePortal();
		fireChange(sel);

		// After choosing a SEARCH column, hand focus to that row's value input,
		// not back to the <select>. Leaving focus on the select meant the next
		// keystrokes - which the user intends for the value - re-opened this
		// portal (a printable key opens it with initialQuery) and Enter then
		// picked items[activeIndex] || items[0], silently rewriting the column.
		// That is how `createdAt` turned into `itemName`. Moving focus forward
		// also matches what every other DB client does: pick column -> type value.
		const searchRow = sel.matches('#fieldset-search select[name$="[col]"]')
			? sel.closest('#fieldset-search > div')
			: null;
		const nextInput = searchRow?.querySelector('input[name$="[val]"]');
		const focusTarget = nextInput || sel;
		try {
			focusTarget.focus({ preventScroll: true });
		} catch (_) {
			focusTarget.focus();
		}
	};

	const placePortal = (portal, anchor) => {
		const r = anchor.getBoundingClientRect();
		const pad = 8;
		portal.style.left = '0px';
		portal.style.top = '0px';
		portal.style.minWidth = Math.max(r.width, 160) + 'px';
		document.body.appendChild(portal);
		const pr = portal.getBoundingClientRect();
		let left = r.left;
		let top = r.bottom + 2;
		if (left + pr.width > window.innerWidth - pad) {
			left = Math.max(pad, window.innerWidth - pr.width - pad);
		}
		if (top + pr.height > window.innerHeight - pad) {
			top = Math.max(pad, r.top - pr.height - 2);
		}
		portal.style.left = left + 'px';
		portal.style.top = top + 'px';
	};

	const syncActive = (list) => {
		const items = [...list.querySelectorAll('.ss-item')];
		items.forEach((el, i) => {
			el.setAttribute('aria-selected', i === activeIndex ? 'true' : 'false');
		});
		const cur = items[activeIndex];
		if (cur) {
			cur.scrollIntoView({ block: 'nearest' });
		}
	};

	const renderList = (portal, sel, query) => {
		const list = portal.querySelector('.ss-list');
		const q = (query || '').trim().toLowerCase();
		list.innerHTML = '';
		const items = collectOptions(sel).filter((o) => {
			if (o.disabled) {
				return false;
			}
			if (!q) {
				return true;
			}
			return o.label.toLowerCase().includes(q) || String(o.value).toLowerCase().includes(q);
		});

		if (!items.length) {
			const empty = document.createElement('div');
			empty.className = 'ss-empty';
			empty.textContent = 'No matches';
			list.appendChild(empty);
			activeIndex = -1;
			return;
		}

		let matchedCurrent = -1;
		items.forEach((item, i) => {
			if (item.value === sel.value) {
				matchedCurrent = i;
			}
			const btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'ss-item';
			btn.textContent = item.label;
			btn.dataset.value = item.value;
			btn.setAttribute('role', 'option');
			btn.setAttribute('aria-selected', 'false');
			btn.addEventListener('mousedown', (e) => {
				e.preventDefault();
				e.stopPropagation();
				pick(sel, item.value);
			});
			list.appendChild(btn);
		});

		if (activeIndex < 0) {
			activeIndex = matchedCurrent >= 0 ? matchedCurrent : 0;
		}
		if (activeIndex >= items.length) {
			activeIndex = items.length - 1;
		}
		syncActive(list);
	};

	const openPortal = (sel, initialQuery = '') => {
		closePortal();
		activeSelect = sel;
		activeIndex = -1;
		sel.classList.add('ss-open');

		const portal = document.createElement('div');
		portal.id = PORTAL_ID;
		portal.setAttribute('role', 'listbox');
		portal.innerHTML = `
			<input type="search" class="ss-filter" autocomplete="off" spellcheck="false" placeholder="Filter…" aria-label="Filter options">
			<div class="ss-list" role="presentation"></div>
		`;
		const filter = portal.querySelector('.ss-filter');
		filter.value = initialQuery;

		ignoreScrollUntil = Date.now() + 400;
		placePortal(portal, sel);
		renderList(portal, sel, initialQuery);

		filter.addEventListener('input', () => {
			activeIndex = 0;
			renderList(portal, sel, filter.value);
		});

		filter.addEventListener('keydown', (e) => {
			const list = portal.querySelector('.ss-list');
			const items = [...list.querySelectorAll('.ss-item')];
			if (e.key === 'Escape') {
				e.preventDefault();
				e.stopPropagation();
				closePortal();
				sel.focus();
				return;
			}
			if (e.key === 'ArrowDown') {
				e.preventDefault();
				if (!items.length) {
					return;
				}
				activeIndex = (activeIndex + 1) % items.length;
				syncActive(list);
				return;
			}
			if (e.key === 'ArrowUp') {
				e.preventDefault();
				if (!items.length) {
					return;
				}
				activeIndex = (activeIndex - 1 + items.length) % items.length;
				syncActive(list);
				return;
			}
			if (e.key === 'Enter') {
				e.preventDefault();
				const cur = items[activeIndex] || items[0];
				if (cur) {
					pick(sel, cur.dataset.value);
				}
			}
		});

		requestAnimationFrame(() => {
			try {
				filter.focus({ preventScroll: true });
				if (initialQuery) {
					filter.setSelectionRange(initialQuery.length, initialQuery.length);
				} else {
					filter.select();
				}
			} catch (_) {
				filter.focus();
			}
		});
	};

	document.addEventListener('mousedown', (e) => {
		const portal = document.getElementById(PORTAL_ID);
		if (portal && portal.contains(e.target)) {
			return;
		}
		if (portal) {
			closePortal();
		}
		const sel = e.target.closest && e.target.closest('select');
		if (!isTarget(sel)) {
			return;
		}
		e.preventDefault();
		e.stopPropagation();
		openPortal(sel);
	}, true);

	document.addEventListener('keydown', (e) => {
		const sel = e.target;
		if (!isTarget(sel)) {
			return;
		}
		if (e.key === 'Escape' && document.getElementById(PORTAL_ID)) {
			e.preventDefault();
			closePortal();
			return;
		}
		if (e.key === 'Enter' || e.key === ' ' || e.key === 'ArrowDown' || e.key === 'ArrowUp') {
			e.preventDefault();
			openPortal(sel);
			return;
		}
		if (e.key.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey) {
			e.preventDefault();
			openPortal(sel, e.key);
		}
	}, true);

	document.addEventListener('scroll', (e) => {
		const portal = document.getElementById(PORTAL_ID);
		if (!portal || !activeSelect) {
			return;
		}
		if (e.target instanceof Element && (portal === e.target || portal.contains(e.target))) {
			return;
		}
		if (Date.now() < ignoreScrollUntil) {
			return;
		}
		placePortal(portal, activeSelect);
	}, true);

	window.addEventListener('resize', () => {
		const portal = document.getElementById(PORTAL_ID);
		if (portal && activeSelect) {
			placePortal(portal, activeSelect);
		}
	});

	// Limit / Text length: grow input width with value length (no DOM wrap).
	const SIZE_FIT_SEL = '#form input[name="limit"], #form input[name="text_length"]';
	const SIZE_FIT_MIN = 5;

	const fitSizeInput = (el) => {
		const len = Math.max(String(el.value ?? '').length, SIZE_FIT_MIN);
		el.style.width = len + 'ch';
	};

	const bootSizeFit = () => {
		for (const el of document.querySelectorAll(SIZE_FIT_SEL)) {
			fitSizeInput(el);
			el.addEventListener('input', () => fitSizeInput(el));
		}
	};

	// Sort checkbox label: "descending" → "desc ↓" (clone-safe via data attr)
	const relabelSortDesc = () => {
		for (const label of document.querySelectorAll('#fieldset-sort label')) {
			const input = label.querySelector('input[type="checkbox"][name^="desc["]');
			if (!input || label.dataset.ssDesc === '1') {
				continue;
			}
			for (const node of [...label.childNodes]) {
				if (node !== input) {
					node.remove();
				}
			}
			label.append(' ');
			const tip = document.createElement('span');
			tip.className = 'ss-desc-label';
			tip.textContent = 'desc ↓';
			tip.setAttribute('aria-hidden', 'true');
			label.append(tip);
			if (!input.getAttribute('aria-label')) {
				input.setAttribute('aria-label', 'descending');
			}
			label.dataset.ssDesc = '1';
		}
	};

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', () => {
			bootSizeFit();
			relabelSortDesc();
		});
	} else {
		bootSizeFit();
		relabelSortDesc();
	}

	// New Sort rows from selectAddRow are clones of an already-relabeled
	// label (dataset + markup copy). Re-scan after change just in case.
	document.getElementById('fieldset-sort')?.addEventListener('change', () => {
		requestAnimationFrame(relabelSortDesc);
	});
})();
</script>
		<?php
	}

	protected $translations = array(
		'en' => array('' => 'Searchable column/operator dropdowns on Select form'),
		'vi' => array('' => 'Dropdown cột/toán tử có ô lọc trên form Select'),
	);
}

return new AdminerSelectSearchable();
