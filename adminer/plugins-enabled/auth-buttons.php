<?php

/**
 * Give the Login and Logout buttons an inline Lucide icon that matches their
 * own text colour.
 *
 * Both were previously drawn with `background-image: url("data:image/svg+xml,
 * ...stroke='white'...")`. A data-URI SVG is a separate document, so it cannot
 * read `currentColor` - the white was frozen in. On a palette whose
 * --accent-fg is dark (Gruvbox, Solarized) that produced a white glyph beside
 * dark text on the same button: two different colours for one label.
 *
 * The glyph is now a real child <svg stroke="currentColor">, so it is always
 * exactly the button's text colour and therefore always carries the same
 * contrast the label does.
 *
 * That means the control has to be able to hold a child, and an
 * <input type="submit"> cannot (replaced element - no children, no ::before).
 * So each one is swapped for a <button type="submit"> carrying the same name
 * and value, which submits identically:
 *
 *   Login   <input type="submit" value="Login">                 - no name, never submitted
 *   Logout  <input type="submit" name="logout" value="Logout">  - name+value preserved
 *
 * Runs on every page, because Logout lives in the header of all of them.
 */
final class AdminerAuthButtons extends Adminer\Plugin {
	function head($dark = null) {
		?>
<style<?php echo Adminer\nonce(); ?>>
.ab-btn {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	gap: 7px;
	cursor: pointer;
}
.ab-ico {
	width: 15px;
	height: 15px;
	flex: 0 0 auto;
	stroke: currentColor;      /* the entire point: one colour per button */
	fill: none;
	stroke-width: 2;
	stroke-linecap: round;
	stroke-linejoin: round;
	pointer-events: none;
}
/* the login button is full width, so keep icon + label centred together */
body:has(#username) #content p button.ab-btn {
	width: 100%;
}
</style>
<script<?php echo Adminer\nonce(); ?>>
(() => {
	const GLYPH = {
		// lucide: log-in
		login: '<path d="m10 17 5-5-5-5"/><path d="M15 12H3"/><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/>',
		// lucide: log-out
		logout: '<path d="m16 17 5-5-5-5"/><path d="M21 12H9"/><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>',
	};

	const svgFor = (name) => {
		const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
		svg.setAttribute('viewBox', '0 0 24 24');
		svg.setAttribute('aria-hidden', 'true');
		svg.setAttribute('class', 'ab-ico');
		svg.innerHTML = GLYPH[name];
		return svg;
	};

	/** Swap an <input type=submit> for an equivalent <button type=submit>. */
	const convert = (input, glyph) => {
		if (!input || input.dataset.abDone) {
			return;
		}
		const label = (input.value || '').trim();
		const btn = document.createElement('button');
		btn.type = 'submit';
		// name + value are what the server reads - carry them over verbatim.
		if (input.name) {
			btn.name = input.name;
			btn.value = input.value;
		}
		if (input.id) {
			btn.id = input.id;
		}
		if (input.className) {
			btn.className = input.className;
		}
		btn.classList.add('ab-btn');
		btn.dataset.abDone = '1';
		btn.append(svgFor(glyph));
		if (label) {
			const span = document.createElement('span');
			span.textContent = label;
			btn.append(span);
			btn.title = input.title || label;
			btn.setAttribute('aria-label', label);
		}
		input.replaceWith(btn);
	};

	const boot = () => {
		// Logout: named, present in the header of every authenticated page.
		convert(document.querySelector('input[type="submit"][name="logout"]'), 'logout');

		// Login: the submit inside the login form (identified by #username).
		const user = document.getElementById('username');
		const loginForm = user ? user.form : null;
		if (loginForm) {
			convert(loginForm.querySelector('input[type="submit"]:not([name])'), 'login');
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
		'en' => array('' => 'Icons on the Login and Logout buttons'),
		'vi' => array('' => 'Thêm icon cho nút Đăng nhập và Đăng xuất'),
	);
}

return new AdminerAuthButtons();
