<?php

/**
 * Pretty-print JSON when a json/jsonb cell is opened for inline editing.
 *
 * Ctrl+click on a result cell makes Adminer swap the cell for
 * `<textarea rows="1">` holding the raw value. For a jsonb column that is one
 * very long unbroken line in a one-row-tall box - technically editable,
 * practically unreadable.
 *
 * This reformats that text to indented JSON, grows the box to fit, and sets a
 * monospace face. No DB access at all: the column types are read from the
 * grid's own header cells (Adminer already puts the declared type in
 * `th > a > span[title]`), so there is nothing to query and nothing to guard.
 *
 * Round-trip safety: the exact original string is kept, and on submit, if the
 * edited text parses to the *same* value, the original is restored verbatim.
 * That matters for `json` (as opposed to `jsonb`), where PostgreSQL preserves
 * the text you send - so merely opening a row to read it must not silently
 * rewrite its formatting. Deliberate edits are sent as typed.
 */
final class AdminerSelectJsonEdit extends Adminer\Plugin {
	function head($dark = null) {
		if (!isset($_GET['select'])) {
			return;
		}
		?>
<style<?php echo Adminer\nonce(); ?>>
#table td[id^="val["] textarea.sje-json {
	font-family: var(--mono-stack, ui-monospace, SFMono-Regular, Menlo, Consolas, monospace);
	font-size: 12px;
	line-height: 1.45;
	tab-size: 2;
	white-space: pre;
	overflow: auto;
	resize: vertical;
	min-height: 7em;
	max-height: 60vh;
	padding: 6px 8px;
	border-radius: var(--radius-sm, 6px);
}
#table td[id^="val["] textarea.sje-invalid {
	outline: 1px solid #e5534b;
	outline-offset: -1px;
}
</style>
<script<?php echo Adminer\nonce(); ?>>
(() => {
	const MAX_ROWS = 26;

	// head() runs before <body>, so #table does not exist yet - everything has
	// to wait for the DOM or the lookup below silently bails and nothing binds.
	const boot = () => {

	const grid = document.getElementById('table');
	if (!grid) {
		return;
	}

	/** column name => declared type, straight off the header row. */
	const types = new Map(
		[...grid.querySelectorAll('thead th[id^="th["]')].map((th) => [
			th.id.slice(3, -1),
			((th.querySelector('span[title]') || {}).title || '').toLowerCase(),
		])
	);

	const columnOf = (el) => {
		const td = el.closest('td[id^="val["]');
		if (!td) {
			return '';
		}
		const m = td.id.match(/\[([^\[\]]+)\]$/);
		return m ? m[1] : '';
	};

	const isJsonColumn = (el) => /\bjsonb?\b/.test(types.get(columnOf(el)) || '');

	/**
	 * Adminer inserts the editor EMPTY and assigns .value a moment later, so a
	 * MutationObserver sees `<textarea>` with no content yet. Retry briefly
	 * instead of giving up, or JSON formatting never runs at all.
	 */
	const formatWhenFilled = (area, tries = 0) => {
		if (area.dataset.sjeDone) {
			return;
		}
		if (!area.isConnected) {
			return;                       // editor was dismissed again
		}
		if (area.value && area.value.trim()) {
			format(area);
			return;
		}
		if (tries < 12) {                 // ~600ms, then assume it really is NULL
			setTimeout(() => formatWhenFilled(area, tries + 1), 50);
		}
	};

	const format = (area) => {
		if (area.dataset.sjeDone) {
			return;
		}
		const raw = area.value;
		if (!raw || !raw.trim()) {
			return;                       // NULL / empty: nothing to format
		}
		let parsed;
		try {
			parsed = JSON.parse(raw);
		} catch (_) {
			// Not valid JSON. Leave the text byte-for-byte alone - reformatting
			// something we cannot parse risks destroying it - but still make it
			// readable.
			area.dataset.sjeDone = '1';
			area.classList.add('sje-json');
			return;
		}
		const pretty = JSON.stringify(parsed, null, 2);
		area.dataset.sjeOriginal = raw;
		area.dataset.sjeDone = '1';
		area.value = pretty;
		area.classList.add('sje-json');
		area.rows = Math.min(MAX_ROWS, pretty.split('\n').length + 1);
		area.setAttribute('spellcheck', 'false');
		area.addEventListener('input', () => {
			let ok = true;
			try {
				JSON.parse(area.value);
			} catch (_) {
				ok = area.value.trim() === '';
			}
			area.classList.toggle('sje-invalid', !ok);
		});
	};

	const scan = (root) => {
		if (root.nodeType !== 1) {
			return;
		}
		const areas = root.matches && root.matches('textarea')
			? [root]
			: [...root.querySelectorAll('textarea')];
		for (const area of areas) {
			if (isJsonColumn(area)) {
				formatWhenFilled(area);
			}
		}
	};

	// Adminer builds the editor on demand, so watch for it rather than trying to
	// hook whichever click handler happens to create it.
	new MutationObserver((records) => {
		for (const rec of records) {
			for (const node of rec.addedNodes) {
				scan(node);
			}
		}
	}).observe(grid, { childList: true, subtree: true });

	scan(grid);   // anything already open

	/**
	 * Restore the byte-identical original when the edit was cosmetic only, so
	 * opening a row to read it never rewrites how the value is stored.
	 */
	const restoreUnchanged = () => {
		for (const area of grid.querySelectorAll('textarea.sje-json')) {
			const original = area.dataset.sjeOriginal;
			if (original === undefined) {
				continue;
			}
			try {
				if (JSON.stringify(JSON.parse(area.value)) === JSON.stringify(JSON.parse(original))) {
					area.value = original;
				}
			} catch (_) {
				/* unparseable: send as typed and let PostgreSQL object */
			}
		}
	};

	// The inline editor lives in the results form, which carries no id.
	const form = grid.closest('form');
	if (form) {
		form.addEventListener('submit', restoreUnchanged);
	}

	};   // end boot()

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
		'en' => array('' => 'Pretty-print JSON when editing a json/jsonb cell inline'),
		'vi' => array('' => 'Tự động format JSON khi sửa trực tiếp ô json/jsonb'),
	);
}

return new AdminerSelectJsonEdit();
