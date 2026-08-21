<?php

/**
 * Allow empty DB passwords when the browser hits Adminer from localhost.
 * Needed for local Redis/Mongo (and similar) that have no AUTH configured.
 *
 * Includes ::ffff:127. because PHP's built-in server often reports IPv4
 * clients as IPv4-mapped IPv6 under network_mode: host.
 */
require_once('plugins/login-ip.php');

return new AdminerLoginIp(
	array('127.', '::1', '::ffff:127.'),
	array()
);
