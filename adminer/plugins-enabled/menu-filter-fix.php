<?php

/**
 * Make the sidebar table Filter work alongside menu-links.php.
 *
 * Upstream's tables-filter plugin assumes Adminer's DEFAULT sidebar markup,
 * where every table <li> holds two links (a short "select" link, then the
 * table name):
 *
 *     a = qsa('a', table)[1];        // the second <a>
 *     text = a.innerHTML.trim();     // -> throws when there is only one
 *
 * We run AdminerMenuLinks('select'), which renders a SINGLE <a> per row so
 * that clicking the table name opens Select data. So `[1]` is undefined,
 * `a.innerHTML` throws on the very first table, and the filter silently does
 * nothing at all - typing in the box left the full list on screen.
 *
 * Rather than give up the one-link sidebar, use the escape hatch already in
 * upstream's own code: when a <li> already carries `data-table-name` it takes
 * a different branch that resolves the link via `a[data-link="main"]` and
 * never looks at index [1]. Seeding those two attributes makes the stock
 * filter work unmodified.
 *
 * Kept in its own file so it is obvious this exists only to reconcile
 * tables-filter with menu-links, and can be dropped if either one changes.
 */
final class AdminerMenuFilterFix extends Adminer\Plugin {
	function head($dark = null) {
		?>
<script<?php echo Adminer\nonce(); ?>>
(() => {
	const seed = () => {
		const list = document.getElementById('tables');
		if (!list) {
			return false;
		}
		let seeded = 0;
		for (const li of list.querySelectorAll('li')) {
			const links = li.querySelectorAll('a');
			if (!links.length) {
				continue;
			}
			// Match upstream's expectation: with the default two-link sidebar the
			// table name is the SECOND anchor; with menu-links('select') there is
			// only one. Picking by count keeps this correct either way, so
			// changing the menu-links mode later cannot silently re-break it.
			const main = links.length > 1 ? links[1] : links[0];
			const name = (main.textContent || '').trim();
			if (!name) {
				continue;
			}
			if (li.getAttribute('data-table-name') === null) {
				li.setAttribute('data-table-name', name);
			}
			main.setAttribute('data-link', 'main');
			seeded++;
		}
		return seeded > 0;
	};

	const reapply = () => {
		const input = document.getElementById('filter-field');
		if (!input || typeof tablesFilter !== 'function') {
			return;
		}
		const value = input.value;
		if (value === '') {
			return;
		}
		// tablesFilter() early-returns when the value matches what it last saw,
		// and it records that value BEFORE the line that used to throw - so after
		// a failed run the filter stays wedged until the text changes. Bounce the
		// value through '' to clear that cached state, then re-filter for real.
		try {
			input.value = '';
			tablesFilter();
			input.value = value;
			tablesFilter();
		} catch (e) {
			input.value = value;
		}
	};

	const boot = () => {
		if (seed()) {
			reapply();
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
		'en' => array('' => 'Fix the sidebar table filter for the single-link menu'),
		'vi' => array('' => 'Sửa bộ lọc bảng ở sidebar cho menu một liên kết'),
	);
}

return new AdminerMenuFilterFix();
