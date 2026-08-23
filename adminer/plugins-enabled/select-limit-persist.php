<?php

/**
 * Persist Select "Limit" AND "Text length" across tables/sessions via cookie.
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

	// Text length behaves exactly like Limit: Adminer defaults it to 100 on
	// every page load, so a change lasted only until you opened another table.
	private const COOKIE_LEN = 'adminer_select_text_length';
	private const FALLBACK_LEN = 100;
	private const MAX_LEN = 1000000;

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
		if (!isset($_GET['select'])) {
			return;
		}
		if (isset($_GET['limit'])) {
			$this->save($this->fromRequest($_GET['limit']));
		}
		if (isset($_GET['text_length'])) {
			$this->saveLength($this->lengthFromRequest($_GET['text_length']));
		}
	}

	function selectLimitProcess() {
		if (isset($_GET['limit'])) {
			return $this->fromRequest($_GET['limit']);
		}
		return $this->readSaved();
	}

	private function readSavedLength(): int {
		if (!isset($_COOKIE[self::COOKIE_LEN]) || $_COOKIE[self::COOKIE_LEN] === '') {
			return self::FALLBACK_LEN;
		}
		return $this->sanitizeLength(intval($_COOKIE[self::COOKIE_LEN]));
	}

	private function sanitizeLength(int $length): int {
		if ($length < 0) {
			return self::FALLBACK_LEN;
		}
		if ($length > self::MAX_LEN) {
			return self::MAX_LEN;
		}
		return $length;
	}

	/** @param mixed $raw */
	private function lengthFromRequest($raw): int {
		if (is_array($raw)) {
			$raw = end($raw);
		}
		return $this->sanitizeLength(intval($raw));
	}

	private function saveLength(int $length): void {
		$length = $this->sanitizeLength($length);
		Adminer\cookie(self::COOKIE_LEN, (string) $length, self::TTL);
		$_COOKIE[self::COOKIE_LEN] = (string) $length;
	}

	function selectLengthProcess() {
		// Core returns a string here, so match that rather than an int.
		if (isset($_GET['text_length'])) {
			return (string) $this->lengthFromRequest($_GET['text_length']);
		}
		return (string) $this->readSavedLength();
	}

	function selectLengthPrint($text_length) {
		$default = $this->readSavedLength();
		echo "<fieldset><legend>" . Adminer\lang(68) . "</legend><div>",
			"<input type='number' name='text_length' class='size' value='" . Adminer\h($text_length) . "'",
			// data-default carries the SAVED value, not a hardcoded 100, so
			// Adminer only puts text_length in the URL when it differs from what
			// you last chose - same trick the Limit box uses.
			" data-default='" . Adminer\h($default) . "'",
			Adminer\on('input', 'selectFieldChange'),
			">",
			"</div></fieldset>\n";
		// Non-null stops Adminer core from printing a second Text length box.
		return true;
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
		'en' => array('' => 'Persist Select limit and text length across tables via cookie'),
		'vi' => array('' => 'Lưu Limit và Text length trang Select giữa các bảng bằng cookie'),
	);
}

return new AdminerSelectLimitPersist();
