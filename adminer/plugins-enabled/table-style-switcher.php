<?php

/**
 * Switch table border style + color from the UI (cookie-persisted).
 *
 * Composes with theme-switcher / dark-switcher: those own palette colors;
 * this plugin only sets data-table-border-style / data-table-border-color on
 * <html>, which adminer.css maps to --table-border-style / --table-border-color.
 *
 * Restore runs in head() before paint (no flash). Selects sit left of the
 * dark-theme <select>, same bottom bar as Shortcuts + ☀.
 */
final class AdminerTableStyleSwitcher extends Adminer\Plugin {
	const STYLES = array(
		'solid' => 'Solid',
		'dashed' => 'Dashed',
		'dotted' => 'Dotted',
		'none' => 'None',
	);

	const COLORS = array(
		'theme' => 'Theme',
		'muted' => 'Muted',
		'accent' => 'Accent',
	);

	const COOKIE_STYLE = 'adminer_table_border_style';
	const COOKIE_COLOR = 'adminer_table_border_color';
	const DEFAULT_STYLE = 'solid';
	const DEFAULT_COLOR = 'muted';

	function head($dark = null) {
		$styles = json_encode(array_keys(self::STYLES));
		$colors = json_encode(array_keys(self::COLORS));
		$defStyle = json_encode(self::DEFAULT_STYLE);
		$defColor = json_encode(self::DEFAULT_COLOR);
		?>
<script<?php echo Adminer\nonce(); ?>>
(() => {
	const STYLES = <?php echo $styles; ?>;
	const COLORS = <?php echo $colors; ?>;
	const read = (name, allowed, fallback) => {
		const m = document.cookie.match(new RegExp('(?:^|; )' + name + '=([\\w-]+)'));
		return (m && allowed.includes(m[1])) ? m[1] : fallback;
	};
	const root = document.documentElement;
	root.setAttribute('data-table-border-style', read('adminer_table_border_style', STYLES, <?php echo $defStyle; ?>));
	root.setAttribute('data-table-border-color', read('adminer_table_border_color', COLORS, <?php echo $defColor; ?>));
})();
</script>
		<?php
	}

	function navigation($missing) {
		$selCss = 'position: fixed; z-index: 10001; bottom: .5em; font: inherit; font-size: smaller; max-width: 6.5em;';
		?>
<select id="adminer-table-border-style" title="Table border style" style="<?php echo Adminer\h($selCss); ?> right: 19.5em;">
<?php foreach (self::STYLES as $key => $label): ?>
	<option value="<?php echo Adminer\h($key); ?>"><?php echo Adminer\h($label); ?></option>
<?php endforeach; ?>
</select>
<select id="adminer-table-border-color" title="Table border color" style="<?php echo Adminer\h($selCss); ?> right: 26.5em;">
<?php foreach (self::COLORS as $key => $label): ?>
	<option value="<?php echo Adminer\h($key); ?>"><?php echo Adminer\h($label); ?></option>
<?php endforeach; ?>
</select>
<script<?php echo Adminer\nonce(); ?>>
(() => {
	const root = document.documentElement;
	const bind = (id, attr, cookieName) => {
		const sel = document.getElementById(id);
		sel.value = root.getAttribute(attr) || sel.options[0].value;
		sel.addEventListener('change', () => {
			cookie(cookieName + '=' + sel.value, 30);
			root.setAttribute(attr, sel.value);
		});
	};
	bind('adminer-table-border-style', 'data-table-border-style', 'adminer_table_border_style');
	bind('adminer-table-border-color', 'data-table-border-color', 'adminer_table_border_color');
})();
</script>
		<?php
	}

	protected $translations = array(
		'en' => array('' => 'Switch table border style and color'),
		'vi' => array('' => 'Chọn kiểu và màu khung bảng'),
	);
}

return new AdminerTableStyleSwitcher();
