<?php

/**
 * Prefill the login form with sane defaults for whichever driver is selected.
 *
 * Adminer ships one global default (ADMINER_DEFAULT_SERVER, "localhost" here)
 * and leaves username and database empty, so every login started by retyping
 * the same three values - including the port, which is the part that actually
 * differs per engine and is the easiest to get wrong.
 *
 * WHY THE SCRIPT COMES FROM loginFormField('db') AND NOT FROM head(). head()
 * runs before the form exists, so filling from there means waiting for
 * DOMContentLoaded and letting the browser paint "localhost" and three empty
 * boxes first. The db row is the LAST field Adminer renders, so a script
 * attached to it runs while the parser is still inside the form and the values
 * are in place before the first paint. Adminer itself uses this trick on the
 * username row to fire the driver's change handler.
 *
 * WHY IT DOES NOT TOUCH loginFormField('driver'). default-pgsql.php owns that
 * field, and Adminer's plugin dispatch stops at the first hook returning
 * non-null - claiming 'driver' here would silently disable that plugin. This
 * one reads the selected driver from the DOM instead, which also keeps it
 * correct if the default driver is ever changed elsewhere.
 *
 * NOTHING THE USER TYPED IS EVER OVERWRITTEN. A field is filled only when it
 * is empty, or when it still holds the bare ADMINER_DEFAULT_SERVER or another
 * driver's default - all of which mean nobody chose it. And the whole thing
 * stands down when the page carries an error message: that is a failed login
 * being re-rendered with the credentials the user submitted, and rewriting
 * those under them would be maddening.
 */
final class AdminerLoginDefaults extends Adminer\Plugin {
	/**
	 * driver id => [server, username, database]. Ports are each engine's own
	 * documented default and usernames are the account the engine creates on
	 * install. An empty string means "leave it blank": Mongo and Redis are
	 * commonly unauthenticated, and only MySQL and PostgreSQL have a database
	 * worth preselecting.
	 *
	 * sqlite is deliberately absent - Adminer hides the server row for it and
	 * its "database" is a file path only the user can know.
	 */
	private const DEFAULTS = array(
		'server' => array('localhost:3306', 'root', ''),        // MySQL / MariaDB
		'pgsql' => array('localhost:5432', 'postgres', 'postgres'),
		'mssql' => array('localhost:1433', 'sa', ''),
		'oracle' => array('localhost:1521', 'system', ''),
		'mongo' => array('localhost:27017', '', ''),
		'redis' => array('localhost:6379', '', ''),
	);

	function loginFormField($name, $heading, $value) {
		if ($name !== 'db') {
			return null;
		}
		$json = function ($v) {
			return json_encode($v, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
		};
		return $heading . $value . "\n" . Adminer\script('(() => {
	const DEFAULTS = ' . $json(self::DEFAULTS) . ';
	const COLUMN = {server: 0, username: 1, db: 2};
	// What Adminer puts in the server box on its own. Counts as "not chosen".
	const BARE = ' . $json((string) getenv('ADMINER_DEFAULT_SERVER')) . ';

	const form = document.currentScript.closest("form");
	const driverSelect = form && form["auth[driver]"];
	if (!driverSelect) {
		return;
	}

	// A failed attempt re-renders the form with what was submitted. Leave it be.
	if (document.querySelector(".error")) {
		return;
	}

	const fields = {
		server: form["auth[server]"],
		username: form["auth[username]"],
		db: form["auth[db]"],
	};

	/** True for any value that got there without the user picking it. */
	const isUnchosen = (key, value) => {
		if (value === "") {
			return true;
		}
		if (key === "server" && value === BARE) {
			return true;
		}
		const i = COLUMN[key];
		return Object.values(DEFAULTS).some((d) => d[i] !== "" && d[i] === value);
	};

	const apply = () => {
		const preset = DEFAULTS[driverSelect.value];
		for (const [key, input] of Object.entries(fields)) {
			if (!input || !isUnchosen(key, input.value)) {
				continue;
			}
			// No preset (sqlite): an empty box is already the right answer.
			input.value = (preset ? preset[COLUMN[key]] : "");
		}
	};

	apply();
	driverSelect.addEventListener("change", apply);
})();');
	}

	protected $translations = array(
		'en' => array('' => 'Prefill host, port, user and database for the selected driver'),
		'vi' => array('' => 'Điền sẵn host, port, user và database theo driver đang chọn'),
	);
}

return new AdminerLoginDefaults();
