<?php

/**
 * Add a blank-header row-number column on Select result tables.
 *
 * Inserted after the checkbox column (not a real DB column). Numbers are
 * page*limit + index. Client-side only — export/SQL unchanged.
 */
final class AdminerRowNumColumn extends Adminer\Plugin {
	function head($dark = null) {
		if (!isset($_GET['select'])) {
			return;
		}
		?>
<style<?php echo Adminer\nonce(); ?>>
#table th.row-num-h,
#table td.row-num {
	width: 1%;
	min-width: 2.25em;
	padding-left: .4em;
	padding-right: .4em;
	text-align: right;
	vertical-align: middle;
	color: var(--muted, #888);
	font-variant-numeric: tabular-nums;
	user-select: none;
	white-space: nowrap;
}
#table th.row-num-h {
	/* Keep header empty — title attribute only for a11y/hover */
	font-weight: normal;
}
#table td.row-num {
	background: var(--dim, transparent);
}
</style>
<script<?php echo Adminer\nonce(); ?>>
(() => {
	const pageIndex = () => {
		const p = new URL(location.href).searchParams.get('page');
		const n = Number(p);
		return Number.isFinite(n) && n > 0 ? n : 0;
	};

	const pageLimit = () => {
		const fromForm = document.querySelector('#form input[name="limit"]');
		if (fromForm && fromForm.value !== '') {
			const n = Number(fromForm.value);
			if (Number.isFinite(n) && n >= 0) {
				return n;
			}
		}
		const fromUrl = Number(new URL(location.href).searchParams.get('limit'));
		if (Number.isFinite(fromUrl) && fromUrl >= 0) {
			return fromUrl;
		}
		return 50;
	};

	const ensureHeader = (theadRow) => {
		if (theadRow.querySelector(':scope > .row-num-h')) {
			return;
		}
		const first = theadRow.firstElementChild;
		if (!first) {
			return;
		}
		const th = document.createElement('th');
		th.className = 'row-num-h';
		th.title = 'Row';
		th.setAttribute('aria-label', 'Row number');
		first.insertAdjacentElement('afterend', th);
	};

	const syncRows = (tbody, start) => {
		const rows = [...tbody.querySelectorAll(':scope > tr')];
		rows.forEach((tr, i) => {
			let cell = tr.querySelector(':scope > td.row-num');
			if (!cell) {
				const first = tr.firstElementChild;
				if (!first) {
					return;
				}
				cell = document.createElement('td');
				cell.className = 'row-num';
				first.insertAdjacentElement('afterend', cell);
			}
			cell.textContent = String(start + i + 1);
		});
	};

	const apply = () => {
		const table = document.getElementById('table');
		if (!table) {
			return;
		}
		const theadRow = table.querySelector('thead tr');
		const tbody = table.querySelector('tbody');
		if (!theadRow || !tbody) {
			return;
		}
		ensureHeader(theadRow);
		syncRows(tbody, pageIndex() * pageLimit());
	};

	const boot = () => {
		apply();
		const table = document.getElementById('table');
		const tbody = table && table.querySelector('tbody');
		if (!tbody) {
			return;
		}
		const obs = new MutationObserver(() => {
			apply();
		});
		obs.observe(tbody, { childList: true });
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
		'en' => array('' => 'Blank-header row numbers on Select results'),
		'vi' => array('' => 'Cột số thứ tự (header trống) trên kết quả Select'),
	);
}

return new AdminerRowNumColumn();
