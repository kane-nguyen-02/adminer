<?php

/**
 * Allow empty DB passwords when the browser hits Adminer from localhost
 * or any RFC1918 private network (machines that can reach each other on LAN).
 *
 * Includes ::ffff:… because PHP's built-in server often reports IPv4
 * clients as IPv4-mapped IPv6 under network_mode: host.
 *
 * 172.16.0.0/12 is listed as 172.16.–172.31. (not "172.") so we do not
 * accidentally allow non-private 172.x addresses.
 */
require_once('plugins/login-ip.php');

$private172 = array();
$private172Mapped = array();
for ($i = 16; $i <= 31; $i++) {
	$private172[] = "172.{$i}.";
	$private172Mapped[] = "::ffff:172.{$i}.";
}

return new AdminerLoginIp(
	array_merge(
		array(
			'127.',
			'::1',
			'::ffff:127.',
			'10.',
			'::ffff:10.',
			'192.168.',
			'::ffff:192.168.',
		),
		$private172,
		$private172Mapped
	),
	array()
);
