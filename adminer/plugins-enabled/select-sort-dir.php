<?php

/**
 * Replace the Sort "desc" checkbox with a direction button, and mirror the
 * direction onto the sorted column's table header.
 *
 * Adminer renders sort direction as
 *   <label><input type="checkbox" name="desc[0]" value="1"> desc</label>
 * which shows the direction as a *state to read* rather than as a direction.
 * It is now a single button whose Lucide glyph IS the direction:
 * arrow-up-narrow-wide for ascending, arrow-down-wide-narrow for descending.
 * Clicking it flips the sort.
 *
 * The checkbox itself stays in the DOM, only hidden. That is deliberate: a
 * display:none input is still submitted (only `disabled` removes it), so
 * `desc[0]=1` reaches Adminer exactly as before and nothing server-side
 * changes. The button just toggles `.checked` and fires `change`, which is
 * also what makes select-autoapply.php commit the new sort immediately.
 *
 * The header indicator is derived from the sort fieldset itself, so it can
 * never disagree with the controls - and Adminer marks the sorted column with
 * no class of its own, which is why it needed adding at all.
 *
 * Coexists with select-searchable.php, which adds its own `.ss-desc-label`
 * text to the same label; that span is hidden rather than removed.
 */
final class AdminerSelectSortDir extends Adminer\Plugin {
	function head($dark = null) {
		if (!isset($_GET['select'])) {
			return;
		}
		?>
<style<?php echo Adminer\nonce(); ?>>
/* Hide the original control without removing it from the form. */
#fieldset-sort label.sd-host {
	display: inline-flex;
	align-items: center;
	margin: 0;
	gap: 0;
}
#fieldset-sort label.sd-host input[type="checkbox"],
#fieldset-sort label.sd-host .ss-desc-label {
	display: none;
}
.sd-toggle {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	gap: 5px;
	min-height: 26px;
	padding: 3px 8px;
	border: 1px solid var(--border);
	border-radius: var(--radius-sm);
	background: transparent;
	color: var(--fg);
	font: inherit;
	font-size: 11.5px;
	cursor: pointer;
	transition: border-color 120ms ease, color 120ms ease, background-color 120ms ease;
}
.sd-toggle:hover,
.sd-toggle:focus-visible {
	border-color: var(--accent);
	color: var(--accent);
	background: color-mix(in srgb, var(--accent) 12%, transparent);
	outline: none;
}
.sd-toggle[disabled] {
	opacity: 0.4;
	cursor: not-allowed;
}
.sd-ico {
	width: 14px;
	height: 14px;
	flex: 0 0 auto;
	stroke: currentColor;      /* follows the button colour, so theme + hover work */
	fill: none;
	stroke-width: 2;
	stroke-linecap: round;
	stroke-linejoin: round;
	pointer-events: none;
}
/* Header indicator on the sorted column. */
#table thead th .sd-head {
	display: inline-flex;
	align-items: center;
	gap: 2px;
	margin-left: 5px;
	vertical-align: -2px;
	color: var(--accent);
}
#table thead th .sd-head .sd-ico {
	width: 13px;
	height: 13px;
}
#table thead th .sd-head-n {
	font: 600 9px/1 var(--mono-stack, monospace);
	color: var(--muted);
}
</style>
<script<?php echo Adminer\nonce(); ?>>
(() => {
	// lucide: arrow-up-narrow-wide / arrow-down-wide-narrow
	const GLYPH = {
		asc:  '<path d="m3 8 4-4 4 4"/><path d="M7 4v16"/><path d="M11 12h4"/><path d="M11 16h7"/><path d="M11 20h10"/>',
		desc: '<path d="m3 16 4 4 4-4"/><path d="M7 20V4"/><path d="M11 4h10"/><path d="M11 8h7"/><path d="M11 12h4"/>',
	};

	const svgFor = (dir) => {
		const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
		svg.setAttribute('viewBox', '0 0 24 24');
		svg.setAttribute('aria-hidden', 'true');
		svg.setAttribute('class', 'sd-ico');
		svg.innerHTML = GLYPH[dir];
		return svg;
	};

	const boot = () => {
		const fieldset = document.getElementById('fieldset-sort');
		if (!fieldset) {
			return;
		}

		const paintButton = (btn, checkbox) => {
			const dir = checkbox.checked ? 'desc' : 'asc';
			const label = checkbox.checked ? 'Descending' : 'Ascending';
			btn.textContent = '';
			btn.append(svgFor(dir));
			const span = document.createElement('span');
			span.textContent = dir;
			btn.append(span);
			btn.title = label + ' (click to flip)';
			btn.setAttribute('aria-label', label);
			btn.setAttribute('aria-pressed', String(checkbox.checked));
		};

		for (const row of fieldset.querySelectorAll(':scope > div')) {
			const checkbox = row.querySelector('input[type="checkbox"][name^="desc["]');
			if (!checkbox || checkbox.dataset.sdDone) {
				continue;
			}
			checkbox.dataset.sdDone = '1';
			const host = checkbox.closest('label') || row;
			host.classList.add('sd-host');

			const btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'sd-toggle';
			paintButton(btn, checkbox);

			const orderSel = row.querySelector('select[name^="order["]');
			const syncEnabled = () => {
				// Flipping direction with no column chosen would submit nothing.
				btn.disabled = !(orderSel && orderSel.value);
			};
			syncEnabled();
			if (orderSel) {
				orderSel.addEventListener('change', syncEnabled);
			}

			btn.addEventListener('click', (e) => {
				e.preventDefault();
				checkbox.checked = !checkbox.checked;
				paintButton(btn, checkbox);
				// Let Adminer + select-autoapply see a real change on the checkbox.
				checkbox.dispatchEvent(new Event('change', { bubbles: true }));
			});

			host.append(btn);
		}

		// ---- mirror the applied sort onto the column headers ----------------
		const grid = document.getElementById('table');
		if (!grid) {
			return;
		}
		const orders = [...fieldset.querySelectorAll('select[name^="order["]')];
		const active = orders.filter((o) => o.value);
		let position = 0;
		for (const sel of active) {
			position++;
			const idx = (sel.name.match(/\[(\d+)\]/) || [])[1];
			const desc = !!fieldset.querySelector('input[name="desc[' + idx + ']"]:checked');
			const th = document.getElementById('th[' + sel.value + ']');
			if (!th || th.querySelector('.sd-head')) {
				continue;
			}
			const wrap = document.createElement('span');
			wrap.className = 'sd-head';
			wrap.title = (desc ? 'Sorted descending' : 'Sorted ascending')
				+ (active.length > 1 ? ' (' + position + ')' : '');
			wrap.append(svgFor(desc ? 'desc' : 'asc'));
			if (active.length > 1) {
				const n = document.createElement('span');
				n.className = 'sd-head-n';
				n.textContent = String(position);
				wrap.append(n);
			}
			// after the column-name link, before Adminer's own down / = actions
			const anchor = th.querySelector(':scope > a');
			if (anchor) {
				anchor.after(wrap);
			} else {
				th.append(wrap);
			}
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
		'en' => array('' => 'Sort direction as a button, mirrored on the column header'),
		'vi' => array('' => 'Hướng sắp xếp dạng nút, đồng bộ lên tiêu đề cột'),
	);
}

return new AdminerSelectSortDir();
