<?php

/**
 * Keyboard shortcuts for Select / Edit actions (modifier chords only).
 *
 * All Select actions use Alt+Shift+Key so single-key typos never fire.
 * Save also keeps Ctrl/Cmd+S (standard). Uses e.code (layout-independent).
 * Clicks Adminer's own buttons — confirm dialogs / disabled states stay intact.
 *
 * Select: Alt+Shift+E/D/C/S/N/M/F/A/X/R/H
 * Edit:   Ctrl/Cmd+S  |  Alt+Shift+S
 * Also injects a Reset button next to Action → Select. */
final class AdminerSelectShortcuts extends Adminer\Plugin {
	function head($dark = null) {
		$onSelect = isset($_GET['select']);
		$onEdit = isset($_GET['edit']);
		if (!$onSelect && !$onEdit) {
			return;
		}
		?>
<style<?php echo Adminer\nonce(); ?>>
#select-shortcuts-help {
	position: fixed;
	z-index: 10001;
	/* Leave room for dark-switcher's ☀ at bottom-right (.5em) */
	right: 3.25em;
	bottom: .5em;
	min-width: 16em;
	max-width: 26em;
	padding: .55em .75em .65em;
	border: 1px solid #888;
	background: var(--bg, #fff);
	color: var(--color, inherit);
	box-shadow: 0 2px 8px rgba(0,0,0,.2);
	font: inherit;
	font-size: smaller;
	line-height: 1.35;
}
#select-shortcuts-help kbd {
	display: inline-block;
	min-width: 1.2em;
	padding: 0 .3em;
	margin-right: .35em;
	border: 1px solid #888;
	border-radius: 3px;
	font: inherit;
	font-weight: 600;
	text-align: center;
	white-space: nowrap;
}
#select-shortcuts-help .ssh-row {
	display: flex;
	gap: .5em;
	padding: .12em 0;
	align-items: baseline;
}
#select-shortcuts-help .ssh-row kbd {
	flex: 0 0 auto;
}
#select-shortcuts-help .ssh-head {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: .5em;
	margin-bottom: .35em;
}
#select-shortcuts-help .ssh-title {
	font-weight: 600;
}
#select-shortcuts-help .ssh-close {
	border: 0;
	background: transparent;
	color: inherit;
	font: inherit;
	font-size: 1.1em;
	line-height: 1;
	cursor: pointer;
	padding: 0 .2em;
	opacity: .7;
}
#select-shortcuts-help .ssh-close:hover {
	opacity: 1;
}
#select-shortcuts-help.ssh-collapsed {
	min-width: 0;
	padding: .35em .55em;
}
#select-shortcuts-help.ssh-collapsed .ssh-body {
	display: none;
}
#select-shortcuts-fab {
	position: fixed;
	z-index: 10001;
	/* Leave room for dark-switcher's ☀ at bottom-right (.5em) */
	right: 3.25em;
	bottom: .5em;
	padding: .35em .65em;
	border: 1px solid #888;
	background: var(--bg, #fff);
	color: var(--color, inherit);
	box-shadow: 0 2px 8px rgba(0,0,0,.2);
	font: inherit;
	font-size: smaller;
	cursor: pointer;
}
</style>
<script<?php echo Adminer\nonce(); ?>>
(() => {
	const HELP_ID = 'select-shortcuts-help';
	const FAB_ID = 'select-shortcuts-fab';
	const STORAGE_KEY = 'adminer_select_shortcuts_help';
	const ON_SELECT = <?php echo $onSelect ? 'true' : 'false'; ?>;
	const MOD_LABEL = 'Alt+Shift';

	const clickSubmit = (name) => {
		const btn = document.querySelector(`input[type="submit"][name="${name}"]`);
		if (!btn || btn.disabled) {
			return false;
		}
		btn.click();
		return true;
	};

	const clickSave = () => {
		const btn = document.getElementById('save')
			|| [...document.querySelectorAll('input[type="submit"]')].find((el) => el.value === 'Save');
		if (!btn || btn.disabled) {
			return false;
		}
		btn.click();
		return true;
	};

	const clickExport = () => {
		const btn = document.querySelector('input[type="submit"][name="export"]');
		if (!btn || btn.disabled) {
			return false;
		}
		btn.click();
		return true;
	};

	const goNewItem = () => {
		const link = [...document.querySelectorAll('a')].find((a) => a.textContent.trim() === 'New item');
		if (!link) {
			return false;
		}
		location.href = link.href;
		return true;
	};

	const goModify = () => {
		const link = [...document.querySelectorAll('fieldset legend a, fieldset a')].find((a) => {
			const t = a.textContent.trim();
			return t === 'Modify' || /^Modify\b/.test(t);
		});
		if (!link || !link.getAttribute('href')) {
			return false;
		}
		location.href = link.href;
		return true;
	};

	const focusSearch = () => {
		const fieldset = document.getElementById('fieldset-search');
		if (!fieldset) {
			return false;
		}
		fieldset.className = '';
		const val = fieldset.querySelector('[name$="[val]"]');
		if (val) {
			val.focus();
			val.select();
		}
		return true;
	};

	const toggleSelectAllPage = () => {
		const box = document.getElementById('all-page');
		if (!box) {
			return false;
		}
		box.click();
		return true;
	};

	const resetSelectFilters = () => {
		const reset = document.getElementById('select-reset');
		if (reset?.disabled) {
			return false;
		}
		const url = new URL(location.href);
		for (const key of [...url.searchParams.keys()]) {
			if (
				/^(where|order|desc|columns|fulltext|boolean)\[/.test(key)
				|| key === 'page'
				|| key === 'next'
				|| key === 'modify'
				|| key === 'limit'
				|| key === 'text_length'
			) {
				url.searchParams.delete(key);
			}
		}
		location.href = url.toString();
		return true;
	};

	const isSelectFiltersDirty = () => {
		const url = new URL(location.href);
		for (const key of url.searchParams.keys()) {
			if (
				/^(where|order|desc|columns|fulltext|boolean)\[/.test(key)
				|| key === 'page'
				|| key === 'next'
				|| key === 'modify'
				|| key === 'limit'
				|| key === 'text_length'
			) {
				return true;
			}
		}

		const form = document.getElementById('form');
		if (!form) {
			return false;
		}

		for (const el of form.querySelectorAll('input, select')) {
			const name = el.name || '';
			if (!name || name === 'token') {
				continue;
			}
			if (
				!/^(where|order|desc|columns|fulltext|boolean|limit|text_length)/.test(name)
			) {
				continue;
			}

			if (el.type === 'checkbox') {
				if (el.checked) {
					return true;
				}
				continue;
			}

			const def = el.getAttribute('data-default');
			if (def !== null) {
				if (String(el.value) !== String(def)) {
					return true;
				}
				continue;
			}

			// Empty default for search/sort/columns value fields
			if (el.value !== '' && el.value != null) {
				return true;
			}
		}
		return false;
	};

	const syncResetButton = () => {
		const reset = document.getElementById('select-reset');
		if (!reset) {
			return;
		}
		const dirty = isSelectFiltersDirty();
		reset.disabled = !dirty;
		reset.setAttribute('aria-disabled', dirty ? 'false' : 'true');
	};

	const injectResetButton = () => {
		if (!ON_SELECT || document.getElementById('select-reset')) {
			return;
		}
		const actionFs = [...document.querySelectorAll('#content #form > fieldset')].find((fs) => {
			const legend = fs.querySelector(':scope > legend');
			return legend && legend.textContent.trim() === 'Action';
		});
		if (!actionFs) {
			return;
		}
		// select-toolbar-icons.php may have already swapped this <input> for a
		// <button> so it can carry an inline (themeable) icon, so accept either.
		const selectBtn = actionFs.querySelector('input[type="submit"], button[type="submit"]');
		if (!selectBtn) {
			return;
		}
		const reset = document.createElement('button');
		reset.type = 'button';
		reset.id = 'select-reset';
		reset.textContent = 'Reset';
		reset.disabled = true;
		reset.addEventListener('click', (e) => {
			e.preventDefault();
			if (!reset.disabled) {
				resetSelectFilters();
			}
		});
		selectBtn.insertAdjacentElement('afterend', reset);

		const form = document.getElementById('form');
		if (form) {
			form.addEventListener('input', syncResetButton);
			form.addEventListener('change', syncResetButton);
		}
		syncResetButton();
	};

	const helpVisible = () => sessionStorage.getItem(STORAGE_KEY) !== 'hidden';

	const setHelpVisible = (visible) => {
		sessionStorage.setItem(STORAGE_KEY, visible ? 'shown' : 'hidden');
	};

	const removeFab = () => {
		document.getElementById(FAB_ID)?.remove();
	};

	const showFab = () => {
		if (document.getElementById(FAB_ID)) {
			return;
		}
		const fab = document.createElement('button');
		fab.type = 'button';
		fab.id = FAB_ID;
		fab.textContent = 'Shortcuts';
		fab.title = `${MOD_LABEL}+H`;
		fab.addEventListener('click', (e) => {
			e.preventDefault();
			setHelpVisible(true);
			showHelp();
		});
		document.body.appendChild(fab);
	};

	const closeHelp = () => {
		document.getElementById(HELP_ID)?.remove();
		setHelpVisible(false);
		showFab();
	};

	const showHelp = () => {
		removeFab();
		if (document.getElementById(HELP_ID)) {
			return;
		}
		const rows = ON_SELECT
			? `
			<div class="ssh-row"><kbd>${MOD_LABEL}+E</kbd><span>Edit selected</span></div>
			<div class="ssh-row"><kbd>${MOD_LABEL}+D</kbd><span>Delete selected</span></div>
			<div class="ssh-row"><kbd>${MOD_LABEL}+C</kbd><span>Clone selected</span></div>
			<div class="ssh-row"><kbd>${MOD_LABEL}+S</kbd><span>Save (Modify)</span></div>
			<div class="ssh-row"><kbd>${MOD_LABEL}+N</kbd><span>New item</span></div>
			<div class="ssh-row"><kbd>${MOD_LABEL}+M</kbd><span>Modify mode</span></div>
			<div class="ssh-row"><kbd>${MOD_LABEL}+F</kbd><span>Focus Search</span></div>
			<div class="ssh-row"><kbd>${MOD_LABEL}+A</kbd><span>Select all (page)</span></div>
			<div class="ssh-row"><kbd>${MOD_LABEL}+X</kbd><span>Export</span></div>
			<div class="ssh-row"><kbd>${MOD_LABEL}+Backspace</kbd><span>Reset filters</span></div>
			<div class="ssh-row"><kbd>${MOD_LABEL}+H</kbd><span>Ẩn / hiện bảng này</span></div>
			<div class="ssh-row"><kbd>Ctrl+S</kbd><span>Save (Edit / Modify)</span></div>
			`
			: `
			<div class="ssh-row"><kbd>Ctrl+S</kbd><span>Save</span></div>
			<div class="ssh-row"><kbd>${MOD_LABEL}+S</kbd><span>Save</span></div>
			<div class="ssh-row"><kbd>${MOD_LABEL}+H</kbd><span>Ẩn / hiện bảng này</span></div>
			`;

		const box = document.createElement('div');
		box.id = HELP_ID;
		box.setAttribute('role', 'complementary');
		box.setAttribute('aria-label', 'Keyboard shortcuts');
		box.innerHTML = `
			<div class="ssh-head">
				<div class="ssh-title">Phím tắt</div>
				<button type="button" class="ssh-close" title="Ẩn (Esc / ${MOD_LABEL}+H)" aria-label="Close">×</button>
			</div>
			<div class="ssh-body">${rows}</div>
		`;
		box.querySelector('.ssh-close').addEventListener('click', (e) => {
			e.preventDefault();
			closeHelp();
		});
		document.body.appendChild(box);
		setHelpVisible(true);
	};

	const toggleHelp = () => {
		if (document.getElementById(HELP_ID)) {
			closeHelp();
			return;
		}
		showHelp();
	};

	const annotateTitles = () => {
		const map = [
			['input[type="submit"][name="edit"]', `${MOD_LABEL}+E`],
			['input[type="submit"][name="clone"]', `${MOD_LABEL}+C`],
			['input[type="submit"][name="delete"]', `${MOD_LABEL}+D`],
			['#save', `${MOD_LABEL}+S / Ctrl+S`],
			['input[type="submit"][name="export"]', `${MOD_LABEL}+X`],
			['#select-reset', `${MOD_LABEL}+Backspace`],
		];
		for (const [sel, key] of map) {
			const el = document.querySelector(sel);
			if (!el) {
				continue;
			}
			const hint = `Shortcut: ${key}`;
			if (el.title.includes(hint)) {
				continue;
			}
			el.title = el.title ? `${el.title} · ${hint}` : hint;
		}
	};

	/** Alt+Shift+Key chord (no Ctrl/Meta) — hard to hit by accident. */
	const isActionChord = (e) => e.altKey && e.shiftKey && !e.ctrlKey && !e.metaKey;

	document.addEventListener('keydown', (e) => {
		if (e.key === 'Escape' && document.getElementById(HELP_ID)) {
			closeHelp();
			return;
		}

		const mod = e.ctrlKey || e.metaKey;

		// Ctrl/Cmd+S → Save (standard; blocks browser "Save page")
		if (mod && !e.altKey && !e.shiftKey && (e.code === 'KeyS' || e.key === 's' || e.key === 'S')) {
			if (clickSave()) {
				e.preventDefault();
			}
			return;
		}

		if (!isActionChord(e)) {
			return;
		}

		// Ignore when cell-filter menu is open (except help toggle)
		if (document.getElementById('cell-filter-menu') && e.code !== 'KeyH') {
			return;
		}

		let handled = false;

		if (e.code === 'KeyH') {
			toggleHelp();
			handled = true;
		} else if (ON_SELECT) {
			switch (e.code) {
				case 'KeyE':
					handled = clickSubmit('edit');
					break;
				case 'KeyD':
				case 'Delete':
					handled = clickSubmit('delete');
					break;
				case 'KeyC':
					handled = clickSubmit('clone');
					break;
				case 'KeyS':
					handled = clickSave();
					break;
				case 'KeyN':
					handled = goNewItem();
					break;
				case 'KeyM':
					handled = goModify();
					break;
				case 'KeyF':
					handled = focusSearch();
					break;
				case 'KeyA':
					handled = toggleSelectAllPage();
					break;
				case 'KeyX':
					handled = clickExport();
					break;
				case 'Backspace':
					handled = resetSelectFilters();
					break;
				default:
					break;
			}
		} else if (e.code === 'KeyS') {
			handled = clickSave();
		}

		if (handled) {
			e.preventDefault();
			e.stopPropagation();
		}
	}, true);

	const boot = () => {
		injectResetButton();
		annotateTitles();
		if (helpVisible()) {
			showHelp();
		} else {
			showFab();
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
		'en' => array('' => 'Keyboard shortcuts for Select/Edit actions'),
		'vi' => array('' => 'Phím tắt cho các thao tác Select/Edit'),
	);
}

return new AdminerSelectShortcuts();
