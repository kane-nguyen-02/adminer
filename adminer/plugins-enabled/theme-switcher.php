<?php

/**
 * Switch between multiple dark themes (Tokyo Night / Nord / GitHub Dark
 * Dimmed / One Dark Pro / Dracula) — layered on top of Adminer's built-in
 * dark-switcher plugin (light/dark toggle, ☀ button, `adminer_dark` cookie).
 *
 * Mechanism: adminer-dark.css defines every theme's variables behind a
 * `:root[data-theme="..."]` selector (see that file's header comment for
 * why). This plugin only:
 *   1. Restores `data-theme` from the `adminer_theme` cookie in head()
 *      (fires before body paint, so there's no flash of the wrong theme).
 *   2. Renders a small <select> fixed above the ☀ toggle; picking a theme
 *      sets the cookie + `data-theme` attribute live, client-side only —
 *      no reload, no server round-trip, and it composes untouched with the
 *      existing light/dark toggle (that keeps working exactly as before,
 *      it just now toggles light vs "whichever dark theme is selected").
 */
final class AdminerThemeSwitcher extends Adminer\Plugin {
	const THEMES = array(
		'tokyonight' => 'Tokyo Night',
		'nord' => 'Nord',
		'github-dimmed' => 'GitHub Dark Dimmed',
		'onedark' => 'One Dark Pro',
		'dracula' => 'Dracula',
		'monokai' => 'Monokai',
		'solarized-dark' => 'Solarized Dark',
		'gruvbox-dark' => 'Gruvbox Dark',
		'catppuccin-mocha' => 'Catppuccin Mocha',
		'night-owl' => 'Night Owl',
		'ayu-dark' => 'Ayu Dark',
	);

	const COOKIE_NAME = 'adminer_theme';

	function head($dark = null) {
		$keys = json_encode(array_keys(self::THEMES));
		?>
<script<?php echo Adminer\nonce(); ?>>
(() => {
	const THEMES = <?php echo $keys; ?>;
	const m = document.cookie.match(/adminer_theme=([\w-]+)/);
	const theme = (m && THEMES.includes(m[1])) ? m[1] : THEMES[0];
	document.documentElement.setAttribute('data-theme', theme);
})();
</script>
		<?php
	}

	function navigation($missing) {
		?>
<select id="adminer-theme-switcher" title="Dark theme" style="position: fixed; z-index: 10001; right: .5em; bottom: 2.5em; font: inherit; font-size: smaller; max-width: 9em;">
<?php foreach (self::THEMES as $key => $label): ?>
	<option value="<?php echo Adminer\h($key); ?>"><?php echo Adminer\h($label); ?></option>
<?php endforeach; ?>
</select>
<script<?php echo Adminer\nonce(); ?>>
(() => {
	const sel = document.getElementById('adminer-theme-switcher');
	sel.value = document.documentElement.getAttribute('data-theme') || 'tokyonight';
	sel.addEventListener('change', () => {
		cookie('adminer_theme=' + sel.value, 30);
		document.documentElement.setAttribute('data-theme', sel.value);
	});
})();
</script>
		<?php
	}

	protected $translations = array(
		'en' => array('' => 'Switch between multiple dark themes'),
		'vi' => array('' => 'Chọn giữa nhiều theme tối (Tokyo Night, Nord, GitHub Dark Dimmed, One Dark Pro, Dracula)'),
	);
}

return new AdminerThemeSwitcher();
