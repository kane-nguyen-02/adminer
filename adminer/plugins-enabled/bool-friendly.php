<?php

/**
 * Show boolean columns as true / false instead of 1 / 0, in both the result
 * grid and the edit form.
 *
 * Adminer renders a boolean value as <code>1</code> / <code>0</code> in Select
 * results, and edits it with a bare checkbox (hidden 0 + checkbox value 1).
 * "1/0" and an on/off checkbox read further from the natural word than the
 * database's own `true`/`false`, so this plugin swaps both:
 *
 *   - selectVal(): rewrites the displayed digit to true / false. NULL and every
 *     non-boolean column are returned untouched (null defers to Adminer's own
 *     formatting, since the base renderer is the last hook in the chain).
 *
 *   - editInput(): replaces the checkbox with a <select> of true / false that
 *     posts a value PostgreSQL accepts verbatim. NULL for a nullable column is
 *     still handled by Adminer's own function dropdown beside the field, so this
 *     only owns the value part.
 *
 * Keyed solely off $field['type'] containing "bool" (what Adminer labels the
 * type), so it needs no queries and is driver-agnostic.
 */
final class AdminerBoolFriendly extends Adminer\Plugin {
	private static function isBool($field): bool {
		return is_array($field)
			&& (bool) preg_match('~bool~i', (string) ($field['type'] ?? ''));
	}

	/** true / false / null (null = NULL or an unrecognised value: leave it be). */
	private static function truthy($value): ?bool {
		// PDO_PgSQL hands booleans back as real PHP true/false. This MUST come
		// first: (string) false is "", which the empty-string guard below would
		// otherwise read as "unknown" and blank the cell - the false-shows-
		// nothing bug.
		if (is_bool($value)) {
			return $value;
		}
		if ($value === null) {
			return null;
		}
		$s = trim((string) $value);
		if ($s === '') {
			return null;
		}
		if (preg_match('~^(1|t|true|y|yes|on)$~i', $s)) {
			return true;
		}
		if (preg_match('~^(0|f|false|n|no|off)$~i', $s)) {
			return false;
		}
		return null;
	}

	function selectVal($val, $link, $field, $original) {
		if (!self::isBool($field)) {
			return null;                         // defer to Adminer's default
		}
		$b = self::truthy($original);
		if ($b === null) {
			$b = self::truthy($val);             // some drivers pass it as $val
		}
		if ($b === null) {
			return null;                         // NULL / unexpected: keep Adminer's <i>NULL</i>
		}
		// Colour-coded so true / false / NULL are each obvious at a glance; NULL
		// stays Adminer's own italic marker (handled by returning null above).
		$cls = $b ? 'bool-true' : 'bool-false';
		return "<code class='bool-val $cls'>" . ($b ? 'true' : 'false') . '</code>';
	}

	function head($dark = null) {
		if (!isset($_GET['select'])) {
			return;
		}
		// Reuse adminer.css's own accent hues (green .char / red .binary) so the
		// true/false colours match the rest of the console in light and dark.
		echo '<style' . Adminer\nonce() . '>
#table td .bool-val { font-weight: 600; }
#table td .bool-true { color: #1a7f37; }
#table td .bool-false { color: #cf222e; }
</style>';
	}

	function editInput($table, $field, $attrs, $value) {
		if (!self::isBool($field)) {
			return '';                           // "" = let Adminer render the field
		}
		$b = self::truthy($value);
		$option = function (bool $v, string $label) use ($b) {
			$value = $v ? 'true' : 'false';
			return "<option value='$value'" . ($b === $v ? ' selected' : '') . ">$label</option>";
		};
		return "<select$attrs>" . $option(true, 'true') . $option(false, 'false') . '</select>';
	}

	protected $translations = array(
		'en' => array('' => 'Show boolean columns as true/false and edit them the same way'),
		'vi' => array('' => 'Hiển thị và sửa cột boolean bằng true/false cho dễ đọc'),
	);
}

return new AdminerBoolFriendly();
