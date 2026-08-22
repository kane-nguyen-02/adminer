<?php

/**
 * Select-data row click: highlight only; checkbox click selects.
 *
 * Adminer's tableClick() toggles check[] on any row click. This plugin wraps
 * window.tableClick for #table only (Select results). Other checkable tables
 * (databases / tables list / dump) keep the stock behavior.
 *
 * Setting (cookie adminer_row_highlight):
 *   replace (default) — clicking a row clears previous .row-active
 *   multi             — keep multiple highlighted rows
 */
final class AdminerRowHighlight extends Adminer\Plugin {
	const MODES = array(
		'replace' => 'Replace',
		'multi' => 'Multi',
	);

	const COOKIE = 'adminer_row_highlight';
	const DEFAULT_MODE = 'replace';

	function head($dark = null) {
		if (!isset($_GET['select'])) {
			return;
		}
		$modes = json_encode(array_keys(self::MODES));
		$def = json_encode(self::DEFAULT_MODE);
		?>
<script<?php echo Adminer\nonce(); ?>>
(() => {
	const MODES = <?php echo $modes; ?>;
	const FALLBACK = <?php echo $def; ?>;
	const CLASS = 'row-active';

	const readMode = () => {
		const m = document.cookie.match(/(?:^|; )adminer_row_highlight=([\w-]+)/);
		return (m && MODES.includes(m[1])) ? m[1] : FALLBACK;
	};

	const setActive = (tr) => {
		if (!tr || !tr.parentElement || tr.parentElement.tagName !== 'TBODY') {
			return;
		}
		if (readMode() === 'replace') {
			for (const prev of document.querySelectorAll('#table tbody tr.' + CLASS)) {
				if (prev !== tr) {
					prev.classList.remove(CLASS);
				}
			}
		}
		tr.classList.add(CLASS);
	};

	const orig = window.tableClick;
	if (typeof orig !== 'function') {
		return;
	}

	window.tableClick = function (event) {
		const table = event && event.target && event.target.closest
			? event.target.closest('#table.checkable')
			: null;
		if (!table) {
			return orig.call(this, event);
		}

		const td = event.target.closest('td');
		let text;
		if (td && (text = td.dataset.text)) {
			if (selectClick.call(td, event, +text, td.dataset.warning)) {
				return;
			}
		}

		const hit = event.target.closest('tr, table, a, input, textarea');
		if (hit && !hit.matches('tr')) {
			if (hit.type !== 'checkbox') {
				return;
			}
			checkboxClick.call(hit, event);
			const box = hit;
			const row = box.closest('tr');
			trCheck(box);
			if (box.name === 'check[]') {
				if (box.form && box.form['all']) {
					box.form['all'].checked = false;
				}
				formUncheck('all-page');
			}
			if (row) {
				setActive(row);
			}
			return;
		}

		if (!hit || !hit.matches('tr') || hit.parentElement.tagName !== 'TBODY') {
			return;
		}

		if (event.type === 'dblclick' || getSelection().isCollapsed) {
			setActive(hit);
		}
	};
})();
</script>
		<?php
	}

	function navigation($missing) {
		if (!isset($_GET['select'])) {
			return;
		}
		$selCss = 'position: fixed; z-index: 10001; bottom: .5em; font: inherit; font-size: smaller; max-width: 7.5em;';
		?>
<select id="adminer-row-highlight" title="Row highlight mode" style="<?php echo Adminer\h($selCss); ?> right: 33.5em;">
<?php foreach (self::MODES as $key => $label): ?>
	<option value="<?php echo Adminer\h($key); ?>"><?php echo Adminer\h($label); ?></option>
<?php endforeach; ?>
</select>
<script<?php echo Adminer\nonce(); ?>>
(() => {
	const MODES = <?php echo json_encode(array_keys(self::MODES)); ?>;
	const FALLBACK = <?php echo json_encode(self::DEFAULT_MODE); ?>;
	const sel = document.getElementById('adminer-row-highlight');
	const m = document.cookie.match(/(?:^|; )adminer_row_highlight=([\w-]+)/);
	sel.value = (m && MODES.includes(m[1])) ? m[1] : FALLBACK;
	sel.addEventListener('change', () => {
		cookie('adminer_row_highlight=' + sel.value, 30);
	});
})();
</script>
		<?php
	}

	protected $translations = array(
		'en' => array('' => 'Highlight Select rows on click; checkbox selects'),
		'vi' => array('' => 'Click hàng chỉ highlight; tick checkbox mới chọn'),
	);
}

return new AdminerRowHighlight();
