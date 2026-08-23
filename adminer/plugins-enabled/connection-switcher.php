<?php

/**
 * Always-populated connection switcher with user-assigned names.
 *
 * WHY IT IS SERVER-SIDE. The first version of this plugin kept its own history
 * in localStorage. That was wrong for two reasons, both observable: a
 * connection you had never opened in the current tab was simply absent from the
 * menu, and the entries carried no password, so following one could dump you
 * back on the login form. Adminer already keeps the authoritative registry -
 * $_SESSION["pwds"] for the live session and the `adminer_permanent` cookie for
 * remembered logins - which is exactly what the core renders as the #logins
 * chips on the login page. This plugin reads that same registry, so every
 * connection Adminer can actually reach is in the menu from the first page load
 * of a fresh tab, and following an entry reuses the stored credentials.
 *
 * PERFORMANCE is unchanged from the original design and is still the reason for
 * the shape of this file: reading $_SESSION and two cookies costs nothing, so
 * there are still **no queries** and **no network requests**. The list is
 * serialised into the page as a JSON literal and the menu DOM is built on open,
 * not on page load.
 *
 * Placement is also unchanged: the control goes into the username/Logout
 * cluster, which default.css pins to the top right *outside* #content.
 * Anything inserted there cannot reflow the result grid - the sidebar table
 * list or the area above the toolbar would.
 *
 * NAMES live in the `adminer_conn_names` cookie as a JSON map, keyed by
 * Adminer's own connection key (base64(driver)-base64(server)-base64(user)-
 * base64(db)) so a name stays attached to one exact connection. A cookie
 * rather than localStorage specifically so PHP can render the name in the
 * initial HTML; with localStorage the raw host:port paints first and JS
 * rewrites it a frame later, which reads as a flicker on every navigation.
 *
 * No passwords are read, written or exposed here. The `adminer_permanent`
 * cookie is parsed for its key half only; the encrypted password half is
 * ignored and never leaves PHP.
 */
final class AdminerConnectionSwitcher extends Adminer\Plugin {
	private const NAMES_COOKIE = 'adminer_conn_names';
	private const LAST_COOKIE = 'adminer_conn_last';

	/** Query parameter that asks for the login form instead of the auto-jump. */
	private const NEW_PARAM = 'new';

	/** POST field carrying the connection key to erase. */
	private const FORGET_FIELD = 'cs_forget';

	private const MAX_NAME = 60;

	// lucide: pencil / trash-2
	private const ICON_PEN = '<path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z"/><path d="m15 5 4 4"/>';
	private const ICON_TRASH = '<path d="M10 11v6"/><path d="M14 11v6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>';

	/** @var array|null memoised so head() and serverName() agree and parse once */
	private $names = null;

	/** Adminer's own connection key, so names survive anything that rewrites the URL. */
	private static function keyOf($driver, $server, $username, $db) {
		return implode('-', array_map('base64_encode', array($driver, $server, $username, $db)));
	}

	private function names() {
		if ($this->names === null) {
			$raw = json_decode((string) $_COOKIE[self::NAMES_COOKIE], true);
			$this->names = array();
			if (is_array($raw)) {
				foreach ($raw as $key => $name) {
					// Cookies are attacker-writable; keep only scalar, bounded strings.
					if (is_string($key) && is_scalar($name)) {
						$this->names[$key] = substr(trim((string) $name), 0, self::MAX_NAME);
					}
				}
			}
		}
		return $this->names;
	}

	/**
	 * Every connection Adminer can currently reach, current one first.
	 *
	 * Two sources, merged on the connection key: the live session (what the
	 * core's #logins loop walks) and the permanent-login cookie (logins that
	 * outlived the session). A connection present in both appears once.
	 */
	private function connections() {
		$out = array();
		$add = function ($driver, $server, $username, $db) use (&$out) {
			$key = self::keyOf($driver, $server, $username, $db);
			if (!isset($out[$key])) {
				$out[$key] = array(
					'key' => $key,
					'name' => (string) $this->names()[$key],
					'db' => $db,
					'sub' => "$username@" . ($server != "" ? $server : 'default')
						. '  ·  ' . (Adminer\get_setting("vendor-$driver-$server") ?: Adminer\get_driver($driver) ?: $driver),
					'url' => self::urlFor($driver, $server, $username, $db),
				);
			}
		};

		// Live session. $password === null marks a logged-out entry the core skips too.
		foreach ((array) $_SESSION["pwds"] as $driver => $servers) {
			foreach ((array) $servers as $server => $users) {
				foreach ((array) $users as $username => $password) {
					if ($password === null) {
						continue;
					}
					$dbs = $_SESSION["db"][$driver][$server][$username];
					foreach ($dbs ? array_keys($dbs) : array("") as $db) {
						$add($driver, $server, $username, $db);
					}
				}
			}
		}

		// Permanent logins: "<key>:<base64 encrypted password>" entries, space separated.
		foreach (explode(' ', (string) $_COOKIE["adminer_permanent"]) as $entry) {
			if ($entry === '') {
				continue;
			}
			list($key) = explode(':', $entry);
			$parts = array_map('base64_decode', explode('-', $key));
			if (count($parts) == 4) {
				$add($parts[0], $parts[1], $parts[2], $parts[3]);
			}
		}

		// Current first. Built by hand rather than with auth_url(): auth_url()
		// keeps the rest of the current query string, so switching away from
		// ?select=users would carry select=users onto a database without that
		// table and land on an error page.
		$current = self::keyOf(Adminer\DRIVER, Adminer\SERVER, $_GET["username"], Adminer\DB);
		$ordered = array();
		if (isset($out[$current])) {
			$ordered[] = array('current' => true) + $out[$current];
			unset($out[$current]);
		}
		foreach ($out as $conn) {
			$ordered[] = array('current' => false) + $conn;
		}
		return $ordered;
	}

	/** Mirrors auth_url()'s driver handling: "server" with an empty host is implicit. */
	private static function urlFor($driver, $server, $username, $db) {
		$q = array();
		if ($driver != 'server' || $server != '') {
			$q[$driver] = $server;
		}
		$q['username'] = $username;
		if ($db != '') {
			$q['db'] = $db;
		}
		return '?' . http_build_query($q);
	}

	/**
	 * Put the assigned name in the breadcrumb where the host:port would be.
	 *
	 * Deliberately narrow: only the connection actually being viewed. The core
	 * also calls this from the login-page #logins loop with an arbitrary
	 * $server and no user or database in hand, and answering there would label
	 * every database on a host with one database's name. Returning null leaves
	 * those to Adminer's default.
	 */
	function serverName($server) {
		if (!isset($_GET["username"]) || $server !== Adminer\SERVER) {
			return null;
		}
		$name = $this->names()[self::keyOf(Adminer\DRIVER, $server, $_GET["username"], Adminer\DB)];
		return ($name != "" ? Adminer\h($name) : null);
	}

	/**
	 * Give the login page's own #logins chips a name, a rename button and a
	 * delete button.
	 *
	 * WHY THIS IS A SCRIPT AND NOT SERVER-SIDE HTML. The core builds that list
	 * inside navigation() as one pre-escaped string and exposes no hook for its
	 * contents, so there is nothing to override. serverName() is not a way in
	 * either: the core calls it with only a server, no user or database, so
	 * answering there would stamp one database's name onto every database on
	 * the same host. That leaves decorating the rendered list - the one place in
	 * this plugin where the name is not already in the first paint. It is
	 * confined to the login page, where there is no result grid to reflow.
	 *
	 * Each chip is matched by decoding its own href, so a name and a delete
	 * apply to exactly the connection they were attached to.
	 *
	 * Rename is client-side (it only touches the names cookie). Delete has to
	 * post: `adminer_permanent` is HttpOnly and the session is server-side, so
	 * script cannot reach either. The token comes from the login form Adminer
	 * already rendered, and forget() checks it - see headers().
	 */
	private function loginChipControls() {
		$json = function ($value) {
			return json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
		};
		// The row layout for #logins lives in adminer.css (it is Adminer's own
		// element); only the controls this plugin creates are styled here.
		echo '<style' . Adminer\nonce() . '>
.cs-chip-form {
	display: contents;                 /* the form must not become a layout box */
}
.cs-chip-btn {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 26px;
	height: 26px;
	padding: 0;
	border: 1px solid var(--border);
	border-radius: var(--radius-sm);
	background: transparent;
	color: var(--muted);
	cursor: pointer;
	transition: color 120ms ease, border-color 120ms ease, background-color 120ms ease;
}
.cs-chip-btn:hover,
.cs-chip-btn:focus-visible {
	color: var(--accent);
	border-color: var(--accent);
	background: color-mix(in srgb, var(--accent) 12%, transparent);
	outline: none;
}
.cs-chip-btn--danger:hover,
.cs-chip-btn--danger:focus-visible {
	color: #f87171;
	border-color: #f87171;
	background: color-mix(in srgb, #f87171 14%, transparent);
}
.cs-chip-ico {
	width: 14px;
	height: 14px;
	stroke: currentColor;
	fill: none;
	stroke-width: 2;
	stroke-linecap: round;
	stroke-linejoin: round;
	pointer-events: none;
}
.cs-chip-name {
	box-sizing: border-box;
	width: 16em;
	padding: 5px 12px;
	border: 1px solid var(--accent);
	border-radius: 999px;
	background: var(--bg);
	color: var(--fg);
	font: inherit;
	font-size: 12px;
}
/* While renaming, the input stands in for the chip itself.
   The prefix is not decoration: adminer.css sets the chip with
   `body:has(#username) #logins a`, whose :has(#id) makes it specificity
   (2,0,2) - a bare `.cs-chip--edit > a` loses and the link stays visible next
   to its own edit box. Matching the prefix is what wins. */
body:has(#username) #logins li.cs-chip--edit > a,
body:has(#username) #logins li.cs-chip--edit > .cs-chip-btn,
body:has(#username) #logins li.cs-chip--edit > .cs-chip-form {
	display: none;
}
</style>
';
		echo Adminer\script('(() => {
	const NAMES = ' . $json($this->names()) . ';
	const FORGET_FIELD = ' . $json(self::FORGET_FIELD) . ';
	const RENAME = ' . $json($this->lang('Rename')) . ';
	const FORGET = ' . $json($this->lang('Remove this connection')) . ';
	const CONFIRM = ' . $json($this->lang('Remove this saved connection?')) . ';
	const ICON_PEN = ' . $json(self::ICON_PEN) . ';
	const ICON_TRASH = ' . $json(self::ICON_TRASH) . ';
	const MAX_NAME = ' . (int) self::MAX_NAME . ';

	// Adminer puts the driver in the query string as its own key, e.g. ?pgsql=host
	const DRIVERS = ["server", "pgsql", "sqlite", "mssql", "oracle", "mongo", "redis", "elastic", "clickhouse", "firebird", "simpledb"];

	/** base64 of the UTF-8 bytes, to match PHP base64_encode on raw bytes. */
	const b64 = (s) => btoa(String.fromCharCode(...new TextEncoder().encode(s)));

	const keyOf = (href) => {
		const q = new URLSearchParams(href.slice(href.indexOf("?")));
		const driver = DRIVERS.find((d) => q.has(d)) || "server";
		return [driver, q.get(driver) || "", q.get("username") || "", q.get("db") || ""]
			.map(b64).join("-");
	};

	const svgFor = (paths) => {
		const svg = document.createElementNS("http://www.w3.org/2000/svg", "svg");
		svg.setAttribute("viewBox", "0 0 24 24");
		svg.setAttribute("aria-hidden", "true");
		svg.setAttribute("class", "cs-chip-ico");
		svg.innerHTML = paths;
		return svg;
	};

	const iconButton = (icon, label, extraClass) => {
		const b = document.createElement("button");
		b.type = "button";
		b.className = "cs-chip-btn" + (extraClass ? " " + extraClass : "");
		b.title = label;
		b.setAttribute("aria-label", label);
		b.append(svgFor(icon));
		return b;
	};

	const persist = () => {
		const value = encodeURIComponent(JSON.stringify(NAMES));
		document.cookie = "' . self::NAMES_COOKIE . '=" + value
			+ ";path=/;max-age=31536000;samesite=lax";
	};

	const boot = () => {
		// Adminer renders exactly one token per page; reuse it rather than
		// inventing a second one.
		const token = document.querySelector(\'input[name="token"]\');

		for (const a of document.querySelectorAll("#logins a[href]")) {
			const li = a.closest("li");
			if (!li) {
				continue;
			}
			const key = keyOf(a.getAttribute("href"));
			const original = a.textContent.trim();
			// Keep the "(PostgreSQL)" prefix: it is the one thing a name does not
			// carry, and it is how you tell two same-named engines apart.
			const vendor = (/^\([^)]*\)/.exec(original) || [""])[0];

			const paint = () => {
				const name = NAMES[key];
				a.textContent = (name ? (vendor ? vendor + " " : "") + name : original);
				a.title = original;
			};
			paint();

			const input = document.createElement("input");
			input.className = "cs-chip-name";
			input.maxLength = MAX_NAME;
			input.hidden = true;

			const pen = iconButton(ICON_PEN, RENAME);
			const stopEdit = () => {
				li.classList.remove("cs-chip--edit");
				input.hidden = true;
			};
			const commit = () => {
				const value = input.value.trim().slice(0, MAX_NAME);
				if (value) {
					NAMES[key] = value;
				} else {
					delete NAMES[key];       // cleared name means "back to default"
				}
				persist();
				paint();
				stopEdit();
			};
			pen.addEventListener("click", () => {
				li.classList.add("cs-chip--edit");
				input.hidden = false;
				input.value = NAMES[key] || "";
				input.focus();
				input.select();
			});
			input.addEventListener("keydown", (e) => {
				if (e.key === "Enter") {
					e.preventDefault();
					commit();
				} else if (e.key === "Escape") {
					e.preventDefault();
					stopEdit();
				}
			});
			input.addEventListener("blur", commit);

			li.append(pen, input);

			// Deleting needs the server, so it needs a form. #logins sits outside
			// Adminer\'s language form, so nesting one here is legal.
			if (token) {
				const form = document.createElement("form");
				form.method = "post";
				form.action = "";
				form.className = "cs-chip-form";
				const t = document.createElement("input");
				t.type = "hidden";
				t.name = "token";
				t.value = token.value;
				const k = document.createElement("input");
				k.type = "hidden";
				k.name = FORGET_FIELD;
				k.value = key;
				const trash = iconButton(ICON_TRASH, FORGET, "cs-chip-btn--danger");
				trash.type = "submit";
				// Losing a stored password means retyping it; ask first.
				form.addEventListener("submit", (e) => {
					if (!confirm(CONFIRM + "\n\n" + original)) {
						e.preventDefault();
					}
				});
				form.append(t, k, trash);
				li.append(form);
			}
		}
	};

	if (document.readyState === "loading") {
		document.addEventListener("DOMContentLoaded", boot);
	} else {
		boot();
	}
})();');
	}

	/**
	 * Erase every trace of one saved connection.
	 *
	 * Three stores have to agree or the connection comes back: the live session
	 * (which is what the #logins list is built from), the permanent-login cookie
	 * (which would restore it into a fresh session on the next request), and the
	 * names cookie (whose orphaned entry would resurface if the same connection
	 * were ever added again).
	 *
	 * The password entry in $_SESSION["pwds"] is keyed by USER, not by database,
	 * so it is only dropped once that user has no databases left on that server
	 * - otherwise removing one database would silently log you out of its
	 * siblings.
	 */
	private function forget($key) {
		$parts = array_map('base64_decode', explode('-', $key));
		if (count($parts) != 4) {
			return;
		}
		list($driver, $server, $username, $db) = $parts;

		unset($_SESSION["db"][$driver][$server][$username][$db]);
		if (!$_SESSION["db"][$driver][$server][$username]) {
			unset($_SESSION["db"][$driver][$server][$username]);
			unset($_SESSION["pwds"][$driver][$server][$username]);
		}

		$kept = array();
		foreach (explode(' ', (string) $_COOKIE["adminer_permanent"]) as $entry) {
			if ($entry !== '' && explode(':', $entry)[0] !== $key) {
				$kept[] = $entry;
			}
		}
		Adminer\cookie('adminer_permanent', implode(' ', $kept));

		$names = $this->names();
		unset($names[$key]);
		$this->names = $names;
		self::scriptCookie(self::NAMES_COOKIE, ($names ? json_encode($names) : ''));

		if ((string) $_COOKIE[self::LAST_COOKIE] === $key) {
			self::scriptCookie(self::LAST_COOKIE, '');
		}
	}

	/**
	 * Write a cookie that script can still read and rewrite.
	 *
	 * NOT Adminer\cookie(): that helper stamps HttpOnly on everything except
	 * adminer_import, and both of these cookies are owned by the browser side -
	 * the rename control reads and rewrites the names map from JS. Routing them
	 * through cookie() once, on the first delete, silently converted them to
	 * HttpOnly and killed renaming for good; nothing errors, the write is just
	 * ignored. Found by watching document.cookie go empty after a delete while
	 * PHP could still read the value.
	 *
	 * The path is pinned to "/" for the same reason: cookie_path() returns the
	 * *current request* path, which would not match the "path=/" the JS side
	 * writes and would leave two cookies of the same name shadowing each other.
	 */
	private static function scriptCookie($name, $value) {
		$attributes = 'path=/; SameSite=Lax' . (Adminer\HTTPS ? '; Secure' : '');
		header(
			'Set-Cookie: ' . $name . '=' . rawurlencode($value)
				. ($value === '' ? '; Max-Age=0' : '; Max-Age=31536000')
				. '; ' . $attributes,
			false                            // append; do not clobber Adminer's own
		);
		// Keep this request's view of the cookie consistent with what was sent.
		$_COOKIE[$name] = $value;
	}

	/**
	 * Land straight on a saved connection instead of an empty login form.
	 *
	 * headers() is the only hook that runs before any output, which a redirect
	 * requires. It fires on every page, so the guard has to be tight - and the
	 * tightest correct condition turns out to be "the URL is completely bare":
	 *
	 *   - A failed login is a POST, so its error message still renders.
	 *   - Logout redirects to "?<driver>=<host>" (Adminer strips only
	 *     username/db/ns), so it keeps a query string and lands on the form
	 *     rather than being bounced into whichever OTHER connection survived.
	 *   - "+ New connection" points at "./?new=1", so adding a connection is
	 *     still possible even while saved ones exist. That link IS the escape
	 *     hatch; without it this feature would make the login form
	 *     unreachable.
	 *
	 * No redirect loop is possible: the target always carries a query string,
	 * so the request it produces cannot match this guard again.
	 */
	function headers() {
		// Delete first: it arrives as a POST, which the auto-jump guard below
		// rejects on purpose. A bad or missing token is ignored in silence
		// rather than reported - a forged request should learn nothing.
		if (isset($_POST[self::FORGET_FIELD])) {
			if (Adminer\verify_token()) {
				$this->forget((string) $_POST[self::FORGET_FIELD]);
				Adminer\redirect('?' . self::NEW_PARAM . '=1', $this->lang('Connection removed.'));
			}
			return;
		}

		// Cast, do not compare: with php -S serving "/" there is no QUERY_STRING
		// key at all, and `null !== ''` would make this guard reject the one
		// case it exists to allow. Cost a debugging round to find.
		$query = (string) ($_SERVER['QUERY_STRING'] ?? '');
		if (
			($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET'
			|| $query !== ''
			|| $_POST
			|| isset($_GET[self::NEW_PARAM])
		) {
			return;
		}
		$conns = $this->connections();
		if (!$conns) {
			return;                          // nothing saved: show the form
		}
		// Prefer the connection last actually viewed over the newest login:
		// "where I was" is what someone reopening the tool is looking for.
		$last = (string) $_COOKIE[self::LAST_COOKIE];
		foreach ($conns as $conn) {
			if ($conn['key'] === $last) {
				Adminer\redirect($conn['url']);
			}
		}
		Adminer\redirect($conns[0]['url']);
	}

	function head($dark = null) {
		if (!isset($_GET['username'])) {
			// Login page. Adminer renders #logins itself and offers no hook for
			// its contents, so the name, rename and delete controls have to be
			// attached to the finished list. See loginChipControls().
			$this->loginChipControls();
			return;
		}
		$json = function ($value) {
			// HEX_TAG/HEX_AMP keep a database or connection name containing
			// "</script>" from closing the block it is embedded in.
			return json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
		};
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
	min-width: 22em;
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
.cs-row {
	display: flex;
	align-items: stretch;
	gap: 2px;
	border-radius: var(--radius-sm);
}
.cs-row:hover {
	background: color-mix(in srgb, var(--accent) 16%, transparent);
}
.cs-item {
	display: flex;
	flex: 1 1 auto;
	min-width: 0;
	flex-direction: column;
	gap: 1px;
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
.cs-item:focus-visible {
	background: color-mix(in srgb, var(--accent) 16%, transparent);
	outline: none;
}
.cs-row[aria-current="true"] {
	background: color-mix(in srgb, var(--accent) 22%, transparent);
}
.cs-item-main,
.cs-item-sub {
	overflow: hidden;
	white-space: nowrap;
	text-overflow: ellipsis;
}
.cs-item-main {
	font-family: var(--mono-stack, monospace);
	font-size: 11.5px;
}
.cs-item-sub {
	color: var(--muted);
	font-size: 10.5px;
}
.cs-edit {
	display: flex;
	flex: 0 0 auto;
	align-items: center;
	padding: 0 7px;
	border: 0;
	border-radius: var(--radius-sm);
	background: transparent;
	color: var(--muted);
	cursor: pointer;
	opacity: 0;
}
.cs-row:hover .cs-edit,
.cs-edit:focus-visible {
	opacity: 1;
}
.cs-edit:hover,
.cs-edit:focus-visible {
	color: var(--accent);
	outline: none;
}
.cs-editbox {
	flex: 1 1 auto;
	min-width: 0;
	box-sizing: border-box;
	margin: 4px;
	padding: 4px 6px;
	border: 1px solid var(--accent);
	border-radius: var(--radius-sm);
	background: var(--bg);
	color: var(--fg);
	font: inherit;
	font-size: 11.5px;
}
.cs-row--edit .cs-item,
.cs-row--edit .cs-edit {
	display: none;
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
	const CONNS = <?php echo $json($this->connections()); ?>;
	const NAMES_COOKIE = <?php echo $json(self::NAMES_COOKIE); ?>;
	const LAST_COOKIE = <?php echo $json(self::LAST_COOKIE); ?>;
	const NEW_URL = <?php echo $json('./?' . self::NEW_PARAM . '=1'); ?>;
	const NO_DB = <?php echo $json($this->lang('(no database)')); ?>;
	const RENAME = <?php echo $json($this->lang('Rename')); ?>;
	const NEW_CONN = <?php echo $json($this->lang('New connection')); ?>;
	const MAX_NAME = <?php echo (int) self::MAX_NAME; ?>;

	// lucide: database
	const ICON_DB = '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5V19A9 3 0 0 0 21 19V5"/><path d="M3 12A9 3 0 0 0 21 12"/>';
	const ICON_PEN = <?php echo $json(self::ICON_PEN); ?>;

	const svgFor = (paths) => {
		const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
		svg.setAttribute('viewBox', '0 0 24 24');
		svg.setAttribute('aria-hidden', 'true');
		svg.setAttribute('class', 'cs-ico');
		svg.innerHTML = paths;
		return svg;
	};

	/**
	 * Rewrite the whole map every time. It is at most a few hundred bytes and a
	 * cookie has no partial update, so read-modify-write of the full JSON is
	 * the only correct form.
	 */
	const persistNames = () => {
		const map = {};
		for (const c of CONNS) {
			if (c.name) {
				map[c.key] = c.name;
			}
		}
		const value = encodeURIComponent(JSON.stringify(map));
		document.cookie = NAMES_COOKIE + '=' + value + ';path=/;max-age=31536000;samesite=lax';
	};

	const label = (c) => c.name || c.db || NO_DB;

	const boot = () => {
		const logout = document.querySelector('#foot button[name="logout"], #foot input[name="logout"]');
		const host = logout ? logout.closest('p') : null;
		if (!host) {
			return;
		}
		const current = CONNS.find((c) => c.current) || null;

		// Remember where we are, so reopening the tool on a bare URL comes back
		// here rather than to whichever connection happened to be saved last.
		if (current) {
			document.cookie = LAST_COOKIE + '=' + encodeURIComponent(current.key)
				+ ';path=/;max-age=31536000;samesite=lax';
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
		btn.title = current ? current.sub : 'Switch connection';
		btn.setAttribute('aria-haspopup', 'menu');
		btn.setAttribute('aria-expanded', 'false');

		const menu = document.createElement('div');
		menu.className = 'cs-menu';
		menu.setAttribute('role', 'menu');
		menu.hidden = true;

		const makeRow = (c) => {
			const row = document.createElement('div');
			row.className = 'cs-row';
			if (c.current) {
				row.setAttribute('aria-current', 'true');
			}

			const a = document.createElement('a');
			a.className = 'cs-item';
			a.href = c.url;
			a.setAttribute('role', 'menuitem');
			const main = document.createElement('span');
			main.className = 'cs-item-main';
			main.textContent = label(c);
			const sub = document.createElement('span');
			sub.className = 'cs-item-sub';
			sub.textContent = c.sub + (c.name && c.db ? '  ·  ' + c.db : '') + (c.current ? '  ·  current' : '');
			a.append(main, sub);

			const pen = document.createElement('button');
			pen.type = 'button';
			pen.className = 'cs-edit';
			pen.title = RENAME;
			pen.setAttribute('aria-label', RENAME);
			pen.append(svgFor(ICON_PEN));

			// Editing happens in a sibling input, not inside the <a>: a text box
			// nested in a link fights the link for clicks and for Enter.
			const input = document.createElement('input');
			input.className = 'cs-editbox';
			input.maxLength = MAX_NAME;
			input.placeholder = c.db || NO_DB;
			input.hidden = true;

			const stopEdit = () => {
				row.classList.remove('cs-row--edit');
				input.hidden = true;
			};
			const commit = () => {
				c.name = input.value.trim().slice(0, MAX_NAME);
				persistNames();
				main.textContent = label(c);
				sub.textContent = c.sub + (c.name && c.db ? '  ·  ' + c.db : '') + (c.current ? '  ·  current' : '');
				if (c.current) {
					btnText.textContent = label(c);
				}
				stopEdit();
			};

			pen.addEventListener('click', (e) => {
				e.preventDefault();
				e.stopPropagation();
				row.classList.add('cs-row--edit');
				input.hidden = false;
				input.value = c.name || '';
				input.focus();
				input.select();
			});
			input.addEventListener('keydown', (e) => {
				if (e.key === 'Enter') {
					e.preventDefault();
					commit();
				} else if (e.key === 'Escape') {
					// Swallow it, otherwise the document handler closes the menu
					// and the rename is lost without the user asking for that.
					e.preventDefault();
					e.stopPropagation();
					stopEdit();
				}
			});
			input.addEventListener('blur', commit);

			row.append(a, pen, input);
			return row;
		};

		const build = () => {
			menu.textContent = '';
			if (!CONNS.length) {
				const empty = document.createElement('div');
				empty.className = 'cs-empty';
				empty.textContent = 'No connections yet';
				menu.append(empty);
			}
			for (const c of CONNS) {
				menu.append(makeRow(c));
			}
			const sep = document.createElement('hr');
			sep.className = 'cs-sep';
			menu.append(sep);
			const add = document.createElement('a');
			add.className = 'cs-item';
			// ?new=1 suppresses the auto-jump in headers(); a bare './' would be
			// redirected straight back into the current connection.
			add.href = NEW_URL;
			add.setAttribute('role', 'menuitem');
			const addRow = document.createElement('span');
			addRow.className = 'cs-item-main';
			addRow.textContent = '+  ' + NEW_CONN;
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
		'en' => array(
			'' => 'Switch between saved connections from any page, with your own names',
			'(no database)' => '(no database)',
			'Rename' => 'Rename',
			'New connection' => 'New connection',
		),
		'vi' => array(
			'' => 'Chuyển nhanh giữa các kết nối đã lưu ở mọi trang, đặt tên riêng được',
			'(no database)' => '(chưa chọn database)',
			'Rename' => 'Đổi tên',
			'New connection' => 'Kết nối mới',
		),
	);
}

return new AdminerConnectionSwitcher();
