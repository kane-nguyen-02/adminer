<?php

/**
 * Tag the Select-page Action buttons so adminer.css can give them an icon.
 *
 * JavaScript is needed only to mark the right controls and to give them a
 * tooltip: an <input type="submit"> is a replaced element, so it cannot host a
 * ::before, and CSS alone cannot tell "Select" from "Export". The glyph itself
 * is a background-image in adminer.css, sitting left of the button's own text
 * (icon AND label - icon-only proved too small and too cryptic).
 *
 * Safe on the submitted data: the "Select" button carries no name attribute,
 * so it is never part of the query string, and #select-reset is our own
 * type="button". Nothing server-side reads either value. Buttons that DO
 * carry a name (edit / clone / delete / export, in the other form) are left
 * completely alone - Adminer reads their values.
 *
 * The icon itself is drawn in adminer.css from `data-tb-icon`, using
 * mask + currentColor so it follows every palette.
 */
final class AdminerSelectToolbarIcons extends Adminer\Plugin {
	function head($dark = null) {
		if (!isset($_GET['select'])) {
			return;
		}
		?>
<script<?php echo Adminer\nonce(); ?>>
(() => {
	const boot = () => {
		const form = document.getElementById('form');
		if (!form) {
			return;
		}
		// Only the Action fieldset - the one holding our Reset button.
		const reset = form.querySelector('#select-reset');
		const fieldset = reset ? reset.closest('fieldset') : null;
		if (!fieldset) {
			return;
		}

		// Lucide paths. Rendered as a REAL inline <svg stroke="currentColor">,
		// not a background-image data URI: a data URI cannot see currentColor,
		// so any colour baked into it stays fixed and stops contrasting the
		// moment the palette or the hover state changes. Inline SVG inherits
		// the button's own colour, so hover/disabled/theme all just work.
		const GLYPH = {
			// lucide: send
			run: '<path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/>',
			// lucide: list-restart
			reset: '<path d="M21 5H3"/><path d="M7 12H3"/><path d="M7 19H3"/><path d="M12 18a5 5 0 0 0 9-3 4.5 4.5 0 0 0-4.5-4.5c-1.33 0-2.54.54-3.41 1.41L11 14"/><path d="M11 10v4h4"/>',
		};

		const svgFor = (name) => {
			const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
			svg.setAttribute('viewBox', '0 0 24 24');
			svg.setAttribute('aria-hidden', 'true');
			svg.setAttribute('class', 'tb-ico');
			svg.innerHTML = GLYPH[name];
			return svg;
		};

		/** Give an existing <button> its glyph. */
		const decorate = (btn, icon) => {
			if (btn.dataset.tbIcon) {
				return;
			}
			const label = (btn.textContent || '').trim();
			btn.textContent = '';
			btn.append(svgFor(icon));
			if (label) {
				const span = document.createElement('span');
				span.textContent = label;
				btn.append(span);
				if (!btn.title) {
					btn.title = label;
				}
				btn.setAttribute('aria-label', label);
			}
			btn.dataset.tbIcon = icon;
		};

		/**
		 * Swap the Select <input type="submit"> for a <button type="submit">.
		 *
		 * An <input> is a replaced element: it can hold neither a child <svg>
		 * nor a ::before, so an inline (and therefore themeable) icon is
		 * impossible while it stays an input. The button is a drop-in here
		 * because this control carries NO name attribute, so it was never part
		 * of the submitted query string either way.
		 */
		const replaceSubmit = (input, icon) => {
			const label = (input.value || '').trim();
			const btn = document.createElement('button');
			btn.type = 'submit';
			btn.className = input.className;
			if (input.id) {
				btn.id = input.id;
			}
			btn.append(svgFor(icon));
			if (label) {
				const span = document.createElement('span');
				span.textContent = label;
				btn.append(span);
				btn.title = input.title || label;
				btn.setAttribute('aria-label', label);
			}
			btn.dataset.tbIcon = icon;
			input.replaceWith(btn);
			return btn;
		};

		// Select — an <input type=submit> with no name.
		for (const input of fieldset.querySelectorAll('input[type="submit"]')) {
			if (input.name) {
				continue;                       // server reads this value: leave it
			}
			if ((input.value || '').trim().toLowerCase() === 'select') {
				replaceSubmit(input, 'run');
			}
		}

		// Reset — already a <button>, injected by select-shortcuts.php.
		const resetBtn = fieldset.querySelector('#select-reset');
		if (resetBtn) {
			decorate(resetBtn, 'reset');
		}
	};

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
	// select-shortcuts.php injects #select-reset during its own boot, so run
	// again shortly after rather than racing it.
	requestAnimationFrame(boot);
	setTimeout(boot, 250);
})();
</script>
		<?php
	}

	protected $translations = array(
		'en' => array('' => 'Show the Select action buttons as icons'),
		'vi' => array('' => 'Hiển thị các nút Action của Select dạng icon'),
	);
}

return new AdminerSelectToolbarIcons();
