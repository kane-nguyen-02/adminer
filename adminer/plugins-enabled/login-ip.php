<?php

/**
 * Allow an empty DB password from ANY client address, including through a proxy.
 *
 * Upstream's AdminerLoginIp::login() is what Adminer consults when the password
 * box is left empty: return true and the empty password is accepted, otherwise
 * Adminer refuses it. Two things had to change to make that work from anywhere:
 *
 *   1. The IP prefix list. An empty prefix '' matches every address, because
 *      strncasecmp($addr, '', 0) is 0 - so array('') is "any address" without
 *      having to enumerate ranges.
 *
 *   2. The X-Forwarded-For list. This is the part that silently blocked public
 *      access: with an EMPTY $forwarded_for list, upstream additionally
 *      requires that the request was NOT proxied ($forwarded_for == ""). Behind
 *      the Cloudflare tunnel that header is always set, so an empty password
 *      was refused no matter which IPs were listed. Passing array('') accepts
 *      any forwarded chain too.
 *
 * SECURITY - this is deliberately permissive, at the operator's request:
 * anyone who can reach this Adminer can submit a BLANK password to any host and
 * port it can route to. The database still enforces its own authentication, so
 * this is not a bypass - but any passwordless account on any reachable host
 * becomes reachable through here, and Adminer can be used to probe the network
 * it sits in. Keep real passwords on every account, and keep access control in
 * front of it (the tunnel's own auth) rather than relying on this file.
 */
require_once('plugins/login-ip.php');

// array('') = match any REMOTE_ADDR; array('') = match any X-Forwarded-For.
return new AdminerLoginIp(array(''), array(''));
