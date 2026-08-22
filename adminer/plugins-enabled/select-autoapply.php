<?php

/**
 * Auto-apply the Select query form.
 *
 * Adminer's select page normally needs an explicit click on "Select". This
 * applies the query as soon as you have actually decided something:
 *
 *   commit actions (immediate)  column change, operator change, sort change,
 *                               desc toggle, limit, text length
 *   typing a value (debounced)  600 ms after the last keystroke
 *   Enter                       native form submit, debounce cancelled
 *
 * Every apply is a real navigation + SQL query, so the whole point of this
 * file is knowing when NOT to fire:
 *
 *   - never while a suggestion/date portal is open (you are mid-choice, and
 *     reloading would tear the portal out from under the pointer),
 *   - never on a column/operator change whose row still has no value - that
 *     is a half-built condition and Adminer would just drop it. This also
 *     stops Adminer's own selectAddRow() (which fires `change` on the column
 *     select when it appends a fresh empty row) from triggering a reload,
 *   - never while an IME composition is in progress. This matters here:
 *     typing Vietnamese produces a stream of composition events, and firing
 *     mid-composition would submit a half-formed word and eat the rest,
 *   - never when nothing actually changed since the last apply.
 *
 * Because an apply is a page load, focus and caret are saved to
 * sessionStorage first and restored afterwards - otherwise auto-apply would
 * fight the user, throwing them out of the field they are still typing in.
 */
final class AdminerSelectAutoApply extends Adminer\Plugin {
	/** Retry delay used when a picker is open at commit time (see apply()). */
	private const DEBOUNCE_MS = 600;

	function head($dark = null) {
		if (!isset($_GET['select'])) {
			return;
		}
		?>
<script<?php echo Adminer\nonce(); ?>>
(() => {
	const DEBOUNCE_MS = <?php echo self::DEBOUNCE_MS; ?>;
	const STATE_KEY = 'adminer_autoapply_focus';
	// Mid-choice UI that a navigation would rip out from under the pointer:
	// the column chooser (#ss-portal), the date picker (.ssf-dt) and the
	// right-click cell menu. Deliberately NOT the value suggestion list
	// (.ssf-combo): that one opens on focus and stays open the whole time you
	// are typing, so treating it as "mid-choice" would mean debounced
	// auto-apply never fired for text columns at all - i.e. the main case.
	const PORTAL_SEL = '#ss-portal, .ssf-dt, #cell-filter-menu';

	// head() runs before the document body exists, so #form is not there yet.
	// Everything below therefore lives in boot(), called once the DOM is ready.
	const boot = () => {

	const form = document.getElementById('form');
	if (!form) {
		return;
	}

	let timer = null;
	let composing = false;
	let submitting = false;

	const portalOpen = () => !!document.querySelector(PORTAL_SEL);

	const cancel = () => {
		if (timer) {
			clearTimeout(timer);
			timer = null;
		}
	};

	/** Signature of everything that affects the query, to detect real changes. */
	const signature = () => {
		const parts = [];
		for (const el of form.querySelectorAll('select, input')) {
			const name = el.name || '';
			if (!name || name === 'token') {
				continue;
			}
			if (!/^(where|order|desc|columns|fulltext|limit|text_length)/.test(name)) {
				continue;
			}
			parts.push(name + '=' + (el.type === 'checkbox' ? (el.checked ? 1 : 0) : el.value));
		}
		return parts.join('&');
	};

	let lastSignature = signature();

	const rowOf = (el) => el.closest('#fieldset-search > div');

	/** A search row is worth submitting once it has a value, or needs none. */
	const rowReady = (row) => {
		if (!row) {
			return true;                    // sort / limit / text length
		}
		const op = row.querySelector('select[name$="[op]"]')?.value || '';
		if (/^IS (NOT )?NULL$/.test(op)) {
			return true;                    // these take no value
		}
		return (row.querySelector('input[name$="[val]"]')?.value || '').trim() !== '';
	};

	const rememberFocus = () => {
		const el = document.activeElement;
		if (!el || !el.name || !form.contains(el)) {
			sessionStorage.removeItem(STATE_KEY);
			return;
		}
		let start = null;
		let end = null;
		try {
			start = el.selectionStart;
			end = el.selectionEnd;
		} catch (_) {
			/* selection not supported on this control */
		}
		sessionStorage.setItem(STATE_KEY, JSON.stringify({
			name: el.name,
			start: start,
			end: end,
			scrollY: window.scrollY,
		}));
	};

	const restoreFocus = () => {
		let state = null;
		try {
			state = JSON.parse(sessionStorage.getItem(STATE_KEY) || 'null');
		} catch (_) {
			state = null;
		}
		sessionStorage.removeItem(STATE_KEY);
		if (!state || !state.name) {
			return;
		}
		const el = form.querySelector('[name="' + CSS.escape(state.name) + '"]');
		if (!el) {
			return;
		}
		try {
			el.focus({ preventScroll: true });
			if (state.start !== null && state.end !== null && 'setSelectionRange' in el) {
				el.setSelectionRange(state.start, state.end);
			}
		} catch (_) {
			/* not a text control */
		}
		if (typeof state.scrollY === 'number') {
			window.scrollTo({ top: state.scrollY });
		}
	};

	let retries = 0;
	const MAX_RETRIES = 12;              // ~3.6s of waiting, then give up

	const apply = () => {
		if (submitting || composing) {
			cancel();
			return;
		}
		// A picker is open, so navigating now would yank it away mid-choice.
		// Re-arm instead of dropping the request: this path is hit every time
		// the date picker writes a value, because applyValue() fires `input`
		// (which arms the debounce) and Adminer's selectFirstChange() turns the
		// same event into a `change` that lands here while the picker is still
		// on screen. Plain `cancel(); return;` silently threw the apply away.
		if (portalOpen()) {
			cancel();
			if (retries++ < MAX_RETRIES) {
				timer = setTimeout(apply, 300);
			}
			return;
		}
		cancel();
		retries = 0;
		const now = signature();
		if (now === lastSignature) {
			return;                         // nothing actually changed
		}
		lastSignature = now;
		submitting = true;
		// Safety valve: if the submit is blocked (constraint validation, another
		// plugin cancelling it), clear the flag so auto-apply is not wedged off
		// for the rest of the page's life.
		setTimeout(() => { submitting = false; }, 2000);
		rememberFocus();
		// requestSubmit() runs validation and fires `submit`, unlike submit().
		if (typeof form.requestSubmit === 'function') {
			form.requestSubmit();
		} else {
			form.submit();
		}
	};

	/**
	 * Last value seen for each control, so we can tell a real choice from a
	 * synthetic `change`.
	 *
	 * This matters: Adminer's value input carries data-oninput="selectFirstChange()",
	 * and that handler does `fire(this.parentNode.firstChild, 'change')` - i.e.
	 * EVERY KEYSTROKE dispatches a `change` on the column <select>. Without this
	 * check, typing looked exactly like "the user picked a column" and applied
	 * the query per character, which is the lag we were asked to remove.
	 * Comparing the control's own value is also why the searchable-dropdown
	 * portal still commits correctly: there the value genuinely changes.
	 */
	const lastValues = new Map();
	const readValue = (el) => (el.type === 'checkbox' || el.type === 'radio')
		? String(el.checked)
		: el.value;

	for (const el of form.querySelectorAll('select, input')) {
		if (el.name) {
			lastValues.set(el.name, readValue(el));
		}
	}

	const controlActuallyChanged = (el) => {
		if (!el.name) {
			return false;
		}
		const now = readValue(el);
		const changed = lastValues.get(el.name) !== now;
		lastValues.set(el.name, now);
		return changed;
	};

	// ---- commit actions: apply immediately -------------------------------
	form.addEventListener('change', (e) => {
		const el = e.target;
		const name = (el && el.name) || '';
		if (!name) {
			return;
		}
		// The value box never commits on change/blur - only Enter does. Applying
		// on blur would fire a query every time focus left a half-typed field.
		if (/^where\[\d+\]\[val\]$/.test(name)) {
			return;
		}
		if (!controlActuallyChanged(el)) {
			return;                         // synthetic echo of a keystroke
		}
		if (/^(where|order|columns)/.test(name) || name === 'desc' || /^desc\[/.test(name)) {
			if (rowReady(rowOf(el))) {
				apply();
			}
			return;
		}
		if (name === 'limit' || name === 'text_length') {
			apply();
		}
	});

	// ---- typing does NOT apply ------------------------------------------
	// Deliberately no debounced submit while typing. Each apply is a real
	// navigation + SQL query, so firing one mid-word made the page feel
	// laggy and stole the caret repeatedly. Typing only ever arms nothing;
	// you commit with Enter, or by choosing something (column, operator,
	// a date from the picker, sort, limit, text length).
	//
	// IME is still tracked so a composition in progress can never be caught
	// by a commit that lands mid-word - typing Vietnamese produces a stream
	// of composition events and submitting inside one eats the rest.
	form.addEventListener('compositionstart', () => {
		composing = true;
		cancel();
	});
	form.addEventListener('compositionend', () => {
		composing = false;
	});

	// ---- Enter: let the native submit win, drop any pending timer ---------
	form.addEventListener('keydown', (e) => {
		if (e.key === 'Enter') {
			cancel();
			rememberFocus();
			submitting = true;
		}
		if (e.key === 'Escape') {
			cancel();
		}
	});

	form.addEventListener('submit', () => {
		cancel();
		submitting = true;
	});

	// Clicking "Select" or "Reset" is an explicit apply - keep the caret too.
	form.addEventListener('click', (e) => {
		if (e.target && e.target.type === 'submit') {
			cancel();
			rememberFocus();
		}
	});

	restoreFocus();

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
		'en' => array('' => 'Apply the Select query automatically (on choice, and while typing)'),
		'vi' => array('' => 'Tự động áp dụng truy vấn Select (khi chọn, và khi đang gõ)'),
	);
}

return new AdminerSelectAutoApply();
