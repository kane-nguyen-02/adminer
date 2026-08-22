<?php

/**
 * Smart assist for Adminer Select search inputs (optional — free text always kept).
 *
 * Loaded by Adminer from plugins-enabled/ (docker-compose volume).
 * Hooks: headers() (DISTINCT JSON), head() (CSS/JS on ?select= pages).
 * Query params unchanged: where[n][col|op|val] — compatible with selectSearchProcess().
 */
final class AdminerSelectSmartFilter extends Adminer\Plugin {
	private const DISTINCT_LIMIT = 50;
	private const TZ_OFFSET = '+07:00';

	/**
	 * True only when a live DB connection exists.
	 *
	 * Adminer calls head() on the LOGIN page too, and $_GET['select'] survives
	 * there (expired session, bookmarked select URL, fresh browser). Without
	 * this guard Adminer\fields() dereferences a null driver and the whole
	 * console dies with "Call to a member function tableOid() on null" —
	 * reproduced, not hypothetical. A broken plugin must cost one feature,
	 * never the page.
	 */
	private function connected(): bool {
		if (!isset($_GET['username'])) {
			return false;
		}
		try {
			return (bool) Adminer\connection();
		} catch (\Throwable $e) {
			return false;
		}
	}

	/**
	 * Adminer\fields() that can never be fatal: returns array() when there is
	 * no connection, no such table, or the driver throws.
	 *
	 * @return array<string, array>
	 */
	private function safeFields(string $table): array {
		if ($table === '' || !$this->connected()) {
			return array();
		}
		try {
			return Adminer\fields($table) ?: array();
		} catch (\Throwable $e) {
			error_log('select-smart-filter: field lookup failed: ' . $e->getMessage());
			return array();
		}
	}

	/** connection()->query() that can never be fatal. @return mixed */
	private function safeQuery(string $sql) {
		if (!$this->connected()) {
			return false;
		}
		try {
			return Adminer\connection()->query($sql);
		} catch (\Throwable $e) {
			error_log('select-smart-filter: query failed: ' . $e->getMessage());
			return false;
		}
	}

	function headers() {
		if (!isset($_GET['select']) || !isset($_GET['ssf_distinct'])) {
			return;
		}

		$table = (string) $_GET['select'];
		$column = (string) $_GET['ssf_distinct'];
		if ($column === '' || !preg_match('~^[a-zA-Z_][a-zA-Z0-9_]*$~', $column)) {
			$this->jsonResponse(array());
		}

		$fields = $this->safeFields($table);
		if (!isset($fields[$column])) {
			$this->jsonResponse(array());
		}

		$query = isset($_GET['ssf_q']) ? (string) $_GET['ssf_q'] : '';
		$this->jsonResponse($this->fetchDistinct($table, $column, $query));
	}

	function head($dark = null) {
		if (!isset($_GET['select'])) {
			return;
		}

		$table = (string) $_GET['select'];
		// No connection (login page reached via a select URL) -> nothing to assist.
		$fields = $this->safeFields($table);
		if (!$fields) {
			return;
		}
		$meta = $this->buildColumnMeta($table, $fields);
		$metaJson = json_encode($meta, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
		if ($metaJson === false) {
			$metaJson = '{}';
		}
		?>
<style<?php echo Adminer\nonce(); ?>>
#fieldset-search > div {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: .3em .35em;
}
/* Holds only the assist button + bool chips — never the input itself
   (see the syncRow() comment: Adminer needs the input as a direct sibling
   of the where[n][col] select). */
#fieldset-search .ssf-val-cell {
	display: inline-flex;
	align-items: center;
	gap: .25em;
	flex: 0 0 auto;
}
#fieldset-search input[name$="[val]"] {
	flex: 1 1 12em;
	min-width: 10em;
	max-width: 100%;
}
#fieldset-search input[name$="[val]"].ssf-invalid {
	outline: 1px solid #e5534b;
	outline-offset: 0;
}
#fieldset-search .ssf-assist-btn {
	flex: 0 0 auto;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 1.85em;
	height: 1.85em;
	padding: 0;
	border: 1px solid var(--border, #888);
	border-radius: var(--radius-sm, 6px);
	background: var(--dim, #f0f0f0);
	color: inherit;
	font: inherit;
	font-size: .95em;
	line-height: 1;
	cursor: pointer;
}
#fieldset-search .ssf-assist-btn:hover,
#fieldset-search .ssf-assist-btn:focus-visible {
	border-color: var(--accent, #3574f0);
	background: color-mix(in srgb, var(--accent, #3574f0) 14%, var(--dim, #f0f0f0));
	outline: none;
}
#fieldset-search .ssf-assist-btn[hidden] {
	display: none !important;
}
#fieldset-search .ssf-bool-chips {
	display: inline-flex;
	flex-wrap: wrap;
	gap: .2em;
}
#fieldset-search .ssf-bool-chips button {
	padding: .15em .45em;
	border: 1px solid var(--border, #888);
	border-radius: var(--radius-sm, 4px);
	background: transparent;
	color: inherit;
	font: inherit;
	font-size: .85em;
	cursor: pointer;
}
#fieldset-search .ssf-bool-chips button:hover {
	background: color-mix(in srgb, var(--accent, #3574f0) 16%, transparent);
}
.ssf-portal {
	position: fixed;
	z-index: 10045;
	display: flex;
	margin: 0;
	padding: .25rem;
	border: 1px solid color-mix(in srgb, var(--border, #888) 80%, transparent);
	border-radius: .5rem;
	background: var(--bg, #fff);
	color: var(--fg, inherit);
	box-shadow: 0 10px 38px -10px rgba(0, 0, 0, .35), 0 0 0 1px color-mix(in srgb, var(--border, #888) 40%, transparent);
	font: inherit;
	font-size: .9rem;
	overflow: hidden;
}
.ssf-portal button {
	font: inherit;
	cursor: pointer;
}
.ssf-dt {
	min-width: min(34em, calc(100vw - 16px));
	max-height: min(26em, calc(100vh - 16px));
}
.ssf-dt-presets {
	flex: 0 0 auto;
	display: flex;
	flex-direction: column;
	gap: .15em;
	padding: .45em;
	border-right: 1px solid var(--border, #888);
	background: var(--dim, #f6f6f6);
	overflow: auto;
}
.ssf-dt-presets button {
	display: block;
	width: 100%;
	padding: .35em .55em;
	border: 0;
	border-radius: var(--radius-sm, 4px);
	background: transparent;
	color: inherit;
	text-align: left;
	white-space: nowrap;
}
.ssf-dt-presets button:hover {
	background: color-mix(in srgb, var(--accent, #3574f0) 20%, transparent);
}
.ssf-dt-main {
	flex: 1 1 auto;
	display: flex;
	flex-direction: column;
	min-width: 0;
	padding: .45em .55em .5em;
}
.ssf-dt-nav {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: .5em;
	margin-bottom: .35em;
	font-weight: 600;
}
.ssf-dt-nav button {
	padding: .2em .45em;
	border: 1px solid var(--border, #888);
	border-radius: var(--radius-sm, 4px);
	background: transparent;
	color: inherit;
}
.ssf-dt-grid {
	display: grid;
	grid-template-columns: repeat(7, 1fr);
	gap: .12em;
	text-align: center;
}
.ssf-dt-grid .ssf-dow {
	opacity: .65;
	font-size: .8em;
	padding: .1em 0;
}
.ssf-dt-grid .ssf-day {
	padding: .28em 0;
	border: 0;
	border-radius: var(--radius-sm, 4px);
	background: transparent;
	color: inherit;
}
.ssf-dt-grid .ssf-day:hover {
	background: color-mix(in srgb, var(--accent, #3574f0) 18%, transparent);
}
.ssf-dt-grid .ssf-day.ssf-out {
	opacity: .35;
}
.ssf-dt-grid .ssf-day.ssf-today {
	outline: 1px solid var(--accent, #3574f0);
}
.ssf-dt-grid .ssf-day.ssf-selected {
	background: var(--accent, #3574f0);
	color: #fff;
}
.ssf-dt-foot {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: .35em;
	margin-top: .45em;
	padding-top: .4em;
	border-top: 1px solid var(--border, #888);
}
.ssf-dt-foot label {
	display: inline-flex;
	align-items: center;
	gap: .35em;
}
.ssf-dt-foot input[type="text"] {
	width: 7.5em;
	padding: .2em .35em;
	border: 1px solid var(--border, #888);
	border-radius: var(--radius-sm, 4px);
	background: var(--bg, #fff);
	color: inherit;
	font: inherit;
}
.ssf-dt-foot .ssf-dt-actions {
	display: inline-flex;
	gap: .35em;
}
.ssf-dt-foot .ssf-dt-actions button {
	padding: .25em .55em;
	border: 1px solid var(--border, #888);
	border-radius: var(--radius-sm, 4px);
	background: transparent;
	color: inherit;
}
.ssf-combo {
	flex-direction: column;
	min-width: min(16em, calc(100vw - 16px));
	max-width: min(28em, calc(100vw - 16px));
	max-height: min(14em, calc(100vh - 24px));
	padding: .2rem;
}
.ssf-combo-list {
	flex: 1 1 auto;
	overflow: auto;
	margin: 0;
	padding: .1rem 0;
	list-style: none;
	overscroll-behavior: contain;
	scrollbar-width: thin;
}
.ssf-combo-list button {
	display: block;
	box-sizing: border-box;
	width: calc(100% - .25rem);
	margin: 0 .125rem;
	padding: .4em .55em;
	border: 0;
	border-radius: .35rem;
	background: transparent;
	color: inherit;
	font: inherit;
	text-align: left;
	cursor: pointer;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}
.ssf-combo-list button:hover,
.ssf-combo-list button[aria-selected="true"] {
	background: color-mix(in srgb, var(--accent, #3574f0) 18%, transparent);
}
.ssf-combo-empty,
.ssf-combo-loading {
	padding: .5em .65em;
	font-size: .85em;
	color: var(--muted, #888);
}
#fieldset-search input[name$="[val]"].ssf-combo-open {
	border-color: var(--accent, #3574f0);
	box-shadow: 0 0 0 2px color-mix(in srgb, var(--accent, #3574f0) 22%, transparent);
}
</style>
<script<?php echo Adminer\nonce(); ?>>
(() => {
	const COLUMN_META = <?php echo $metaJson; ?>;
	const TZ_OFFSET = <?php echo json_encode(self::TZ_OFFSET); ?>;
	const PORTAL_DT = 'ssf-dt-portal';
	const PORTAL_COMBO = 'ssf-combo-portal';
	const NULL_OPS = new Set(['IS NULL', 'IS NOT NULL']);
	const RELATIVE_PRESETS = new Set([
		'last-24h', 'last-7d', 'last-30d', 'last-90d', 'last-1y',
		'this-week', 'last-week', 'this-month', 'last-month',
	]);

	const PRESETS = [
		{ id: 'today', label: 'today' },
		{ id: 'yesterday', label: 'yesterday' },
		{ id: 'last-24h', label: 'last 24h' },
		{ id: 'last-7d', label: 'last 7 days' },
		{ id: 'last-30d', label: 'last 30 days' },
		{ id: 'last-90d', label: 'last 90 days' },
		{ id: 'last-1y', label: 'last 1 year' },
		{ id: 'this-week', label: 'this week' },
		{ id: 'last-week', label: 'last week' },
		{ id: 'this-month', label: 'this month' },
		{ id: 'last-month', label: 'last month' },
	];

	let activePortal = null;
	let activeAnchor = null;
	let activeRow = null;
	let activeCombo = null;
	let calView = new Date();
	let calSelected = null;
	let ignoreScrollUntil = 0;
	let ignoreClickUntil = 0;
	let initialized = false;
	let comboBlurTimer = null;
	const distinctCache = new Map();
	const valuesCache = new Map();

	const pad = (n) => String(n).padStart(2, '0');
	const startOfDay = (d) => new Date(d.getFullYear(), d.getMonth(), d.getDate());
	const addDays = (d, n) => {
		const x = new Date(d);
		x.setDate(x.getDate() + n);
		return x;
	};
	const startOfWeek = (d) => {
		const x = startOfDay(d);
		const day = x.getDay();
		return addDays(x, day === 0 ? -6 : 1 - day);
	};
	const startOfMonth = (d) => new Date(d.getFullYear(), d.getMonth(), 1);

	const closePortal = () => {
		document.getElementById(PORTAL_DT)?.remove();
		activePortal = null;
		activeAnchor = null;
		activeRow = null;
	};

	const closeCombo = () => {
		document.getElementById(PORTAL_COMBO)?.remove();
		const val = activeCombo?.val;
		if (val) {
			val.classList.remove('ssf-combo-open');
		}
		activeCombo = null;
	};

	const closeAll = () => {
		closePortal();
		closeCombo();
	};

	const repositionPortals = () => {
		if (activePortal && activeAnchor) {
			placePortal(activePortal, activeAnchor);
		}
		const comboEl = document.getElementById(PORTAL_COMBO);
		if (comboEl && activeCombo?.val) {
			placePortal(comboEl, activeCombo.val);
		}
	};

	const rowControls = (row) => ({
		col: row.querySelector('select[name$="[col]"]'),
		op: row.querySelector('select[name$="[op]"]'),
		val: row.querySelector('input[name$="[val]"]'),
	});

	const columnName = (row) => {
		const col = rowControls(row).col;
		return col ? col.value : '';
	};

	const metaForRow = (row) => {
		const name = columnName(row);
		return name ? (COLUMN_META[name] || { kind: 'plain' }) : null;
	};

	const isNullOpRow = (row) => {
		const op = rowControls(row).op;
		return op && NULL_OPS.has(op.value);
	};

	const temporalKind = (kind) => ['date', 'datetime', 'time'].includes(kind);

	/**
	 * Value suggestions are OFF.
	 *
	 * The dropdown of existing column values (uuids, item names) caused more
	 * trouble than it saved: it covered the results, fought the auto-apply
	 * reload, and rendered over itself. The value box is now just an input -
	 * type freely, and use the date picker for temporal columns. Flip this to
	 * the old body to bring the list back; every call site still honours it.
	 */
	const hasSuggest = () => false;

	const assistIcon = (meta) => {
		if (!meta) {
			return '';
		}
		if (temporalKind(meta.kind)) {
			return '\uD83D\uDCC5';
		}
		if (meta.kind === 'interval') {
			return '\u23F1';
		}
		return '\u25BE';
	};

	const assistTitle = (meta) => {
		if (!meta) {
			return '';
		}
		if (temporalKind(meta.kind)) {
			return 'Date/time assist (optional — free text still works)';
		}
		if (meta.kind === 'interval') {
			return 'Interval suggestions';
		}
		return 'Value suggestions (custom input allowed)';
	};

	const placeholderFor = (meta) => {
		if (!meta) {
			return '';
		}
		switch (meta.kind) {
			case 'date':
				return 'YYYY-MM-DD';
			case 'datetime':
				return meta.tz ? 'YYYY-MM-DD HH:mm:ss+07' : 'YYYY-MM-DD HH:mm:ss';
			case 'time':
				return 'HH:mm:ss';
			case 'interval':
				return 'e.g. 7 days, 1 month';
			case 'bool':
				return 'true / false';
			default:
				return '';
		}
	};

	const formatDateTime = (d, meta) => {
		const y = d.getFullYear();
		const mo = pad(d.getMonth() + 1);
		const day = pad(d.getDate());
		const h = pad(d.getHours());
		const mi = pad(d.getMinutes());
		const s = pad(d.getSeconds());
		if (meta.kind === 'date') {
			return `${y}-${mo}-${day}`;
		}
		if (meta.kind === 'time') {
			return `${h}:${mi}:${s}`;
		}
		const base = `${y}-${mo}-${day} ${h}:${mi}:${s}`;
		return meta.tz ? `${base}${TZ_OFFSET.replace(':00', '')}` : base;
	};

	const presetDate = (id) => {
		const now = new Date();
		switch (id) {
			case 'today':
				return startOfDay(now);
			case 'yesterday':
				return startOfDay(addDays(now, -1));
			case 'last-24h':
				return new Date(now.getTime() - 24 * 60 * 60 * 1000);
			case 'last-7d':
				return new Date(now.getTime() - 7 * 24 * 60 * 60 * 1000);
			case 'last-30d':
				return new Date(now.getTime() - 30 * 24 * 60 * 60 * 1000);
			case 'last-90d':
				return new Date(now.getTime() - 90 * 24 * 60 * 60 * 1000);
			case 'last-1y':
				return new Date(now.getTime() - 365 * 24 * 60 * 60 * 1000);
			case 'this-week':
				return startOfWeek(now);
			case 'last-week':
				return startOfWeek(addDays(now, -7));
			case 'this-month':
				return startOfMonth(now);
			case 'last-month':
				return startOfMonth(new Date(now.getFullYear(), now.getMonth() - 1, 1));
			default:
				return now;
		}
	};

	const notifyInput = (input) => {
		input.dispatchEvent(new Event('input', { bubbles: true, cancelable: true }));
	};

	const maybeSetCompareOp = (row, presetId) => {
		if (!RELATIVE_PRESETS.has(presetId)) {
			return;
		}
		const op = rowControls(row).op;
		if (!op) {
			return;
		}
		if (op.value === '=' || op.value === 'LIKE' || op.value === 'LIKE %%'
			|| op.value === 'ILIKE' || op.value === 'ILIKE %%') {
			op.value = '>=';
			op.dispatchEvent(new Event('change', { bubbles: true, cancelable: true }));
		}
	};

	const applyValue = (row, value, presetId = '') => {
		const { val } = rowControls(row);
		if (!val || isNullOpRow(row)) {
			return;
		}
		if (presetId) {
			maybeSetCompareOp(row, presetId);
		}
		val.value = value;
		validateInput(val, metaForRow(row));
		notifyInput(val);
		try {
			val.focus({ preventScroll: true });
		} catch (_) {
			val.focus();
		}
	};

	const validateInput = (input, meta) => {
		if (!input || !meta) {
			input?.classList.remove('ssf-invalid');
			return;
		}
		const v = (input.value || '').trim();
		if (v === '' || meta.kind === 'plain' || meta.kind === 'enum'
			|| meta.kind === 'check' || meta.kind === 'bool' || meta.kind === 'interval') {
			input.classList.remove('ssf-invalid');
			return;
		}
		let ok = true;
		if (meta.kind === 'date') {
			ok = /^\d{4}-\d{2}-\d{2}$/.test(v);
		} else if (meta.kind === 'time') {
			ok = /^\d{2}:\d{2}(:\d{2}(\.\d+)?)?$/.test(v);
		} else if (meta.kind === 'datetime') {
			// Accept PG-style timestamps including fractional seconds
			ok = /^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2}(\.\d+)?)?([+-]\d{2}(:\d{2})?|Z)?$/.test(v)
				|| /^\d{4}-\d{2}-\d{2}$/.test(v);
		}
		input.classList.toggle('ssf-invalid', !ok);
	};

	const parseInputDate = (raw, meta) => {
		const v = (raw || '').trim();
		if (!v) {
			return null;
		}
		if (meta.kind === 'date') {
			const m = v.match(/^(\d{4})-(\d{2})-(\d{2})/);
			return m ? new Date(+m[1], +m[2] - 1, +m[3]) : null;
		}
		if (meta.kind === 'datetime') {
			const m = v.match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?/);
			if (!m) {
				return null;
			}
			return new Date(+m[1], +m[2] - 1, +m[3], +(m[4] || 0), +(m[5] || 0), +(m[6] || 0));
		}
		return null;
	};

	const placePortal = (portal, anchor) => {
		const r = anchor.getBoundingClientRect();
		const pad = 8;
		portal.style.left = '0px';
		portal.style.top = '0px';
		document.body.appendChild(portal);
		const pr = portal.getBoundingClientRect();
		let left = r.left;
		let top = r.bottom + 2;
		if (left + pr.width > window.innerWidth - pad) {
			left = Math.max(pad, window.innerWidth - pr.width - pad);
		}
		if (top + pr.height > window.innerHeight - pad) {
			top = Math.max(pad, r.top - pr.height - 2);
		}
		portal.style.left = left + 'px';
		portal.style.top = top + 'px';
	};

	const monthLabel = (d) => d.toLocaleString('en-US', { month: 'long', year: 'numeric' });

	const renderCalendar = (host, meta, row) => {
		host.innerHTML = '';
		const nav = document.createElement('div');
		nav.className = 'ssf-dt-nav';
		const prev = document.createElement('button');
		prev.type = 'button';
		prev.textContent = '\u2039';
		const next = document.createElement('button');
		next.type = 'button';
		next.textContent = '\u203A';
		const title = document.createElement('span');
		title.textContent = monthLabel(calView);
		nav.append(prev, title, next);
		host.append(nav);

		const grid = document.createElement('div');
		grid.className = 'ssf-dt-grid';
		for (const dow of ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su']) {
			const el = document.createElement('div');
			el.className = 'ssf-dow';
			el.textContent = dow;
			grid.append(el);
		}

		const first = new Date(calView.getFullYear(), calView.getMonth(), 1);
		const start = startOfWeek(first);
		const today = startOfDay(new Date());
		for (let i = 0; i < 42; i++) {
			const day = addDays(start, i);
			const btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'ssf-day';
			btn.textContent = String(day.getDate());
			if (day.getMonth() !== calView.getMonth()) {
				btn.classList.add('ssf-out');
			}
			if (+startOfDay(day) === +today) {
				btn.classList.add('ssf-today');
			}
			if (calSelected && +startOfDay(day) === +startOfDay(calSelected)) {
				btn.classList.add('ssf-selected');
			}
			btn.addEventListener('click', (e) => {
				e.preventDefault();
				calSelected = new Date(day);
				if (meta.kind !== 'time') {
					const timeInput = activePortal?.querySelector('.ssf-dt-time-input');
					const parts = (timeInput?.value || '00:00:00').split(':');
					calSelected.setHours(+parts[0] || 0, +parts[1] || 0, +parts[2] || 0, 0);
					applyValue(row, formatDateTime(calSelected, meta));
					// Picking a day IS the decision - close. Re-rendering the
					// calendar and leaving it open meant the picker sat over the
					// results you just asked for, and had to be dismissed by hand.
					closePortal();
					return;
				}
				renderCalendar(host, meta, row);
			});
			grid.append(btn);
		}
		host.append(grid);

		prev.addEventListener('click', (e) => {
			e.preventDefault();
			calView = new Date(calView.getFullYear(), calView.getMonth() - 1, 1);
			renderCalendar(host, meta, row);
		});
		next.addEventListener('click', (e) => {
			e.preventDefault();
			calView = new Date(calView.getFullYear(), calView.getMonth() + 1, 1);
			renderCalendar(host, meta, row);
		});
	};

	const openDatePortal = (anchor, row, meta) => {
		closePortal();
		activeAnchor = anchor;
		activeRow = row;

		const { val } = rowControls(row);
		const parsed = parseInputDate(val?.value, meta);
		calView = parsed ? new Date(parsed) : new Date();
		calSelected = parsed ? new Date(parsed) : null;

		const portal = document.createElement('div');
		portal.id = PORTAL_DT;
		portal.className = 'ssf-portal ssf-dt';
		portal.setAttribute('role', 'dialog');
		portal.setAttribute('aria-label', 'Date/time assist');

		const presets = document.createElement('div');
		presets.className = 'ssf-dt-presets';
		for (const p of PRESETS) {
			if (meta.kind === 'time' && !['today', 'yesterday'].includes(p.id)) {
				continue;
			}
			const btn = document.createElement('button');
			btn.type = 'button';
			btn.textContent = p.label;
			btn.addEventListener('click', (e) => {
				e.preventDefault();
				applyValue(row, formatDateTime(presetDate(p.id), meta), p.id);
				closePortal();
			});
			presets.append(btn);
		}

		if (meta.kind === 'interval') {
			for (const label of ['7 days', '30 days', '90 days', '1 year']) {
				const btn = document.createElement('button');
				btn.type = 'button';
				btn.textContent = label;
				btn.addEventListener('click', (e) => {
					e.preventDefault();
					applyValue(row, label);
					closePortal();
				});
				presets.append(btn);
			}
		}

		const main = document.createElement('div');
		main.className = 'ssf-dt-main';

		if (meta.kind !== 'interval') {
			const calHost = document.createElement('div');
			calHost.className = 'ssf-dt-cal';
			renderCalendar(calHost, meta, row);
			main.append(calHost);

			if (meta.kind !== 'date') {
				const foot = document.createElement('div');
				foot.className = 'ssf-dt-foot';
				const label = document.createElement('label');
				label.textContent = 'time ';
				const timeInput = document.createElement('input');
				timeInput.type = 'text';
				timeInput.className = 'ssf-dt-time-input';
				timeInput.value = '00:00:00';
				timeInput.placeholder = 'HH:mm:ss';
				label.append(timeInput);
				const actions = document.createElement('div');
				actions.className = 'ssf-dt-actions';
				const applyBtn = document.createElement('button');
				applyBtn.type = 'button';
				applyBtn.textContent = 'apply time';
				applyBtn.addEventListener('click', (e) => {
					e.preventDefault();
					const base = calSelected || new Date();
					const parts = (timeInput.value || '00:00:00').split(':');
					base.setHours(+parts[0] || 0, +parts[1] || 0, +parts[2] || 0, 0);
					applyValue(row, formatDateTime(base, meta));
				});
				const clearBtn = document.createElement('button');
				clearBtn.type = 'button';
				clearBtn.textContent = 'clear';
				clearBtn.addEventListener('click', (e) => {
					e.preventDefault();
					applyValue(row, '');
					closePortal();
				});
				actions.append(applyBtn, clearBtn);
				foot.append(label, actions);
				main.append(foot);
			} else {
				const foot = document.createElement('div');
				foot.className = 'ssf-dt-foot';
				const clearBtn = document.createElement('button');
				clearBtn.type = 'button';
				clearBtn.textContent = 'clear';
				clearBtn.addEventListener('click', (e) => {
					e.preventDefault();
					applyValue(row, '');
					closePortal();
				});
				foot.append(clearBtn);
				main.append(foot);
			}
		}

		portal.append(presets, main);
		activePortal = portal;
		placePortal(portal, anchor);
		ignoreScrollUntil = Date.now() + 400;
		ignoreClickUntil = Date.now() + 250;
	};

	const fetchDistinct = async (column, query = '') => {
		const key = column + '\u0000' + query;
		if (distinctCache.has(key)) {
			return distinctCache.get(key);
		}
		const url = new URL(location.href);
		url.searchParams.set('ssf_distinct', column);
		if (query) {
			url.searchParams.set('ssf_q', query);
		}
		url.searchParams.delete('page');
		url.searchParams.delete('next');
		let list = [];
		try {
			const res = await fetch(url.toString(), { credentials: 'same-origin' });
			if (res.ok) {
				const data = await res.json();
				list = Array.isArray(data) ? data : [];
			}
		} catch (_) {
			return [];
		}
		distinctCache.set(key, list);
		return list;
	};

	const valuesForMeta = async (meta, column, query = '') => {
		// enum/check/bool are closed sets already in memory - never re-query.
		if (meta.kind === 'enum' || meta.kind === 'check' || meta.kind === 'bool') {
			const cacheKey = `${column}:${meta.kind}`;
			if (!valuesCache.has(cacheKey)) {
				valuesCache.set(cacheKey, meta.values || []);
			}
			return valuesCache.get(cacheKey);
		}
		if (meta.kind === 'plain') {
			return fetchDistinct(column, query);   // server-side filtered + cached per query
		}
		return [];
	};

	const renderCombo = (val, row, meta, values) => {
		const q = (val.value || '').trim().toLowerCase();
		const filtered = values
			.filter((v) => q === '' || String(v).toLowerCase().includes(q))
			.slice(0, 50);

		let portal = document.getElementById(PORTAL_COMBO);
		if (!portal) {
			portal = document.createElement('div');
			portal.id = PORTAL_COMBO;
			portal.className = 'ssf-portal ssf-combo';
			portal.setAttribute('role', 'listbox');
			document.body.appendChild(portal);
		}

		portal.innerHTML = '';
		const list = document.createElement('ul');
		list.className = 'ssf-combo-list';

		if (!filtered.length) {
			const empty = document.createElement('li');
			empty.className = 'ssf-combo-empty';
			empty.textContent = values.length
				? 'No matches — use typed value'
				: 'Type to filter';
			list.append(empty);
		} else {
			const { op } = rowControls(row);
			for (const item of filtered) {
				const li = document.createElement('li');
				const btn = document.createElement('button');
				btn.type = 'button';
				btn.textContent = String(item);
				btn.addEventListener('mousedown', (e) => {
					e.preventDefault();
					if (op && op.value === 'IN') {
						const cur = (val.value || '').trim();
						val.value = cur ? `${cur}, ${item}` : String(item);
					} else {
						val.value = String(item);
					}
					notifyInput(val);
					closeCombo();
				});
				li.append(btn);
				list.append(li);
			}
		}

		portal.append(list);
		placePortal(portal, val);
		val.classList.add('ssf-combo-open');
		activeCombo = { val, row, meta, values };
		ignoreScrollUntil = Date.now() + 300;
	};

	const updateCombo = async (val, row) => {
		if (!val || !row || isNullOpRow(row)) {
			closeCombo();
			return;
		}
		const meta = metaForRow(row);
		if (!meta || !hasSuggest(meta)) {
			closeCombo();
			return;
		}
		const column = columnName(row);
		if (!column) {
			closeCombo();
			return;
		}
		let portal = document.getElementById(PORTAL_COMBO);
		if (!portal) {
			portal = document.createElement('div');
			portal.id = PORTAL_COMBO;
			portal.className = 'ssf-portal ssf-combo';
			portal.innerHTML = '<div class="ssf-combo-loading">Loading…</div>';
			document.body.appendChild(portal);
			placePortal(portal, val);
			val.classList.add('ssf-combo-open');
		}
		const typed = (val.value || '').trim();
		const values = await valuesForMeta(meta, column, typed);
		// A stale response must not overwrite a newer keystroke's list.
		if ((val.value || '').trim() !== typed) {
			return;
		}
		renderCombo(val, row, meta, values);
	};

	const openAssist = (anchor, row) => {
		const meta = metaForRow(row);
		if (!meta || isNullOpRow(row)) {
			return;
		}
		if (temporalKind(meta.kind) || meta.kind === 'interval') {
			closeCombo();
			openDatePortal(anchor, row, meta);
		}
	};

	const updateBoolChips = (row, meta, cell) => {
		let chips = cell.querySelector('.ssf-bool-chips');
		if (!meta || meta.kind !== 'bool' || isNullOpRow(row)) {
			chips?.remove();
			return;
		}
		if (!chips) {
			chips = document.createElement('span');
			chips.className = 'ssf-bool-chips';
			cell.append(chips);
		}
		if (chips.dataset.ssfBuilt === '1') {
			return;
		}
		chips.dataset.ssfBuilt = '1';
		chips.innerHTML = '';
		for (const v of ['true', 'false']) {
			const btn = document.createElement('button');
			btn.type = 'button';
			btn.textContent = v;
			chips.append(btn);
		}
	};

	const syncRow = (row) => {
		if (!row?.querySelector('input[name$="[val]"]')) {
			return;
		}
		const { val, col } = rowControls(row);
		if (!val) {
			return;
		}

		// The value input MUST remain a DIRECT child of the row <div>.
		// Adminer's own handlers are:
		//   selectFirstChange()  { fire(this.parentNode.firstChild, 'change'); }
		//   selectSearchSearch() { if(!this.value) this.parentNode.firstChild.selectedIndex = 0; }
		// i.e. they require parentNode.firstChild to be the where[n][col]
		// <select>. Wrapping the input in a <span> broke that contract, so
		// Adminer never registered the field as changed, the field stayed "at
		// its data-default", and it was dropped from the GET submit — search
		// silently did nothing and the typed value was wiped on reload.
		// The assist UI therefore lives in a SIBLING span placed after the input.
		if (val.parentNode !== row) {
			row.insertBefore(val, val.parentNode);   // repair markup from older builds
		}
		let cell = row.querySelector(':scope > .ssf-val-cell');
		if (!cell) {
			cell = document.createElement('span');
			cell.className = 'ssf-val-cell';
			val.insertAdjacentElement('afterend', cell);
		}

		let btn = cell.querySelector('.ssf-assist-btn');
		if (!btn) {
			btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'ssf-assist-btn';
			cell.append(btn);
		}

		const meta = metaForRow(row);
		const showDateBtn = !!col?.value && !isNullOpRow(row) && (
			temporalKind(meta?.kind) || meta?.kind === 'interval'
		);

		btn.hidden = !showDateBtn;
		btn.textContent = assistIcon(meta);
		btn.title = assistTitle(meta);

		val.placeholder = isNullOpRow(row) ? '' : (placeholderFor(meta) || '');
		val.classList.toggle('ssf-combo-input', !!col?.value && !isNullOpRow(row) && hasSuggest(meta));

		updateBoolChips(row, meta, cell);
		validateInput(val, meta);
	};

	const bootRow = (row) => {
		syncRow(row);
	};

	const bootAll = () => {
		const fieldset = document.getElementById('fieldset-search');
		if (!fieldset) {
			return;
		}
		for (const row of fieldset.children) {
			if (row.tagName === 'DIV') {
				bootRow(row);
			}
		}
	};

	const fieldsetFromEvent = (el) => {
		if (!el?.closest) {
			return null;
		}
		return el.closest('#fieldset-search > div');
	};

	const init = () => {
		const fieldset = document.getElementById('fieldset-search');
		if (!fieldset) {
			return;
		}
		bootAll();
		if (initialized) {
			return;
		}
		initialized = true;

		fieldset.addEventListener('change', (e) => {
			const row = fieldsetFromEvent(e.target);
			if (row) {
				closeCombo();
				syncRow(row);
			}
		});
		fieldset.addEventListener('focusin', (e) => {
			if (!e.target.matches('input[name$="[val]"]')) {
				return;
			}
			const row = fieldsetFromEvent(e.target);
			if (!row) {
				return;
			}
			clearTimeout(comboBlurTimer);
			const meta = metaForRow(row);
			// Date / time / interval columns open the picker on focus. Requiring a
			// click on the calendar icon first was an extra step on the single
			// most common kind of filter, and the icon is easy to miss entirely.
			// Free text still works - the picker only writes into the input.
			if (meta && !isNullOpRow(row) && (temporalKind(meta.kind) || meta.kind === 'interval')) {
				closeCombo();
				openDatePortal(e.target, row, meta);
				return;
			}
			// Everything else (text, uuid, numeric, enum, bool) autocompletes.
			updateCombo(e.target, row);
		});
		fieldset.addEventListener('input', (e) => {
			if (!e.target.matches('input[name$="[val]"]')) {
				return;
			}
			const row = fieldsetFromEvent(e.target);
			if (row) {
				validateInput(e.target, metaForRow(row));
				updateCombo(e.target, row);
			}
		});
		fieldset.addEventListener('focusout', (e) => {
			if (!e.target.matches('input[name$="[val]"]')) {
				return;
			}
			comboBlurTimer = setTimeout(() => {
				const comboEl = document.getElementById(PORTAL_COMBO);
				if (comboEl && document.activeElement && comboEl.contains(document.activeElement)) {
					return;
				}
				closeCombo();
			}, 160);
		});
		new MutationObserver(() => requestAnimationFrame(bootAll))
			.observe(fieldset, { childList: true, subtree: true });
	};

	// Document capture: open assist + close portal (works even when script runs in <head>)
	document.addEventListener('click', (e) => {
		const assist = e.target.closest('#fieldset-search .ssf-assist-btn');
		if (assist && !assist.hidden) {
			e.preventDefault();
			e.stopPropagation();
			const row = fieldsetFromEvent(assist);
			if (row) {
				openAssist(assist, row);
			}
			return;
		}

		const chip = e.target.closest('#fieldset-search .ssf-bool-chips button');
		if (chip) {
			e.preventDefault();
			e.stopPropagation();
			const row = fieldsetFromEvent(chip);
			if (row) {
				applyValue(row, chip.textContent || '');
			}
			return;
		}

		if (Date.now() < ignoreClickUntil) {
			return;
		}
		const comboEl = document.getElementById(PORTAL_COMBO);
		if (comboEl && comboEl.contains(e.target)) {
			return;
		}
		if (activeCombo?.val && !e.target.closest('#fieldset-search .ssf-combo-input')) {
			closeCombo();
		}
		if (!activePortal) {
			return;
		}
		if (activePortal.contains(e.target) || activeAnchor?.contains(e.target)) {
			return;
		}
		closePortal();
	}, true);

	document.addEventListener('keydown', (e) => {
		if (e.key === 'Escape') {
			closeAll();
		}
	});

	window.addEventListener('scroll', (e) => {
		if (Date.now() < ignoreScrollUntil) {
			return;
		}
		const comboEl = document.getElementById(PORTAL_COMBO);
		if (e.target instanceof Element) {
			if (comboEl && (comboEl === e.target || comboEl.contains(e.target))) {
				return;
			}
			if (activePortal && (activePortal === e.target || activePortal.contains(e.target))) {
				return;
			}
		}
		repositionPortals();
	}, true);

	window.addEventListener('resize', () => {
		repositionPortals();
	});

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
</script>
		<?php
	}

	/** @param list<string> $values */
	private function jsonResponse(array $values): void {
		header('Content-Type: application/json; charset=utf-8');
		echo json_encode($values, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
		exit;
	}

	/** @param array<string, array<string, mixed>> $fields */
	private function buildColumnMeta(string $table, array $fields): array {
		$meta = array();
		$enumTypes = $this->loadEnumTypes();
		$checkMap = $this->loadCheckConstraints($table);

		foreach ($fields as $name => $field) {
			$type = (string) ($field['type'] ?? '');
			$fullType = (string) ($field['full_type'] ?? $type);
			$entry = array('kind' => 'plain', 'pgType' => $fullType, 'tz' => false);

			if (preg_match('~^date$~', $type)) {
				$entry['kind'] = 'date';
			} elseif (preg_match('~timestamp~', $type) || preg_match('~timestamp~i', $fullType)) {
				$entry['kind'] = 'datetime';
				$entry['tz'] = (bool) preg_match('~tz|with time zone~i', $fullType);
			} elseif (preg_match('~^time~', $type) || preg_match('~^time~i', $fullType)) {
				$entry['kind'] = 'time';
				$entry['tz'] = (bool) preg_match('~tz|with time zone~i', $fullType);
			} elseif (preg_match('~^interval$~', $type)) {
				$entry['kind'] = 'interval';
			} elseif ($type === 'bool' || preg_match('~boolean~i', $fullType)) {
				$entry['kind'] = 'bool';
				$entry['values'] = array('true', 'false', 't', 'f', '1', '0');
			} elseif (isset($enumTypes[$type])) {
				$entry['kind'] = 'enum';
				$entry['values'] = $enumTypes[$type];
			} elseif (isset($checkMap[$name])) {
				$entry['kind'] = 'check';
				$entry['values'] = $checkMap[$name];
			}

			$meta[$name] = $entry;
		}

		return $meta;
	}

	/** @return array<string, list<string>> */
	private function loadEnumTypes(): array {
		$schema = $this->currentSchema();
		$sql = "
			SELECT t.typname, e.enumlabel
			FROM pg_type t
			JOIN pg_enum e ON e.enumtypid = t.oid
			JOIN pg_namespace n ON n.oid = t.typnamespace
			WHERE n.nspname = " . Adminer\q($schema) . "
			ORDER BY t.typname, e.enumsortorder
		";
		$result = $this->safeQuery($sql);
		if (!$result) {
			return array();
		}

		$map = array();
		while ($row = $result->fetch_row()) {
			$type = (string) $row[0];
			$map[$type][] = (string) $row[1];
		}
		return $map;
	}

	/** @return array<string, list<string>> */
	private function loadCheckConstraints(string $table): array {
		$schema = $this->currentSchema();
		$sql = "
			SELECT a.attname, pg_get_constraintdef(c.oid) AS def
			FROM pg_constraint c
			JOIN pg_class rel ON rel.oid = c.conrelid
			JOIN pg_namespace n ON n.oid = rel.relnamespace
			JOIN pg_attribute a ON a.attrelid = rel.oid
				AND a.attnum = ANY (c.conkey) AND NOT a.attisdropped
			WHERE c.contype = 'c'
				AND n.nspname = " . Adminer\q($schema) . "
				AND rel.relname = " . Adminer\q($table) . "
		";
		$result = $this->safeQuery($sql);
		if (!$result) {
			return array();
		}

		$map = array();
		while ($row = $result->fetch_row()) {
			$values = $this->parseCheckInList((string) $row[1]);
			if ($values !== null) {
				$map[(string) $row[0]] = $values;
			}
		}
		return $map;
	}

	/** @return list<string>|null */
	private function parseCheckInList(string $definition): ?array {
		if (!preg_match('/\bIN\s*\(([^)]+)\)/i', $definition, $match)) {
			return null;
		}
		if (!preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $match[1], $labels)) {
			return null;
		}
		return $labels[1] ?: null;
	}

	/** Neutralise LIKE wildcards so a typed % or _ matches literally. */
	private function escapeLike(string $value): string {
		return str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), $value);
	}

	/** @return list<string> */
	private function fetchDistinct(string $table, string $column, string $query = ''): array {
		$fields = $this->safeFields($table);
		if (!isset($fields[$column])) {
			return array();
		}

		$field = $fields[$column];
		$typeInfo = $field['type'] . ' ' . ($field['full_type'] ?? '');
		if (preg_match('~date|timestamp|time|interval|bool|bytea~i', $typeInfo)) {
			return array();
		}

		// Filter in SQL, not in the browser. Previously the endpoint returned the
		// alphabetically-first DISTINCT_LIMIT values and the client filtered that
		// cached slice - so on a high-cardinality column (uuid, item name) a value
		// that exists but sorts past the first 50 could never be suggested, however
		// closely you typed it. Pushing the match into the query fixes that.
		$where = Adminer\idf_escape($column) . " IS NOT NULL";
		$query = trim($query);
		if ($query !== '') {
			// CAST so this also works for uuid/numeric/date columns, which have
			// no ILIKE operator of their own in PostgreSQL.
			$where .= " AND CAST(" . Adminer\idf_escape($column) . " AS text) ILIKE "
				. Adminer\q('%' . $this->escapeLike($query) . '%');
		}

		$sql = "SELECT DISTINCT " . Adminer\idf_escape($column)
			. " FROM " . Adminer\table($table)
			. " WHERE " . $where
			. " ORDER BY 1 LIMIT " . self::DISTINCT_LIMIT;

		$result = $this->safeQuery($sql);
		if (!$result) {
			return array();
		}

		$values = array();
		while ($row = $result->fetch_row()) {
			if ($row[0] !== null) {
				$values[] = (string) $row[0];
			}
		}
		return $values;
	}

	private function currentSchema(): string {
		$ns = $_GET['ns'] ?? '';
		return $ns !== '' ? (string) $ns : 'public';
	}

	protected $translations = array(
		'en' => array('' => 'Smart optional assists for Select search filters'),
		'vi' => array('' => 'Hỗ trợ thông minh (tùy chọn) cho bộ lọc Select'),
	);
}

return new AdminerSelectSmartFilter();
