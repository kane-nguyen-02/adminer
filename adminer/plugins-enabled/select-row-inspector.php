<?php

/**
 * Row inspector for Select results.
 *
 * Highlighted rows (.row-active) open a side panel. Checkbox tick stays for bulk Action.
 * Cookie adminer_row_inspector: on (default) | off — off uses normal Adminer only.
 */
final class AdminerSelectRowInspector extends Adminer\Plugin {
	const COOKIE = 'adminer_row_inspector';
	const DEFAULT_ENABLED = 'off';   // opt-in: the panel must not appear unasked

	function head($dark = null) {
		if (!isset($_GET['select'])) {
			return;
		}
		$def = json_encode(self::DEFAULT_ENABLED);
		?>
<script<?php echo Adminer\nonce(); ?>>
(() => {
	const readEnabled = () => {
		const m = document.cookie.match(/(?:^|; )adminer_row_inspector=(on|off)/);
		return m ? m[1] : <?php echo $def; ?>;
	};
	document.documentElement.dataset.rowInspector = readEnabled();
})();
</script>
		<?php
		if ($this->isEnabledFromCookie() === false) {
			return;
		}
		$this->renderAssets();
	}

	function navigation($missing) {
		if (!isset($_GET['select'])) {
			return;
		}
		$selCss = 'position: fixed; z-index: 10001; bottom: .5em; font: inherit; font-size: smaller; max-width: 6.5em;';
		?>
<select id="adminer-row-inspector" title="Row panel" style="<?php echo Adminer\h($selCss); ?> right: 41em;">
	<option value="on">Panel on</option>
	<option value="off">Panel off</option>
</select>
<script<?php echo Adminer\nonce(); ?>>
(() => {
	const sel = document.getElementById('adminer-row-inspector');
	const FALLBACK = <?php echo json_encode(self::DEFAULT_ENABLED); ?>;
	const m = document.cookie.match(/(?:^|; )adminer_row_inspector=(on|off)/);
	sel.value = (m && (m[1] === 'on' || m[1] === 'off')) ? m[1] : FALLBACK;
	sel.addEventListener('change', () => {
		cookie('adminer_row_inspector=' + sel.value, 30);
		document.documentElement.dataset.rowInspector = sel.value;
		location.reload();
	});
})();
</script>
		<?php
	}

	private function isEnabledFromCookie(): bool {
		if (!isset($_COOKIE[self::COOKIE])) {
			return self::DEFAULT_ENABLED === 'on';
		}
		return $_COOKIE[self::COOKIE] === 'on';
	}

	private function renderAssets(): void {
		?>
<style<?php echo Adminer\nonce(); ?>>
#table td[id^="val["] {
	font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
	font-size: 0.92em;
}

#table td[id^="val["] > i {
	font-style: normal;
	font-size: 0.85em;
	letter-spacing: 0.06em;
	color: var(--muted, #6b7280);
	border-bottom: 1px dotted var(--muted, #6b7280);
}

.sri-drawer {
	position: fixed;
	top: 0;
	right: 0;
	bottom: 0;
	left: auto;
	width: min(480px, 100vw);
	display: flex;
	flex-direction: column;
	background: var(--bg, #fff);
	border-left: 1px solid var(--border, #dde1e6);
	box-shadow: -16px 0 40px -20px rgba(0, 0, 0, 0.25);
	z-index: 10040;
	animation: sri-slide .16s ease-out;
}

@keyframes sri-slide {
	from { transform: translateX(16px); opacity: 0; }
}

@media (prefers-reduced-motion: reduce) {
	.sri-drawer { animation: none; }
}

.sri-drawer[hidden] { display: none !important; }

.sri-drawer-head {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	gap: 12px;
	padding: 12px 16px;
	border-bottom: 1px solid var(--border, #dde1e6);
}

.sri-drawer-title {
	display: block;
	font: 600 13px/1.4 ui-monospace, monospace;
	color: var(--fg, inherit);
}

.sri-drawer-key {
	display: block;
	margin-top: 2px;
	font: 11px/1.4 ui-monospace, monospace;
	color: var(--accent, #3574f0);
	word-break: break-all;
}

/* Same footprint as every other icon button, so the header control no longer
   reads as a tiny afterthought next to them. */
.sri-drawer-close {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 30px;
	height: 30px;
	flex: 0 0 auto;
	background: none;
	border: 1px solid transparent;
	cursor: pointer;
	color: var(--muted, #6b7280);
	padding: 0;
	border-radius: var(--radius-sm, 6px);
}

.sri-drawer-close:hover,
.sri-drawer-close:focus-visible {
	color: var(--accent, #3574f0);
	outline: none;
}

.sri-tabs {
	display: flex;
	align-items: center;
	gap: 4px;
	padding: 8px 12px;
	border-bottom: 1px solid var(--border, #dde1e6);
}

.sri-tabs button {
	background: none;
	border: 1px solid transparent;
	border-radius: var(--radius-sm, 6px);
	color: var(--muted, #6b7280);
	font: inherit;
	font-size: 0.85em;
	padding: 4px 10px;
	cursor: pointer;
}

.sri-tabs button:hover { color: var(--fg, inherit); }

.sri-tabs button.sri-on {
	color: var(--accent, #3574f0);
	border-color: var(--border, #dde1e6);
	background: var(--dim, #f1f3f5);
}

.sri-loading {
	margin-left: auto;
	font-size: 0.8em;
	color: var(--muted, #6b7280);
}

.sri-drawer-body {
	flex: 1 1 auto;
	overflow: auto;
	scrollbar-width: thin;
}

.sri-fields { margin: 0; padding: 4px 0; }

.sri-field {
	padding: 8px 16px;
	border-bottom: 1px solid color-mix(in srgb, var(--border, #dde1e6) 60%, transparent);
}

.sri-field:hover { background: var(--dim, #f1f3f5); }
.sri-field.sri-copied { background: color-mix(in srgb, var(--accent, #3574f0) 12%, var(--dim, #f1f3f5)); }

.sri-field dt {
	display: flex;
	align-items: center;
	gap: 8px;
	margin: 0 0 4px;
}

.sri-name {
	font: 600 11px/1.4 ui-monospace, monospace;
	color: var(--muted, #6b7280);
}

.sri-kind {
	font: 10px/1.4 ui-monospace, monospace;
	color: var(--muted, #6b7280);
	margin-left: auto;
	opacity: 0.85;
}

.sri-copy-btn {
	flex: 0 0 auto;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 1.6em;
	height: 1.6em;
	margin-left: .25em;
	padding: 0;
	border: 1px solid transparent;
	border-radius: var(--radius-sm, 6px);
	background: transparent;
	color: var(--muted, #6b7280);
	font: inherit;
	font-size: 0.95em;
	line-height: 1;
	cursor: pointer;
}

.sri-copy-btn:hover,
.sri-copy-btn:focus-visible {
	border-color: var(--border, #dde1e6);
	color: var(--accent, #3574f0);
	background: color-mix(in srgb, var(--accent, #3574f0) 10%, transparent);
	outline: none;
}

.sri-field dd {
	margin: 0;
	font: 12px/1.5 ui-monospace, monospace;
	color: var(--fg, inherit);
	word-break: break-word;
	white-space: pre-wrap;
}

.sri-null {
	font-style: normal;
	letter-spacing: 0.08em;
	color: var(--muted, #6b7280);
	border-bottom: 1px dotted var(--muted, #6b7280);
}

.sri-json {
	margin: 0;
	padding: 8px 10px;
	background: var(--dim, #f1f3f5);
	border: 1px solid var(--border, #dde1e6);
	border-radius: var(--radius-sm, 6px);
	font: 11.5px/1.5 ui-monospace, monospace;
	white-space: pre;
	overflow-x: auto;
}

.sri-json-all {
	border: 0;
	border-radius: 0;
	background: none;
	padding: 14px 16px;
}

.sri-json-view {
	display: flex;
	flex-direction: column;
	min-height: 100%;
}

.sri-json-toolbar {
	display: flex;
	justify-content: flex-end;
	padding: 8px 12px 0;
}

.sri-json-toolbar .sri-copy-btn {
	width: 2em;
	height: 2em;
}

.sri-fields-edit .sri-field dt .sri-copy-btn {
	margin-left: .25em;
}

.sri-row { border-bottom: 1px solid var(--border, #dde1e6); }

.sri-row > summary {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px 16px;
	font: 11px/1.4 ui-monospace, monospace;
	color: var(--accent, #3574f0);
	cursor: pointer;
	list-style: none;
	background: color-mix(in srgb, var(--dim, #f1f3f5) 70%, var(--bg, #fff));
	word-break: break-all;
}

.sri-row > summary::-webkit-details-marker { display: none; }

.sri-row-n {
	flex: 0 0 auto;
	min-width: 18px;
	text-align: center;
	border: 1px solid var(--border, #dde1e6);
	border-radius: 3px;
	color: var(--muted, #6b7280);
	font-size: 10px;
}

.sri-row .sri-field { padding-left: 22px; }

.sri-inline-form {
	padding: 0;
}

.sri-fields-edit .sri-field {
	cursor: default;
}

.sri-fields-edit .sri-field dd {
	display: flex;
	flex-direction: column;
	gap: 0;
}

.sri-fields-edit .sri-input {
	display: flex;
	flex-direction: column;
	gap: 6px;
	width: 100%;
}

.sri-fields-edit .sri-input > input:not([type="checkbox"]):not([type="radio"]),
.sri-fields-edit .sri-input > textarea,
.sri-fields-edit .sri-input > select {
	width: 100%;
	box-sizing: border-box;
	font: 13px/1.45 ui-monospace, monospace;
	padding: 9px 11px;
	border: 1px solid color-mix(in srgb, var(--border, #dde1e6) 85%, transparent);
	border-radius: 8px;
	background: color-mix(in srgb, var(--dim, #f1f3f5) 35%, var(--bg, #fff));
	color: var(--fg, inherit);
	transition: border-color .15s ease, box-shadow .15s ease, background .15s ease;
	appearance: none;
}

.sri-fields-edit .sri-input > select {
	padding-right: 30px;
	background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%236b7280' d='M3 4.5 6 7.5 9 4.5'/%3E%3C/svg%3E");
	background-repeat: no-repeat;
	background-position: right 10px center;
	cursor: pointer;
}

.sri-fields-edit .sri-input > input:not([type="checkbox"]):not([type="radio"]):hover,
.sri-fields-edit .sri-input > textarea:hover,
.sri-fields-edit .sri-input > select:hover {
	border-color: color-mix(in srgb, var(--accent, #3574f0) 40%, var(--border, #dde1e6));
}

.sri-fields-edit .sri-input > input:not([type="checkbox"]):not([type="radio"]):focus,
.sri-fields-edit .sri-input > textarea:focus,
.sri-fields-edit .sri-input > select:focus {
	outline: none;
	border-color: var(--accent, #3574f0);
	background: var(--bg, #fff);
	box-shadow: 0 0 0 3px color-mix(in srgb, var(--accent, #3574f0) 20%, transparent);
}

.sri-fields-edit .sri-input > textarea {
	min-height: 5em;
	resize: vertical;
}

.sri-fields-edit .sri-input-check {
	flex-direction: row;
	align-items: center;
	gap: 8px;
	padding: 6px 2px;
}

.sri-fields-edit .sri-input-check input[type="checkbox"] {
	width: 16px;
	height: 16px;
	margin: 0;
	accent-color: var(--accent, #3574f0);
	cursor: pointer;
}

.sri-fields-edit .sri-input-check label {
	font: 13px/1.4 inherit;
	color: var(--fg, inherit);
	cursor: pointer;
}

.sri-form-meta {
	display: none !important;
}

.sri-toast {
	position: absolute;
	left: 50%;
	bottom: 58px;
	transform: translateX(-50%) translateY(8px);
	padding: 7px 14px;
	border-radius: 999px;
	font: 12px/1.4 inherit;
	color: var(--accent-fg, #fff);
	background: color-mix(in srgb, var(--accent, #3574f0) 92%, #000);
	box-shadow: 0 4px 16px color-mix(in srgb, var(--accent, #3574f0) 35%, transparent);
	opacity: 0;
	pointer-events: none;
	transition: opacity .2s ease, transform .2s ease;
	z-index: 2;
}

.sri-toast.sri-toast-on {
	opacity: 1;
	transform: translateX(-50%) translateY(0);
}

.sri-fields-edit .sri-input > input.sri-invalid,
.sri-fields-edit .sri-input > textarea.sri-invalid {
	border-color: #cf222e;
	box-shadow: 0 0 0 3px color-mix(in srgb, #cf222e 18%, transparent);
}

.sri-drawer-foot {
	display: flex;
	align-items: center;
	flex-wrap: wrap;
	gap: 16px;
	padding: 12px 14px;
	border-top: 1px solid var(--border, #dde1e6);
}

.sri-icon-btn {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	flex: 0 0 auto;
	width: 42px;
	height: 42px;
	padding: 0;
	border: 1px solid color-mix(in srgb, var(--border, #dde1e6) 80%, transparent);
	border-radius: 10px;
	background: color-mix(in srgb, var(--dim, #f1f3f5) 70%, var(--bg, #fff));
	color: var(--fg, inherit);
	cursor: pointer;
	transition: border-color .15s ease, color .15s ease, background .15s ease, box-shadow .15s ease, transform .12s ease;
}

.sri-icon-btn .sri-ico {
	/* 18px at stroke-width 2 is Lucide's intended density; 24px inside a 30px
	   button made the glyphs collide with the button edge. */
	width: 18px;
	height: 18px;
	stroke: currentColor;
	fill: none;
	stroke-width: 2;
	stroke-linecap: round;
	stroke-linejoin: round;
	pointer-events: none;
}

.sri-icon-btn:hover,
.sri-icon-btn:focus-visible {
	border-color: color-mix(in srgb, var(--accent, #3574f0) 55%, var(--border, #dde1e6));
	color: var(--accent, #3574f0);
	background: color-mix(in srgb, var(--accent, #3574f0) 10%, var(--dim, #f1f3f5));
	box-shadow: 0 2px 8px color-mix(in srgb, var(--accent, #3574f0) 12%, transparent);
	transform: translateY(-1px);
	outline: none;
}

.sri-icon-btn:active {
	transform: translateY(0);
}

.sri-icon-btn.sri-copied {
	border-color: var(--accent, #3574f0);
	color: var(--accent, #3574f0);
	box-shadow: 0 0 0 3px color-mix(in srgb, var(--accent, #3574f0) 18%, transparent);
}

.sri-icon-btn.sri-busy {
	opacity: .55;
	cursor: wait;
	transform: none;
}

.sri-icon-btn[data-act="save"] {
	background: var(--accent, #3574f0);
	color: var(--accent-fg, #fff);
	border-color: transparent;
	box-shadow: 0 2px 10px color-mix(in srgb, var(--accent, #3574f0) 35%, transparent);
}

.sri-icon-btn[data-act="save"]:hover,
.sri-icon-btn[data-act="save"]:focus-visible {
	background: var(--accent-hover, #2559cc);
	border-color: transparent;
	color: var(--accent-fg, #fff);
	box-shadow: 0 4px 14px color-mix(in srgb, var(--accent, #3574f0) 40%, transparent);
}

.sri-icon-btn[data-act="delete"]:hover,
.sri-icon-btn[data-act="delete"]:focus-visible {
	border-color: color-mix(in srgb, #cf222e 55%, var(--border, #dde1e6));
	color: #cf222e;
	background: color-mix(in srgb, #cf222e 10%, var(--dim, #f1f3f5));
	box-shadow: 0 2px 8px color-mix(in srgb, #cf222e 14%, transparent);
}

.sri-drawer-foot button {
	display: none;
}

.sri-drawer-foot .sri-icon-btn {
	display: inline-flex;
}

.sri-drawer-foot button[hidden] {
	display: none !important;
}

.sri-nav {
	margin-left: auto;
	font: 10px/1.5 ui-monospace, monospace;
	color: var(--muted, #6b7280);
}

.sri-confirm {
	position: fixed;
	inset: 0;
	z-index: 10060;
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 1em;
	background: rgba(0, 0, 0, 0.45);
}

.sri-confirm[hidden] { display: none !important; }

.sri-confirm-box {
	width: min(22em, 100%);
	padding: 1em 1.1em;
	border: 1px solid var(--border, #dde1e6);
	border-radius: var(--radius, 8px);
	background: var(--bg, #fff);
	box-shadow: 0 8px 32px rgba(0, 0, 0, 0.25);
}

.sri-confirm-box p {
	margin: 0 0 1em;
	font: inherit;
	line-height: 1.45;
}

.sri-confirm-box .sri-confirm-detail {
	margin-top: .5em;
	font: 11px/1.4 ui-monospace, monospace;
	color: var(--muted, #6b7280);
	word-break: break-all;
}

.sri-confirm-actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
}

.sri-confirm-actions button {
	font: inherit;
	font-size: 0.85em;
	padding: 5px 12px;
	border-radius: var(--radius-sm, 6px);
	cursor: pointer;
}

.sri-confirm-actions .sri-confirm-cancel {
	background: var(--dim, #f1f3f5);
	border: 1px solid var(--border, #dde1e6);
	color: var(--fg, inherit);
}

.sri-confirm-actions .sri-confirm-ok {
	background: #cf222e;
	border: 1px solid #cf222e;
	color: #fff;
}

.sri-drawer-foot button:disabled {
	opacity: 0.45;
	cursor: not-allowed;
}

@media all and (max-width: 800px) {
	.sri-drawer { width: 100vw; }
}
</style>
<script<?php echo Adminer\nonce(); ?>>
(() => {
	'use strict';

	if (document.documentElement.dataset.rowInspector === 'off') {
		return;
	}

	const boot = () => {
		const grid = document.getElementById('table');
		// The row-action form is NOT #form. On Adminer 6.0.1's select page:
		//   #form   -> method=get, the QUERY form (Select/Search/Sort/Limit);
		//              0 check[] boxes, no row-action buttons
		//   (no id) -> method=post, holds all check[] boxes plus
		//              Save / edit / clone / delete / export / import
		// Using #form made resolveChecks() return [] (so Delete and Clone were
		// permanently disabled), findActionButton() return null, and form.submit()
		// would have posted the query form. Resolve the owning form from a
		// checkbox instead - HTMLInputElement.form works regardless of id.
		const form = document.querySelector('input[name="check[]"]')?.form
			|| document.getElementById('form');
		if (!grid || !form) {
			return;
		}

		const FETCH_LIMIT = 10;
		const SHOW_LIMIT = 25;
		const ACTIVE = 'row-active';

		const types = new Map(
			[...grid.querySelectorAll('thead th[id^="th["]')].map((th) => [
				th.id.slice(3, -1),
				th.querySelector('span[title]')?.title || '',
			]),
		);

		const family = (type) => {
			const t = (type || '').toLowerCase();
			if (/timestamp|datetime/.test(t)) return 'datetime';
			if (/^date/.test(t)) return 'date';
			if (/^time\b/.test(t)) return 'time';
			if (/bool/.test(t)) return 'boolean';
			if (/json/.test(t)) return 'json';
			if (/uuid/.test(t)) return 'uuid';
			if (/^(bigint|int|integer|smallint|serial|bigserial)/.test(t)) return 'integer';
			if (/^(numeric|decimal|real|double|float|money)/.test(t)) return 'number';
			return 'text';
		};

		const isNavKey = (key) => [
			'Backspace', 'Delete', 'Tab', 'Escape', 'Enter',
			'ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Home', 'End',
		].includes(key);

		const applyFieldValidation = (el, typeStr) => {
			const kind = family(typeStr);
			el.dataset.sriKind = kind;
			if (el.tagName === 'SELECT' || el.type === 'checkbox' || el.type === 'radio' || el.type === 'file') {
				return;
			}
			const markInvalid = (input, on) => {
				input.classList.toggle('sri-invalid', on);
			};
			const validateValue = (input) => {
				const value = input.value;
				if (!value) {
					markInvalid(input, false);
					return true;
				}
				if (kind === 'integer') {
					const ok = /^-?\d+$/.test(value);
					markInvalid(input, !ok);
					return ok;
				}
				if (kind === 'number') {
					const ok = /^-?\d+(\.\d+)?$/.test(value);
					markInvalid(input, !ok);
					return ok;
				}
				if (kind === 'uuid') {
					const ok = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(value);
					markInvalid(input, !ok);
					return ok;
				}
				if (kind === 'date') {
					const ok = /^\d{4}-\d{2}-\d{2}$/.test(value);
					markInvalid(input, !ok);
					return ok;
				}
				if (kind === 'datetime') {
					const ok = /^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2}(\.\d+)?)?)?$/.test(value);
					markInvalid(input, !ok);
					return ok;
				}
				markInvalid(input, false);
				return true;
			};
			const filterKeydown = (event) => {
				if (event.ctrlKey || event.metaKey || event.altKey || isNavKey(event.key)) {
					return;
				}
				if (kind === 'integer') {
					if (event.key === '-' && event.target.selectionStart === 0 && !event.target.value.includes('-')) {
						return;
					}
					if (/^\d$/.test(event.key)) {
						return;
					}
					event.preventDefault();
					return;
				}
				if (kind === 'number') {
					if (event.key === '-' && event.target.selectionStart === 0 && !event.target.value.includes('-')) {
						return;
					}
					if (event.key === '.' && !event.target.value.includes('.')) {
						return;
					}
					if (/^\d$/.test(event.key)) {
						return;
					}
					event.preventDefault();
					return;
				}
				if (kind === 'uuid') {
					if (/^[0-9a-f-]$/i.test(event.key)) {
						return;
					}
					event.preventDefault();
				}
			};
			const filterPaste = (event) => {
				const clip = event.clipboardData?.getData('text') || '';
				if (!clip) {
					return;
				}
				if (kind === 'integer' && !/^-?\d+$/.test(clip)) {
					event.preventDefault();
				}
				if (kind === 'number' && !/^-?\d+(\.\d+)?$/.test(clip)) {
					event.preventDefault();
				}
				if (kind === 'uuid' && !/^[0-9a-f-]+$/i.test(clip)) {
					event.preventDefault();
				}
			};
			if (kind === 'integer') {
				el.inputMode = 'numeric';
			} else if (kind === 'number') {
				el.inputMode = 'decimal';
			}
			el.addEventListener('keydown', filterKeydown);
			el.addEventListener('paste', filterPaste);
			el.addEventListener('input', () => validateValue(el));
			el.addEventListener('blur', () => validateValue(el));
		};

		const validatePanelForm = (editFormEl) => {
			let ok = true;
			for (const el of editFormEl.querySelectorAll('[data-sri-kind]')) {
				if (el.type === 'checkbox' || el.type === 'radio' || el.tagName === 'SELECT') {
					continue;
				}
				el.dispatchEvent(new Event('blur'));
				if (el.classList.contains('sri-invalid')) {
					ok = false;
				}
			}
			return ok;
		};

		const escape = (s) => String(s).replace(/[&<>"]/g, (c) => ({
			'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;',
		}[c]));

		const copyText = async (text, button) => {
			// navigator.clipboard does NOT exist on a non-secure origin, and
			// plain http on a LAN IP (e.g. http://192.168.x.x:6868) is not
			// secure - only localhost is. So on any real network address the
			// execCommand path below is the NORMAL path, not a rare fallback.
			let copied = false;
			try {
				if (navigator.clipboard?.writeText) {
					await navigator.clipboard.writeText(text);
					copied = true;
				}
			} catch {
				/* fall through to execCommand */
			}
			if (!copied) {
				const active = document.activeElement;
				const range = active && 'selectionStart' in active
					? [active.selectionStart, active.selectionEnd]
					: null;
				const pad = document.createElement('textarea');
				pad.value = text;
				pad.setAttribute('readonly', '');
				// Off-screen: a plain appended textarea can scroll the page.
				pad.style.cssText = 'position:fixed;top:-1000px;left:-1000px;opacity:0';
				document.body.append(pad);
				pad.select();
				try {
					document.execCommand('copy');
				} catch {
					/* nothing else to try */
				}
				pad.remove();
				// execCommand requires focus, so it steals it. Give it back, or
				// the user's next Ctrl+V lands on <body> and appears to do
				// nothing - which is exactly how this read as "paste is broken".
				if (active && typeof active.focus === 'function') {
					active.focus({ preventScroll: true });
					if (range) {
						try {
							active.setSelectionRange(range[0], range[1]);
						} catch {
							/* not a text control */
						}
					}
				}
			}
			if (button) {
				button.classList.add('sri-copied');
				setTimeout(() => button.classList.remove('sri-copied'), 900);
			}
		};

		const checkValuesFor = (rows) => rows
			.map((tr) => tr.querySelector('input[name="check[]"]')?.value)
			.filter(Boolean);

		const appendFormFields = (fd, root) => {
			for (const el of root.querySelectorAll('input, select, textarea')) {
				if (!el.name || el.disabled) {
					continue;
				}
				if (el.type === 'checkbox' || el.type === 'radio') {
					if (el.checked) {
						fd.append(el.name, el.value);
					}
				} else if (el.type === 'file') {
					for (const file of el.files || []) {
						fd.append(el.name, file);
					}
				} else if (el.tagName === 'SELECT' && el.multiple) {
					for (const opt of el.selectedOptions) {
						fd.append(el.name, opt.value);
					}
				} else {
					fd.append(el.name, el.value);
				}
			}
		};

		const parseAdminerError = (html) => {
			const doc = new DOMParser().parseFromString(html, 'text/html');
			return doc.querySelector('.error')?.textContent?.trim() || '';
		};

		const isValueFieldControl = (el) => {
			const name = el.getAttribute('name') || '';
			return /^fields\[/.test(name) && !/^fields-null\[/.test(name);
		};

		const isMetaFieldControl = (el) => {
			const name = el.getAttribute('name') || '';
			return /^function\[/.test(name) || /^fields-null\[/.test(name);
		};

		const parseEditForm = (doc) => {
			const out = {};
			for (const item of doc.querySelectorAll('[name^="fields["]')) {
				const raw = item.getAttribute('name') || '';
				const m = raw.match(/^fields\[(.+)\]$/);
				if (!m) {
					continue;
				}
				const name = m[1];
				if (item.type === 'checkbox' || item.type === 'radio') {
					if (item.checked) {
						out[name] = item.value;
					}
				} else {
					out[name] = item.tagName === 'TEXTAREA' ? item.textContent : item.value;
				}
			}
			for (const nul of doc.querySelectorAll('[name^="fields-null["]')) {
				if (nul.checked) {
					out[nul.getAttribute('name').slice(12, -1)] = null;
				}
			}
			const srcForm = doc.querySelector('#form') || doc.querySelector('#content form');
			return {
				values: Object.keys(out).length ? out : null,
				editForm: srcForm,
				canEdit: !!srcForm?.querySelector('table'),
				saveLabel: srcForm?.querySelector('input[type="submit"]')?.value || 'Save',
			};
		};

		const postForm = async (url, fd, { reload = true } = {}) => {
			const res = await fetch(url, {
				method: 'POST',
				body: fd,
				credentials: 'same-origin',
			});
			const html = await res.text();
			const err = parseAdminerError(html);
			if (err) {
				throw new Error(err);
			}
			if (!res.ok) {
				throw new Error('Request failed');
			}
			if (reload) {
				location.reload();
			}
			return html;
		};

		const showToast = (message) => {
			let toast = drawer.querySelector('.sri-toast');
			if (!toast) {
				toast = document.createElement('div');
				toast.className = 'sri-toast';
				drawer.appendChild(toast);
			}
			toast.textContent = message;
			toast.classList.add('sri-toast-on');
			clearTimeout(toast._timer);
			toast._timer = setTimeout(() => toast.classList.remove('sri-toast-on'), 2200);
		};

		const updateGridRow = (tr, values) => {
			for (const cell of tr.querySelectorAll('td[id^="val["]')) {
				const column = cell.id.slice(cell.id.lastIndexOf('[') + 1, -1);
				if (!Object.prototype.hasOwnProperty.call(values, column)) {
					continue;
				}
				const val = values[column];
				if (val === null) {
					cell.innerHTML = '<i>NULL</i>';
				} else {
					cell.textContent = val;
				}
			}
		};

		const refreshRowData = async (row) => {
			const payload = await readFull(row.tr);
			if (!payload?.values) {
				return;
			}
			row.values = { ...row.values, ...payload.values };
			row.editUrl = payload.editUrl;
			row.editForm = payload.editForm;
			row.canEdit = payload.canEdit;
			row.saveLabel = payload.saveLabel;
			updateGridRow(row.tr, row.values);
		};

		const findActionButton = (actionName) => (
			form.querySelector(`input[type="submit"][name="${actionName}"]`)
			|| form.querySelector(`button[type="submit"][name="${actionName}"]`)
			|| form.querySelector(`input[name="${actionName}"]`)
		);

		const resolveChecks = (rows) => {
			const fromDom = checkValuesFor(rows);
			if (fromDom.length) {
				return fromDom;
			}
			if (rows.length === 1 && shown.length === 1 && shown[0].tr === rows[0] && shown[0].checkValue) {
				return [shown[0].checkValue];
			}
			return rows
				.map((tr) => tr.querySelector('input[name="check[]"]')?.value || '')
				.filter(Boolean);
		};

		const runSelectAction = (actionName) => {
			const rows = activeRows();
			if (!rows.length) {
				return false;
			}
			const checks = resolveChecks(rows);
			if (!checks.length) {
				return false;
			}

			const allChecks = [...form.querySelectorAll('input[name="check[]"]')];
			allChecks.forEach((cb) => { cb.checked = false; });
			for (const tr of rows) {
				const cb = tr.querySelector('input[name="check[]"]');
				if (!cb) {
					continue;
				}
				cb.checked = true;
				if (typeof trCheck === 'function') {
					trCheck(cb);
				}
				cb.dispatchEvent(new Event('change', { bubbles: true }));
			}

			const btn = findActionButton(actionName);
			const actionValue = btn?.value || btn?.textContent?.trim() || (
				actionName === 'delete' ? 'Delete' : actionName === 'clone' ? 'Clone' : actionName
			);

			for (const el of form.querySelectorAll(`input[data-sri-act="${actionName}"]`)) {
				el.remove();
			}
			const hidden = document.createElement('input');
			hidden.type = 'hidden';
			hidden.name = actionName;
			hidden.value = actionValue;
			hidden.dataset.sriAct = actionName;
			form.appendChild(hidden);

			form.submit();
			return true;
		};

		const confirmDialog = (message, detail) => new Promise((resolve) => {
			const overlay = document.createElement('div');
			overlay.className = 'sri-confirm';
			overlay.innerHTML = `
				<div class="sri-confirm-box" role="alertdialog" aria-modal="true">
					<p>${escape(message)}</p>
					${detail ? `<div class="sri-confirm-detail">${escape(detail)}</div>` : ''}
					<div class="sri-confirm-actions">
						<button type="button" class="sri-confirm-cancel">Cancel</button>
						<button type="button" class="sri-confirm-ok">Delete</button>
					</div>
				</div>`;
			const done = (ok) => {
				overlay.remove();
				resolve(ok);
			};
			overlay.querySelector('.sri-confirm-cancel').onclick = () => done(false);
			overlay.querySelector('.sri-confirm-ok').onclick = () => done(true);
			overlay.addEventListener('click', (e) => {
				if (e.target === overlay) {
					done(false);
				}
			});
			document.body.append(overlay);
		});

		const refreshActionButtons = () => {
			const single = shown.length === 1;
			const editable = single && shown[0]?.canEdit && shown[0]?.editForm && view === 'fields';
			const editBtn = drawer.querySelector('[data-act="edit"]');
			const saveBtn = drawer.querySelector('[data-act="save"]');
			const cancelBtn = drawer.querySelector('[data-act="cancel"]');
			// View mode shows Edit; edit mode shows Save + Cancel. Never both.
			if (editBtn) {
				editBtn.hidden = !(editable && !editing);
			}
			if (saveBtn) {
				saveBtn.hidden = !(editable && editing);
				saveBtn.disabled = false;
				saveBtn.classList.remove('sri-busy');
			}
			if (cancelBtn) {
				cancelBtn.hidden = !(editable && editing);
			}
			const delBtn = drawer.querySelector('[data-act="delete"]');
			const cloneBtn = drawer.querySelector('[data-act="clone"]');
			const hasChecks = resolveChecks(activeRows()).length > 0;
			if (delBtn) {
				delBtn.disabled = !hasChecks;
			}
			if (cloneBtn) {
				cloneBtn.disabled = !hasChecks;
			}
		};

		const fieldCopyValue = (rowIndex, column) => {
			const val = shown[rowIndex]?.values[column];
			return val === null || val === undefined ? '' : String(val);
		};

		const fieldCopyText = (field) => {
			const column = field.dataset.column;
			const rowIndex = Number(field.dataset.row || 0);
			const control = field.querySelector('.sri-input input, .sri-input textarea, .sri-input select');
			if (control) {
				if (control.type === 'checkbox') {
					return control.checked ? control.value : '';
				}
				return control.value;
			}
			if (field.querySelector('dd .sri-null')) {
				return '';
			}
			return fieldCopyValue(rowIndex, column);
		};

		const jsonPayload = () => JSON.stringify(
			shown.length === 1 ? shown[0].values : shown.map((row) => row.values),
			null,
			2,
		);

		const sriIcon = (paths) => `<svg class="sri-ico" viewBox="0 0 24 24" aria-hidden="true">${paths}</svg>`;
		// Lucide (lucide-react) paths, so every glyph in the panel comes from one
		// family at one stroke weight instead of the hand-drawn mix we had.
		const SRI_FOOT_ICONS = {
			// lucide: copy
			copy: sriIcon('<rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/>'),
			// lucide: save
			save: sriIcon('<path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/>'),
			// lucide: square-pen
			edit: sriIcon('<path d="M12 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.375 2.625a1 1 0 0 1 3 3l-9.013 9.014a2 2 0 0 1-.853.505l-2.873.84a.5.5 0 0 1-.62-.62l.84-2.873a2 2 0 0 1 .506-.852z"/>'),
			// lucide: x
			cancel: sriIcon('<path d="M18 6 6 18"/><path d="m6 6 12 12"/>'),
			// lucide: copy-plus
			clone: sriIcon('<line x1="15" x2="15" y1="12" y2="18"/><line x1="12" x2="18" y1="15" y2="15"/><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/>'),
			// lucide: trash
			delete: sriIcon('<path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>'),
			// lucide: x  (header close)
			close: sriIcon('<path d="M18 6 6 18"/><path d="m6 6 12 12"/>'),
		};

		const drawer = document.createElement('aside');
		drawer.className = 'sri-drawer';
		drawer.hidden = true;
		drawer.innerHTML = `
			<header class="sri-drawer-head">
				<div>
					<b class="sri-drawer-title"></b>
					<span class="sri-drawer-key"></span>
				</div>
				<button type="button" class="sri-drawer-close sri-icon-btn" title="Close (Esc)" aria-label="Close">${SRI_FOOT_ICONS.close}</button>
			</header>
			<nav class="sri-tabs">
				<button type="button" data-view="fields" class="sri-on">Fields</button>
				<button type="button" data-view="json">JSON</button>
				<span class="sri-loading" hidden>loading full values…</span>
			</nav>
			<div class="sri-drawer-body"></div>
			<footer class="sri-drawer-foot">
				<button type="button" data-act="copy" class="sri-icon-btn" title="Copy JSON" aria-label="Copy JSON">${SRI_FOOT_ICONS.copy}</button>
				<button type="button" data-act="edit" class="sri-icon-btn" hidden title="Edit" aria-label="Edit">${SRI_FOOT_ICONS.edit}</button>
				<button type="button" data-act="save" class="sri-icon-btn" hidden title="Save" aria-label="Save">${SRI_FOOT_ICONS.save}</button>
				<button type="button" data-act="cancel" class="sri-icon-btn" hidden title="Cancel" aria-label="Cancel">${SRI_FOOT_ICONS.cancel}</button>
				<button type="button" data-act="clone" class="sri-icon-btn" title="Clone" aria-label="Clone">${SRI_FOOT_ICONS.clone}</button>
				<button type="button" data-act="delete" class="sri-icon-btn" title="Delete" aria-label="Delete">${SRI_FOOT_ICONS.delete}</button>
				<span class="sri-nav">↑↓ row · Esc close</span>
			</footer>`;
		document.body.append(drawer);

		const ui = {
			title: drawer.querySelector('.sri-drawer-title'),
			key: drawer.querySelector('.sri-drawer-key'),
			body: drawer.querySelector('.sri-drawer-body'),
			loading: drawer.querySelector('.sri-loading'),
			tabs: [...drawer.querySelectorAll('.sri-tabs button')],
			foot: drawer.querySelector('.sri-drawer-foot'),
		};

		let shown = [];
		let view = 'fields';
		// Read-only until the user explicitly asks to edit. Opening a row was
		// dropping straight into a form full of live inputs, so simply looking
		// at a record put every column one keystroke away from being changed.
		let editing = false;
		let token = 0;
		let userClosed = false;
		let saving = false;

		const tableName = new URL(location.href).searchParams.get('select') || 'row';

		const keyOf = (tr) => decodeURIComponent(
			(tr.querySelector('input[name="check[]"]')?.value || '')
				.replace(/^&/, '').replace(/where\[|\]/g, ''),
		);

		const dataRows = () => [...grid.querySelectorAll('tbody tr')].filter((tr) => tr.querySelector('td[id^="val["]'));

		/**
		 * Rows the panel is showing = rows whose checkbox is TICKED.
		 *
		 * It used to be rows carrying .row-active, which row-highlight.php sets
		 * on a click anywhere in the row - so the panel flew open whenever you
		 * clicked any cell just to read it. Ticking a checkbox is a deliberate
		 * "I mean this row" gesture, and it is already the selection model
		 * Adminer itself uses for Edit / Clone / Delete, so the panel and the
		 * action buttons now agree on what is selected instead of disagreeing.
		 * Click-to-highlight still works, it just no longer opens the panel.
		 */
		const checkedRows = () => dataRows().filter((tr) => {
			const cb = tr.querySelector('input[name="check[]"]');
			return !!cb && cb.checked;
		});

		const activeRows = () => checkedRows();

		const readGrid = (tr) => {
			const out = {};
			for (const cell of tr.querySelectorAll('td[id^="val["]')) {
				const column = cell.id.slice(cell.id.lastIndexOf('[') + 1, -1);
				out[column] = cell.querySelector('i') ? null : cell.textContent;
			}
			return out;
		};

		const readFull = async (tr) => {
			const link = tr.querySelector('a.edit, a.edit-icon');
			if (!link) {
				return null;
			}
			const html = await (await fetch(link.href, { credentials: 'same-origin' })).text();
			const doc = new DOMParser().parseFromString(html, 'text/html');
			return {
				...parseEditForm(doc),
				editUrl: link.href,
			};
		};

		const pretty = (value, column) => {
			if (value === null) {
				return '<i class="sri-null">NULL</i>';
			}
			if (family(types.get(column)) === 'json') {
				try {
					return `<pre class="sri-json">${escape(JSON.stringify(JSON.parse(value), null, 2))}</pre>`;
				} catch { /* fall through */ }
			}
			return escape(value);
		};

		const fieldList = (row, index) => `<dl class="sri-fields">${Object.entries(row.values).map(([column, value]) => `
			<div class="sri-field" data-row="${index}" data-column="${escape(column)}">
				<dt>
					<span class="sri-name">${escape(column)}</span>
					<span class="sri-kind">${escape(types.get(column) || '')}</span>
					<button type="button" class="sri-copy-btn" title="Copy value" aria-label="Copy ${escape(column)}">⎘</button>
				</dt>
				<dd>${pretty(value, column)}</dd>
			</div>`).join('')}</dl>`;

		const paintPanelEditor = (row) => {
			ui.body.innerHTML = '';
			const wrap = document.createElement('div');
			wrap.className = 'sri-inline-form';

			const editFormEl = document.createElement('form');
			editFormEl.id = 'sri-edit-form';
			editFormEl.method = 'post';
			editFormEl.action = row.editUrl;
			editFormEl.enctype = 'multipart/form-data';
			editFormEl.noValidate = true;

			for (const hidden of row.editForm.querySelectorAll('input[type="hidden"]')) {
				editFormEl.appendChild(hidden.cloneNode(true));
			}
			if (!editFormEl.querySelector('input[name="save"]')) {
				const saveHidden = document.createElement('input');
				saveHidden.type = 'hidden';
				saveHidden.name = 'save';
				saveHidden.value = '1';
				editFormEl.appendChild(saveHidden);
			}

			const dl = document.createElement('dl');
			dl.className = 'sri-fields sri-fields-edit';
			const metaBox = document.createElement('div');
			metaBox.className = 'sri-form-meta';
			const srcTable = row.editForm.querySelector('table');
			for (const tr of srcTable?.querySelectorAll('tr') || []) {
				const th = tr.querySelector('th');
				if (!th) {
					continue;
				}
				const columnName = th.textContent.trim();
				const field = document.createElement('div');
				field.className = 'sri-field';
				field.dataset.row = '0';
				field.dataset.column = columnName;
				const dt = document.createElement('dt');
				dt.innerHTML = `
					<span class="sri-name">${escape(columnName)}</span>
					<span class="sri-kind">${escape(types.get(columnName) || '')}</span>
					<button type="button" class="sri-copy-btn" title="Copy value" aria-label="Copy ${escape(columnName)}">⎘</button>`;
				const dd = document.createElement('dd');
				const controls = [...tr.querySelectorAll('input, select, textarea')]
					.filter((el) => el.type !== 'hidden');
				const valueControls = controls.filter(isValueFieldControl);
				const metaControls = controls.filter(isMetaFieldControl);
				for (const el of metaControls) {
					metaBox.appendChild(el.cloneNode(true));
				}
				if (valueControls.length) {
					const inputWrap = document.createElement('div');
					inputWrap.className = 'sri-input';
					for (const el of valueControls) {
						const clone = el.cloneNode(true);
						if (clone.type === 'checkbox' || clone.type === 'radio') {
							inputWrap.classList.add('sri-input-check');
						} else {
							applyFieldValidation(clone, types.get(columnName) || '');
						}
						inputWrap.appendChild(clone);
					}
					dd.appendChild(inputWrap);
				} else {
					const tds = tr.querySelectorAll('td');
					const text = tds[tds.length - 1]?.textContent?.trim() || '';
					const value = Object.prototype.hasOwnProperty.call(row.values, columnName)
						? row.values[columnName]
						: text;
					dd.innerHTML = pretty(value, columnName);
				}
				field.append(dt, dd);
				dl.append(field);
			}

			editFormEl.append(metaBox, dl);
			wrap.append(editFormEl);
			ui.body.append(wrap);
		};

		const savePanel = async () => {
			if (saving || shown.length !== 1) {
				return;
			}
			const editFormEl = ui.body.querySelector('#sri-edit-form');
			if (!editFormEl) {
				return;
			}
			const row = shown[0];
			const saveBtn = drawer.querySelector('[data-act="save"]');
			if (!validatePanelForm(editFormEl)) {
				showToast('Invalid value');
				return;
			}
			saving = true;
			if (saveBtn) {
				saveBtn.disabled = true;
				saveBtn.classList.add('sri-busy');
			}
			const fd = new FormData();
			appendFormFields(fd, editFormEl);
			if (!fd.has('save')) {
				fd.append('save', '1');
			}
			try {
				await postForm(row.editUrl, fd, { reload: false });
				await refreshRowData(row);
				paintPanelEditor(row);
				refreshActionButtons();
				showToast('Saved');
			} catch (err) {
				alert(err.message || 'Save failed');
			} finally {
				saving = false;
				if (saveBtn) {
					saveBtn.disabled = false;
					saveBtn.classList.remove('sri-busy');
				}
			}
		};

		const paint = () => {
			const payload = shown.length === 1 ? shown[0].values : shown.map((row) => row.values);
			if (view === 'json') {
				ui.body.innerHTML = `
					<div class="sri-json-view">
						<div class="sri-json-toolbar">
							<button type="button" class="sri-copy-btn sri-json-copy" title="Copy JSON" aria-label="Copy JSON">⎘</button>
						</div>
						<pre class="sri-json sri-json-all">${escape(JSON.stringify(payload, null, 2))}</pre>
					</div>`;
				refreshActionButtons();
				return;
			}
			if (shown.length === 1 && editing && shown[0].editForm && shown[0].canEdit) {
				paintPanelEditor(shown[0]);
				refreshActionButtons();
				return;
			}
			if (shown.length === 1) {
				ui.body.innerHTML = fieldList(shown[0], 0);
				return;
			}
			ui.body.innerHTML = shown.map((row, index) => `
				<details class="sri-row" open>
					<summary><span class="sri-row-n">${index + 1}</span>${escape(row.key)}</summary>
					${fieldList(row, index)}
				</details>`).join('');
		};

		const render = async (rows) => {
			if (saving) {
				return;
			}
			// A different selection is a fresh look, not a continued edit.
			const sameSelection = shown.length === rows.length
				&& shown.every((r, i) => r.tr === rows[i]);
			if (!sameSelection) {
				editing = false;
			}
			const mine = ++token;
			const visible = rows.slice(0, SHOW_LIMIT);
			shown = visible.map((tr) => ({
				tr,
				key: keyOf(tr),
				checkValue: tr.querySelector('input[name="check[]"]')?.value || '',
				values: readGrid(tr),
				editUrl: null,
				editForm: null,
				canEdit: !!tr.querySelector('a.edit, a.edit-icon'),
				saveLabel: 'Save',
			}));
			ui.title.textContent = rows.length > 1 ? `${tableName} · ${rows.length} rows` : tableName;
			ui.key.textContent = rows.length === 1
				? shown[0].key
				: (rows.length > SHOW_LIMIT ? `showing the first ${SHOW_LIMIT}` : '');
			paint();
			drawer.hidden = false;
			refreshActionButtons();
			ui.loading.hidden = visible.length > FETCH_LIMIT;
			if (visible.length > FETCH_LIMIT) {
				return;
			}
			try {
				const full = await Promise.all(visible.map((tr) => readFull(tr).catch(() => null)));
				if (mine !== token) {
					return;
				}
				full.forEach((payload, i) => {
					if (!payload?.values) {
						return;
					}
					shown[i].values = { ...shown[i].values, ...payload.values };
					shown[i].editUrl = payload.editUrl;
					shown[i].editForm = payload.editForm;
					shown[i].canEdit = payload.canEdit;
					shown[i].saveLabel = payload.saveLabel;
				});
				paint();
				refreshActionButtons();
			} finally {
				if (mine === token) {
					ui.loading.hidden = true;
				}
			}
		};

		const close = () => {
			token++;
			saving = false;
			editing = false;
			drawer.hidden = true;
			userClosed = true;
		};

		const sync = (force = false) => {
			if (saving) {
				return;
			}
			const rows = activeRows();
			if (!rows.length) {
				token++;
				saving = false;
				drawer.hidden = true;
				userClosed = false;
				return;
			}
			if (userClosed && !force) {
				return;
			}
			userClosed = false;
			render(rows);
		};

		const setActive = (tr) => {
			for (const row of dataRows()) {
				row.classList.remove(ACTIVE);
			}
			tr.classList.add(ACTIVE);
			// Keyboard stepping moves the panel too, so make the checkbox the
			// single source of truth here as well: tick the row we stepped onto
			// and untick the rest. Without this, arrow-key navigation would
			// highlight one row while the panel still showed another.
			for (const row of dataRows()) {
				const cb = row.querySelector('input[name="check[]"]');
				if (cb) {
					cb.checked = (row === tr);
				}
			}
			tr.dispatchEvent(new CustomEvent('adminer-row-active', { bubbles: true }));
			tr.scrollIntoView({ block: 'nearest' });
			sync(true);
		};

		const step = (delta) => {
			const rows = dataRows();
			const from = shown.length ? rows.indexOf(shown[shown.length - 1].tr) : -1;
			const next = rows[from + delta];
			if (!next) {
				return;
			}
			setActive(next);
		};

		const deleteActiveRows = async () => {
			const rows = activeRows();
			if (!resolveChecks(rows).length) {
				return;
			}
			const detail = rows.length === 1
				? rows[0] && keyOf(rows[0])
				: rows.length + ' rows';
			const ok = await confirmDialog(
				`Delete ${rows.length} row(s)? This cannot be undone.`,
				detail,
			);
			if (!ok) {
				return;
			}
			if (!runSelectAction('delete')) {
				alert('Delete not available');
			}
		};

		const cloneActiveRows = () => {
			if (!resolveChecks(activeRows()).length) {
				return;
			}
			if (!runSelectAction('clone')) {
				alert('Clone not available');
			}
		};

		drawer.querySelector('.sri-drawer-close').onclick = close;
		for (const tab of ui.tabs) {
			tab.onclick = () => {
				if (saving) {
					return;
				}
				view = tab.dataset.view;
				ui.tabs.forEach((t) => t.classList.toggle('sri-on', t === tab));
				paint();
			};
		}

		ui.foot.onclick = (event) => {
			const btn = event.target.closest('button[data-act]');
			if (!btn || !shown.length) {
				return;
			}
			const act = btn.dataset.act;
			if (act === 'copy') {
				copyText(jsonPayload(), btn);
			}
			if (act === 'edit') {
				editing = true;
				paint();
				refreshActionButtons();
			}
			if (act === 'save') {
				savePanel();
			}
			if (act === 'cancel') {
				editing = false;           // back to read-only, discarding edits
				paint();
				refreshActionButtons();
			}
			if (act === 'clone') {
				cloneActiveRows();
			}
			if (act === 'delete') {
				deleteActiveRows();
			}
		};

		ui.body.onclick = (event) => {
			const copyBtn = event.target.closest('.sri-copy-btn');
			if (copyBtn) {
				event.preventDefault();
				event.stopPropagation();
				const field = copyBtn.closest('.sri-field');
				if (field) {
					copyText(fieldCopyText(field), copyBtn);
					field.classList.add('sri-copied');
					setTimeout(() => field.classList.remove('sri-copied'), 700);
					return;
				}
				if (copyBtn.classList.contains('sri-json-copy')) {
					copyText(jsonPayload(), copyBtn);
					return;
				}
			}
			if (shown.length === 1 && shown[0]?.canEdit && shown[0]?.editForm && view === 'fields') {
				return;
			}
			const field = event.target.closest('.sri-field');
			if (field) {
				copyText(fieldCopyText(field), null);
				field.classList.add('sri-copied');
				setTimeout(() => field.classList.remove('sri-copied'), 700);
			}
		};

		// Only a checkbox change opens/updates/closes the panel. A plain cell
		// click must not - that is what made every read-only click pop the drawer.
		grid.addEventListener('change', (event) => {
			if (event.target?.name === 'check[]') {
				setTimeout(() => sync(true), 0);
			}
		});
		// Adminer's own "check all" / shift-range helpers set .checked directly
		// without firing change, so re-sync after a click on the checkbox column.
		grid.addEventListener('click', (event) => {
			if (event.target?.closest?.('td.checkbox, th.checkbox') || event.target?.name === 'check[]') {
				setTimeout(() => sync(true), 0);
			}
		});

		const tbody = grid.querySelector('tbody');
		if (tbody) {
			new MutationObserver(() => setTimeout(() => sync(true), 0)).observe(tbody, {
				attributes: true,
				attributeFilter: ['class'],
				subtree: true,
			});
		}

		addEventListener('keydown', (event) => {
			if (drawer.hidden || event.target.matches('input, textarea, select')) {
				return;
			}
			if (event.key === 'Escape') {
				close();
			}
			if (event.key === 'ArrowDown') {
				event.preventDefault();
				step(1);
			}
			if (event.key === 'ArrowUp') {
				event.preventDefault();
				step(-1);
			}
		});
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
		'en' => array('' => 'Row inspector panel with inline edit on Select'),
		'vi' => array('' => 'Panel hàng: copy, sửa inline, delete; setting bật/tắt'),
	);
}

return new AdminerSelectRowInspector();
