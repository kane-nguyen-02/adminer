<?php

final class AdminerDefaultPgsql extends Adminer\Plugin {
	function loginFormField($name, $heading, $value) {
		if ($name !== 'driver') {
			return null;
		}

		// Prefer PostgreSQL as the default selected driver.
		// Quote style ('/") varies across Adminer versions, so match either.
		$value = str_replace(" selected", '', $value);
		$value = preg_replace("/value=(['\"])pgsql\\1/", "value=$1pgsql$1 selected", $value, 1);

		return $heading . $value . "\n";
	}
}

return new AdminerDefaultPgsql();
