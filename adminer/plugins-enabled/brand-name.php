<?php

/**
 * Rename the product title link (#h1) from "Adminer" to "Kane".
 * Returns non-null so Adminer core does not print the default name().
 */
final class AdminerBrandName extends Adminer\Plugin {
	function name() {
		$logo = Adminer\h(preg_replace('~\?.*~', '', Adminer\ME) . '?file=logo.png&version=6.0.1');
		return "<a href='https://kane.qzz.io/'" . Adminer\target_blank()
			. " id='h1'><img src='$logo' width='24' height='24' alt='' id='logo'>Kane</a>";
	}

	protected $translations = array(
		'en' => array('' => 'Rename Adminer title to Kane'),
		'vi' => array('' => 'Đổi tên tiêu đề Adminer thành Kane'),
	);
}

return new AdminerBrandName();
