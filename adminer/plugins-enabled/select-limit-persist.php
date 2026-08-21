<?php

/**
 * Persist Select "Limit" across tables/sessions via cookie.
 *
 * - Saves cookie early in headers() (before HTML) via Adminer\cookie().
 * - selectLimitProcess() only reads GET or cookie (no late setcookie).
 * - selectLimitPrint() returns true so Adminer core does not print a
 *   second Limit fieldset (duplicate name=limit → &limit=A&limit=B).
 */
final class AdminerSelectLimitPersist extends Adminer\Plugin {
	private const COOKIE = 'adminer_select_limit';
	private const FALLBACK = 50;
	private const MAX = 1000000;
	private const TTL = 31536000; // 365 days

	private function readSaved(): int {
		if (!isset($_COOKIE[self::COOKIE]) || $_COOKIE[self::COOKIE] === '') {
			return self::FALLBACK;
		}
		return $this->sanitize(intval($_COOKIE[self::COOKIE]));
	}

	private function sanitize(int $limit): int {
		if ($limit < 0) {
			return self::FALLBACK;
		}
		if ($limit > self::MAX) {
			return self::MAX;
		}
		return $limit;
	}

	/** @param mixed $raw */
	private function fromRequest($raw): int {
		if (is_array($raw)) {
			$raw = end($raw);
		}
		return $this->sanitize(intval($raw));
	}

	private function save(int $limit): void {
		$limit = $this->sanitize($limit);
		Adminer\cookie(self::COOKIE, (string) $limit, self::TTL);
		$_COOKIE[self::COOKIE] = (string) $limit;
	}

	function headers() {
		if (isset($_GET['select']) && isset($_GET['limit'])) {
			$this->save($this->fromRequest($_GET['limit']));
		}
	}

	function selectLimitProcess() {
		if (isset($_GET['limit'])) {
			return $this->fromRequest($_GET['limit']);
		}
		return $this->readSaved();
	}

	function selectLimitPrint($limit) {
		$default = $this->readSaved();
		echo "<fieldset><legend>" . Adminer\lang(67) . "</legend><div>",
			"<input type='number' name='limit' class='size' value='" . Adminer\h($limit ?: '') . "'",
			" data-default='" . Adminer\h($default) . "'",
			Adminer\on('input', 'selectFieldChange'),
			">",
			"</div></fieldset>\n";
		// Non-null stops Adminer core from printing a second Limit box.
		return true;
	}

	protected $translations = array(
		'en' => array('' => 'Persist Select limit across tables via cookie'),
		'vi' => array('' => 'Lưu Limit trang Select giữa các bảng bằng cookie'),
	);
}

return new AdminerSelectLimitPersist();
