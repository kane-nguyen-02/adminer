<?php

/**
 * Give "Permanent login" a stable encryption key so saved connections survive.
 *
 * THE BUG THIS FIXES. Adminer's permanent login stores each remembered
 * connection in the `adminer_permanent` cookie as
 * "<key>:<password encrypted with permanentLogin()>". The default
 * permanentLogin() is password_file(), which keeps that secret in
 * get_temp_dir()."/adminer.key" - i.e. /tmp/adminer.key inside the container.
 * /tmp is container-local and not a volume, so every `docker compose up
 * --build`, every restart, every image rebuild throws the key away and mints a
 * new random one. The cookie survives, the key does not, so the stored
 * passwords no longer decrypt and Adminer answers with its
 * "define permanentLogin()" error and drops the entry. That is why remembered
 * connections kept vanishing: it was never the cookie expiring.
 *
 * Sourcing the key from the environment makes it outlive the container. The
 * value belongs in .env (gitignored), not in docker-compose.yml.
 *
 * SECURITY - read this before setting the variable. The `adminer_permanent`
 * cookie holds database passwords encrypted with exactly this key, so key +
 * cookie = plaintext passwords. Making the key durable is precisely what makes
 * the ciphertext durable too: with the old ephemeral key an exfiltrated cookie
 * became useless at the next restart, and now it does not. Anyone who can read
 * the container's environment (docker inspect, a shell in the container, the
 * compose file's .env on disk) and can also get a user's cookie recovers those
 * passwords. Use a long random value, keep .env out of git, and treat the
 * fronting tunnel's authentication as the real access control.
 *
 * Returning null when ADMINER_PERMANENT_KEY is unset is deliberate: the hook
 * then falls through to Adminer's own password_file(), so an unconfigured
 * deployment behaves exactly as it does today rather than silently breaking
 * permanent login in a new way.
 */
final class AdminerPermanentKey extends Adminer\Plugin {
	/** @var string */
	private $key;

	function __construct() {
		$this->key = trim((string) getenv('ADMINER_PERMANENT_KEY'));
	}

	/**
	 * @param bool $create true when Adminer is storing a password rather than
	 *   reading one. Irrelevant here - unlike a key *file* there is nothing to
	 *   lazily create - but the signature has to match the core's.
	 */
	function permanentLogin($create = false) {
		return ($this->key !== '' ? $this->key : null);
	}

	protected $translations = array(
		'en' => array('' => 'Keep permanent logins working across container restarts'),
		'vi' => array('' => 'Giữ đăng nhập vĩnh viễn không mất khi container khởi động lại'),
	);
}

return new AdminerPermanentKey();
