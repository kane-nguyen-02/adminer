<?php

/**
 * Highlight the row(s) just edited inline, after the save reloads the page.
 *
 * Inline editing on the Select page saves by submitting the results form, which
 * reloads the whole grid - so the row you just changed becomes impossible to
 * pick out again. This marks it the way a click does (the .row-active class from
 * row-highlight.php / adminer.css) so it stands out after the reload.
 *
 * Deliberately type-agnostic. It does not care HOW a cell was edited - Adminer's
 * native <input>/<textarea> for text, numbers, dates, uuids, json, or the
 * enum/bool <select> from select-inline-choice.php. All of them end the same
 * way: on the results form's submit, every edited cell is a control living
 * inside its `td[id^="val["]`. Reading them at submit time captures every edited
 * row, of any type, across however many cells were changed in one save - so
 * there is nothing to query and no per-type logic.
 *
 * The row identity is the `where` token shared by every cell of a row (the cell
 * id minus its "val[" prefix and "][column]" suffix). It is built from the
 * row's primary key, so it still matches the same row after the reload.
 */
final class AdminerSelectEditHighlight extends Adminer\Plugin {
	function head($dark = null) {
		if (!isset($_GET['select'])) {
			return;
		}
		?>
<script<?php echo Adminer\nonce(); ?>>
(() => {
	const STORE_KEY = 'adminer_edited_rows';
	const ACTIVE_CLASS = 'row-active';        // same class row-highlight.php uses

	const boot = () => {
		const grid = document.getElementById('table');
		if (!grid) {
			return;
		}

		// The per-row key: cell id without the leading "val[" and trailing
		// "][column]". Shared by every cell of a row, stable across the reload.
		const rowKeyOf = (td) => td.id.replace(/^val\[/, '').replace(/\]\[[^\[\]]+\]$/, '');

		// --- remember every edited row at submit time -----------------------
		const form = grid.closest('form');
		if (form) {
			form.addEventListener('submit', () => {
				const keys = new Set();
				// A value cell holding a control is a cell Adminer opened for
				// editing - whatever its data type. Disabled editors are excluded
				// from the POST (select-inline-choice disables the cells it is not
				// committing), so skip them: only rows that are actually saved get
				// highlighted.
				for (const ed of grid.querySelectorAll('td[id^="val["] input:not([disabled]), td[id^="val["] textarea:not([disabled]), td[id^="val["] select:not([disabled])')) {
					const td = ed.closest('td[id^="val["]');
					if (td) {
						keys.add(rowKeyOf(td));
					}
				}
				if (keys.size) {
					try {
						sessionStorage.setItem(STORE_KEY, JSON.stringify([...keys]));
					} catch (_) {
						/* private mode / storage disabled: just skip the highlight */
					}
				}
			});
		}

		// --- re-highlight those rows after the reload -----------------------
		let stored = null;
		try {
			stored = JSON.parse(sessionStorage.getItem(STORE_KEY) || 'null');
			sessionStorage.removeItem(STORE_KEY);
		} catch (_) {
			stored = null;
		}
		if (!Array.isArray(stored) || !stored.length) {
			return;
		}
		// A failed save does NOT redirect: Adminer re-renders this same page with
		// a <div class="error"> above the grid. Highlighting then would mark the
		// row as saved when the write was actually rejected, so bail on any error.
		// (A successful save redirects to a clean page, shown with .message.)
		if (document.querySelector('.error')) {
			return;
		}
		const wanted = new Set(stored);
		let first = null;
		for (const td of grid.querySelectorAll('td[id^="val["]')) {
			if (!wanted.has(rowKeyOf(td))) {
				continue;
			}
			const tr = td.closest('tr');
			if (tr && !tr.classList.contains(ACTIVE_CLASS)) {
				tr.classList.add(ACTIVE_CLASS);
				// Let row-inspector and friends react as if it were clicked.
				tr.dispatchEvent(new CustomEvent('adminer-row-active', { bubbles: true }));
				if (!first) {
					first = tr;
				}
			}
		}
		if (first) {
			first.scrollIntoView({ block: 'center', behavior: 'smooth' });
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
		'en' => array('' => 'Highlight the row you just edited inline after the save reload'),
		'vi' => array('' => 'Tô sáng dòng vừa sửa trực tiếp sau khi lưu và tải lại trang'),
	);
}

return new AdminerSelectEditHighlight();
