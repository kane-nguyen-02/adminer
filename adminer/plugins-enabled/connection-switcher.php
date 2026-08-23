<?php

/**
 * Switch between recently used connections without going back to the login page.
 *
 * PERFORMANCE - this is the reason for the design. Listing "available
 * connections" properly would mean querying each server, which is exactly the
 * kind of work that makes a console feel slow, and it would run on EVERY page
 * load. So this plugin issues **no queries at all** and adds **no server-side
 * work**: it records the connection you are already on into localStorage and
 * renders that history as links. Cost is one small localStorage read per page
 * and zero network. The menu contents are built on open, not on page load.
 *
 * Placement is also a performance choice: the control goes into the
 * username/Logout cluster in #foot, which sits outside #content. Anything
 * inserted there cannot reflow the result grid - putting it in the sidebar's
 * table list or above the toolbar would.
 *
 * No passwords are ever stored. Each entry is only enough to rebuild the URL
 * (driver, server, username, db, ns); Adminer's own permanent-login cookie
 * decides whether you land straight in or are asked to authenticate again.
 */
final class AdminerConnectionSwitcher extends Adminer\Plugin {
	private const STORE = 'adminer_connections';
	private const MAX = 8;

	function head($dark = null) {
		// Only useful once connected; on the login page Adminer shows #logins.
		if (!isset($_GET['username'])) {
			return;
		}
		?>
<style<?php echo Adminer\nonce(); ?>>
.cs-wrap {
	position: relative;
	display: inline-flex;
	align-items: center;
}
.cs-btn {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	max-width: 22em;
	padding: 3px 8px;
	border: 1px solid var(--border);
	border-radius: var(--radius-sm);
	background: transparent;
	color: var(--fg);
	font: inherit;
	font-size: 11.5px;
	cursor: pointer;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}
.cs-btn:hover,
.cs-btn:focus-visible {
	border-color: var(--accent);
	color: var(--accent);
	outline: none;
}
.cs-ico {
	width: 14px;
	height: 14px;
	flex: 0 0 auto;
	stroke: currentColor;
	fill: none;
	stroke-width: 2;
	stroke-linecap: round;
	stroke-linejoin: round;
	pointer-events: none;
}
.cs-menu {
	position: absolute;
	top: calc(100% + 4px);
	right: 0;
	z-index: 10046;
	min-width: 20em;
	max-width: min(34em, 92vw);
	max-height: 60vh;
	overflow: auto;
	margin: 0;
	padding: 4px;
	border: 1px solid var(--border);
	border-radius: var(--radius);
	background: var(--bg);
	box-shadow: 0 12px 34px -12px rgba(0, 0, 0, .5);
	text-align: left;
}
.cs-item {
	display: flex;
	flex-direction: column;
	gap: 1px;
	width: 100%;
	box-sizing: border-box;
	padding: 6px 8px;
	border: 0;
	border-radius: var(--radius-sm);
	background: transparent;
	color: var(--fg);
	font: inherit;
	font-size: 12px;
	text-align: left;
	text-decoration: none;
	cursor: pointer;
}
.cs-item:hover,
.cs-item:focus-visible {
	background: color-mix(in srgb, var(--accent) 16%, transparent);
	outline: none;
}
.cs-item[aria-current="true"] {
	background: color-mix(in srgb, var(--accent) 22%, transparent);
}
.cs-item-main {
	font-family: var(--mono-stack, monospace);
	font-size: 11.5px;
}
.cs-item-sub {
	color: var(--muted);
	font-size: 10.5px;
}
.cs-sep {
	height: 1px;
	margin: 4px 2px;
	border: 0;
	background: var(--border);
}
.cs-empty {
	padding: 6px 8px;
	color: var(--muted);
	font-size: 11.5px;
}
</style>
<script<?php echo Adminer\nonce(); ?>>
(() => {
	const STORE = <?php echo json_encode(self::STORE); ?>;
	const MAX = <?php echo (int) self::MAX; ?>;

	// lucide: database / plus
	const ICON_DB = '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5V19A9 3 0 0 0 21 19V5"/><path d="M3 12A9 3 0 0 0 21 12"/>';

	const svgFor = (paths) => {
		const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
		svg.setAttribute('viewBox', '0 0 24 24');
		svg.setAttribute('aria-hidden', 'true');
		svg.setAttribute('class', 'cs-ico');
		svg.innerHTML = paths;
		return svg;
	};

	/** Adminer puts the driver in the query string as its own key, e.g. ?pgsql=host */
	const DRIVERS = ['server', 'pgsql', 'sqlite', 'mssql', 'oracle', 'mongo', 'redis', 'elastic', 'clickhouse', 'firebird', 'simpledb'];

	const currentConnection = () => {
		const q = new URLSearchParams(location.search);
		const driver = DRIVERS.find((d) => q.has(d));
		if (!driver || !q.has('username')) {
			return null;
		}
		return {
			driver: driver,
			server: q.get(driver) || '',
			username: q.get('username') || '',
			db: q.get('db') || '',
			ns: q.get('ns') || '',
		};
	};

	const keyOf = (c) => [c.driver, c.server, c.username, c.db].join(' ');

	const read = () => {
		try {
			const raw = JSON.parse(localStorage.getItem(STORE) || '[]');
			return Array.isArray(raw) ? raw.filter((c) => c && c.driver && c.username) : [];
		} catch (_) {
			return [];
		}
	};

	const remember = (conn) => {
		if (!conn) {
			return read();
		}
		const list = read().filter((c) => keyOf(c) !== keyOf(conn));
		list.unshift(Object.assign({}, conn, { ts: Date.now() }));
		const trimmed = list.slice(0, MAX);
		try {
			localStorage.setItem(STORE, JSON.stringify(trimmed));
		} catch (_) {
			/* storage full or blocked: the menu just will not persist */
		}
		return trimmed;
	};

	const urlFor = (c) => {
		const q = new URLSearchParams();
		q.set(c.driver, c.server);
		q.set('username', c.username);
		if (c.db) {
			q.set('db', c.db);
		}
		if (c.ns) {
			q.set('ns', c.ns);
		}
		return '?' + q.toString();
	};

	const label = (c) => (c.db ? c.db : '(no database)');
	const sub = (c) => c.username + '@' + (c.server || 'default') + '  ·  ' + c.driver;

	const boot = () => {
		const current = currentConnection();
		const list = remember(current);

		const logout = document.querySelector('#foot button[name="logout"], #foot input[name="logout"]');
		const host = logout ? logout.closest('p') : null;
		if (!host) {
			return;
		}

		const wrap = document.createElement('span');
		wrap.className = 'cs-wrap';

		const btn = document.createElement('button');
		btn.type = 'button';
		btn.className = 'cs-btn';
		btn.append(svgFor(ICON_DB));
		const btnText = document.createElement('span');
		btnText.textContent = current ? label(current) : 'Connections';
		btn.append(btnText);
		btn.title = current ? sub(current) : 'Switch connection';
		btn.setAttribute('aria-haspopup', 'menu');
		btn.setAttribute('aria-expanded', 'false');

		const menu = document.createElement('div');
		menu.className = 'cs-menu';
		menu.setAttribute('role', 'menu');
		menu.hidden = true;

		const makeItem = (c, isCurrent) => {
			const a = document.createElement('a');
			a.className = 'cs-item';
			a.href = urlFor(c);
			a.setAttribute('role', 'menuitem');
			if (isCurrent) {
				a.setAttribute('aria-current', 'true');
			}
			const main = document.createElement('span');
			main.className = 'cs-item-main';
			main.textContent = label(c);
			const s = document.createElement('span');
			s.className = 'cs-item-sub';
			s.textContent = sub(c) + (isCurrent ? '  ·  current' : '');
			a.append(main, s);
			return a;
		};

		const build = () => {
			menu.textContent = '';
			const others = list.filter((c) => !current || keyOf(c) !== keyOf(current));
			if (current) {
				menu.append(makeItem(current, true));
			}
			if (!others.length && !current) {
				const empty = document.createElement('div');
				empty.className = 'cs-empty';
				empty.textContent = 'No other connections yet';
				menu.append(empty);
			}
			for (const c of others) {
				menu.append(makeItem(c, false));
			}
			const sep = document.createElement('hr');
			sep.className = 'cs-sep';
			menu.append(sep);
			const add = document.createElement('a');
			add.className = 'cs-item';
			add.href = './';                     // Adminer's login form
			add.setAttribute('role', 'menuitem');
			const addRow = document.createElement('span');
			addRow.className = 'cs-item-main';
			addRow.textContent = '+  New connection';
			add.append(addRow);
			menu.append(add);
		};

		const close = () => {
			menu.hidden = true;
			btn.setAttribute('aria-expanded', 'false');
		};

		btn.addEventListener('click', (e) => {
			e.preventDefault();
			if (menu.hidden) {
				build();                        // built on open, not on page load
				menu.hidden = false;
				btn.setAttribute('aria-expanded', 'true');
			} else {
				close();
			}
		});

		document.addEventListener('click', (e) => {
			if (!menu.hidden && !wrap.contains(e.target)) {
				close();
			}
		}, true);

		document.addEventListener('keydown', (e) => {
			if (e.key === 'Escape') {
				close();
			}
		});

		wrap.append(btn, menu);
		host.prepend(wrap);
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
		'en' => array('' => 'Switch between recently used connections from any page'),
		'vi' => array('' => 'Chuyển nhanh giữa các kết nối đã dùng, ở mọi trang'),
	);
}

return new AdminerConnectionSwitcher();
