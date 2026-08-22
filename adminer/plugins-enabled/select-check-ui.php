<?php

/**
 * Modernize Select row action cell: icon edit + larger checkbox.
 *
 * Core still emits `<a class="edit">edit</a>` + checkbox; we restyle client-side
 * only on #table (including rows added via Load more).
 */
final class AdminerSelectCheckUi extends Adminer\Plugin {
	function head($dark = null) {
		if (!isset($_GET['select'])) {
			return;
		}
		?>
<style<?php echo Adminer\nonce(); ?>>
#table td.check,
#table thead td.check {
	width: 1%;
	white-space: nowrap;
	vertical-align: middle;
	text-align: right;
	padding: .3em .55em;
}

#table td.check a.edit,
#table td.check a.edit-icon {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 1.7em;
	height: 1.7em;
	margin-right: .35em;
	vertical-align: middle;
	border-radius: var(--radius-sm, 6px);
	color: var(--muted, #6b7280);
	text-decoration: none;
	background: transparent;
	transition: color .12s ease, background .12s ease;
}

#table td.check a.edit:hover,
#table td.check a.edit-icon:hover,
#table td.check a.edit:focus-visible,
#table td.check a.edit-icon:focus-visible {
	color: var(--accent, #3574f0);
	background: color-mix(in srgb, var(--accent, #3574f0) 14%, transparent);
	text-decoration: none;
	outline: none;
}

#table td.check a.edit-icon::before {
	content: "";
	display: block;
	width: 1em;
	height: 1em;
	background: currentColor;
	-webkit-mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3E%3Cpath fill-rule='evenodd' d='M11.013 1.427a1.75 1.75 0 0 1 2.474 0l1.086 1.086a1.75 1.75 0 0 1 0 2.474l-8.61 8.61c-.21.21-.47.364-.752.453l-2.868.892a.75.75 0 0 1-.921-.921l.892-2.868c.089-.282.243-.542.453-.752l8.61-8.61Zm1.414 1.06a.25.25 0 0 0-.354 0L10.823 3.74l1.44 1.44 1.25-1.25a.25.25 0 0 0 0-.354l-1.086-1.086ZM9.76 4.802l1.441 1.44L4.828 12.62a.25.25 0 0 1-.092.06l-1.85.575.575-1.85a.25.25 0 0 1 .06-.092L9.76 4.802Z'/%3E%3C/svg%3E") center / contain no-repeat;
	mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3E%3Cpath fill-rule='evenodd' d='M11.013 1.427a1.75 1.75 0 0 1 2.474 0l1.086 1.086a1.75 1.75 0 0 1 0 2.474l-8.61 8.61c-.21.21-.47.364-.752.453l-2.868.892a.75.75 0 0 1-.921-.921l.892-2.868c.089-.282.243-.542.453-.752l8.61-8.61Zm1.414 1.06a.25.25 0 0 0-.354 0L10.823 3.74l1.44 1.44 1.25-1.25a.25.25 0 0 0 0-.354l-1.086-1.086ZM9.76 4.802l1.441 1.44L4.828 12.62a.25.25 0 0 1-.092.06l-1.85.575.575-1.85a.25.25 0 0 1 .06-.092L9.76 4.802Z'/%3E%3C/svg%3E") center / contain no-repeat;
}

#table td.check input[type="checkbox"],
#table thead td.check input[type="checkbox"] {
	appearance: none;
	-webkit-appearance: none;
	width: 1.15rem;
	height: 1.15rem;
	margin: 0;
	box-sizing: border-box;
	border: 1.5px solid color-mix(in srgb, var(--muted, #6b7280) 55%, var(--border, #dde1e6));
	border-radius: 4px;
	background: var(--bg, #fff);
	accent-color: var(--accent, #3574f0);
	cursor: pointer;
	vertical-align: middle;
	transition: border-color .12s ease, background .12s ease, box-shadow .12s ease;
}

#table td.check input[type="checkbox"]:hover,
#table thead td.check input[type="checkbox"]:hover {
	border-color: var(--accent, #3574f0);
}

#table td.check input[type="checkbox"]:focus-visible,
#table thead td.check input[type="checkbox"]:focus-visible {
	outline: 2px solid color-mix(in srgb, var(--accent, #3574f0) 45%, transparent);
	outline-offset: 1px;
}

#table td.check input[type="checkbox"]:checked,
#table thead td.check input[type="checkbox"]:checked {
	border-color: var(--accent, #3574f0);
	border-radius: 4px;
	background-color: var(--accent, #3574f0);
	background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3E%3Cpath fill='none' stroke='%23fff' stroke-width='2.2' stroke-linecap='round' stroke-linejoin='round' d='M3.5 8.5l3 3 6-6'/%3E%3C/svg%3E");
	background-size: 78% 78%;
	background-position: center;
	background-repeat: no-repeat;
}

</style>
<script<?php echo Adminer\nonce(); ?>>
(() => {
	const polish = (root) => {
		const scope = root || document;
		for (const link of scope.querySelectorAll('#table td.check a.edit:not(.edit-icon)')) {
			const label = (link.textContent || 'Edit').trim() || 'Edit';
			link.textContent = '';
			link.classList.add('edit-icon');
			link.title = label;
			link.setAttribute('aria-label', label);
		}
	};

	const boot = () => {
		const table = document.getElementById('table');
		if (!table) {
			return;
		}
		polish(table);
		const tbody = table.querySelector('tbody');
		if (!tbody) {
			return;
		}
		new MutationObserver(() => polish(table)).observe(tbody, { childList: true });
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
		'en' => array('' => 'Icon edit link and larger checkboxes on Select rows'),
		'vi' => array('' => 'Icon edit và checkbox lớn hơn trên Select'),
	);
}

return new AdminerSelectCheckUi();
