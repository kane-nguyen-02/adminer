<?php

/**
 * Collapsible sidebar, toggled by a button or Ctrl+B.
 *
 * Adminer's sidebar (#menu, position:absolute, ~274px) is always there, and
 * #content simply carries a matching margin-left. On a wide result grid that
 * 300px is the difference between reading a row and scrolling for it.
 *
 * State lives in a cookie and is applied to <html> in head(), BEFORE the body
 * paints - the same trick theme-switcher.php uses. Doing it after
 * DOMContentLoaded would show the sidebar for a frame and then yank it away on
 * every single page load.
 *
 * Note this also has to move --toolbar-inset: that variable exists because the
 * Select toolbar is capped against the viewport minus the sidebar, so with the
 * sidebar gone the cap has to widen or the toolbar keeps the old dead gap.
 *
 * Adminer's own mobile hamburger (#menuopen / menuToggle()) is left completely
 * alone - this is a separate, persistent, desktop concern.
 */
final class AdminerSidebarCollapse extends Adminer\Plugin {
	private const COOKIE = 'adminer_sidebar';

	function head($dark = null) {
		?>
<script<?php echo Adminer\nonce(); ?>>
(() => {
	// Pre-paint: no flash of the wrong layout.
	const m = document.cookie.match(/(?:^|; )adminer_sidebar=(open|collapsed)/);
	document.documentElement.setAttribute('data-sidebar', m ? m[1] : 'open');
})();
</script>
<style<?php echo Adminer\nonce(); ?>>
html[data-sidebar="collapsed"] #menu {
	display: none;
}
html[data-sidebar="collapsed"] #content {
	margin-left: 16px !important;
}
/* #breadcrumb is position:absolute with a hard-coded `left:21em` - the sidebar
   width - so #content's margin never reaches it and it was left stranded with
   the old gap to its left. 34px because the element also carries
   `margin:0 0 0 -18px`, so 34 - 18 lands on the same 16px as #content.
   Adminer does the same thing at its own mobile breakpoint
   (`#breadcrumb{left:48px !important}`), just with room for the hamburger. */
html[data-sidebar="collapsed"] #breadcrumb {
	left: 34px !important;
}
/* The toolbar cap is measured from the viewport minus the sidebar, so it has
   to be told the sidebar is gone. */
html[data-sidebar="collapsed"] #content #form {
	--toolbar-inset: 3rem;
}
.sc-toggle {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 28px;
	height: 26px;
	padding: 0;
	border: 1px solid var(--border);
	border-radius: var(--radius-sm);
	background: transparent;
	color: var(--muted);
	cursor: pointer;
	transition: color 120ms ease, border-color 120ms ease, background-color 120ms ease;
}
.sc-toggle:hover,
.sc-toggle:focus-visible {
	color: var(--accent);
	border-color: var(--accent);
	background: color-mix(in srgb, var(--accent) 12%, transparent);
	outline: none;
}
.sc-ico {
	width: 15px;
	height: 15px;
	stroke: currentColor;
	fill: none;
	stroke-width: 2;
	stroke-linecap: round;
	stroke-linejoin: round;
	pointer-events: none;
}
</style>
<script<?php echo Adminer\nonce(); ?>>
(() => {
	const COOKIE = <?php echo json_encode(self::COOKIE); ?>;
	const root = document.documentElement;

	// lucide: panel-left-close / panel-left-open
	const GLYPH = {
		close: '<rect width="18" height="18" x="3" y="3" rx="2"/><path d="M9 3v18"/><path d="m16 15-3-3 3-3"/>',
		open:  '<rect width="18" height="18" x="3" y="3" rx="2"/><path d="M9 3v18"/><path d="m14 9 3 3-3 3"/>',
	};

	const svgFor = (name) => {
		const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
		svg.setAttribute('viewBox', '0 0 24 24');
		svg.setAttribute('aria-hidden', 'true');
		svg.setAttribute('class', 'sc-ico');
		svg.innerHTML = GLYPH[name];
		return svg;
	};

	const collapsed = () => root.getAttribute('data-sidebar') === 'collapsed';

	let btn = null;
	const paint = () => {
		if (!btn) {
			return;
		}
		const isCollapsed = collapsed();
		btn.textContent = '';
		btn.append(svgFor(isCollapsed ? 'open' : 'close'));
		const label = (isCollapsed ? 'Show sidebar' : 'Hide sidebar') + ' (Ctrl+B)';
		btn.title = label;
		btn.setAttribute('aria-label', label);
		btn.setAttribute('aria-expanded', String(!isCollapsed));
	};

	const setState = (state) => {
		root.setAttribute('data-sidebar', state);
		// 365 days, same lifetime as the other UI preferences.
		document.cookie = COOKIE + '=' + state + ';path=/;max-age=31536000';
		paint();
	};

	const toggle = () => setState(collapsed() ? 'open' : 'collapsed');

	const boot = () => {
		// Sit next to the username / Logout cluster: that block is present on
		// every authenticated page and lives outside the grid, so adding to it
		// cannot reflow the result table.
		const logout = document.querySelector('#foot button[name="logout"], #foot input[name="logout"]');
		const host = logout ? logout.closest('p') : null;
		if (!host) {
			return;                       // login page: nothing to collapse
		}
		btn = document.createElement('button');
		btn.type = 'button';
		btn.className = 'sc-toggle';
		paint();
		btn.addEventListener('click', (e) => {
			e.preventDefault();
			toggle();
		});
		host.prepend(btn);
	};

	// Ctrl+B / Cmd+B. Ignored while typing so it cannot fight a text field.
	document.addEventListener('keydown', (e) => {
		if (!(e.ctrlKey || e.metaKey) || e.altKey || e.shiftKey) {
			return;
		}
		if (e.key !== 'b' && e.key !== 'B' && e.code !== 'KeyB') {
			return;
		}
		const el = document.activeElement;
		const tag = el && el.tagName;
		if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || (el && el.isContentEditable)) {
			return;
		}
		e.preventDefault();
		toggle();
	});

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
		'en' => array('' => 'Collapsible sidebar (Ctrl+B)'),
		'vi' => array('' => 'Thu gọn sidebar (Ctrl+B)'),
	);
}

return new AdminerSidebarCollapse();
