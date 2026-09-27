<?php
/**
 * Typed input sanitisers.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Support;

/**
 * One sanitiser per field type, for the field registry to reference.
 *
 * Every method is deterministic and returns its declared type for any
 * input. Sanitising cleans input on the way in; it never escapes output.
 * Callers unslash request data before passing it here. Checking that a
 * value is acceptable, and reporting why not, is validation and belongs
 * to the validators, not to this class.
 */
final class Sanitizer {

	/**
	 * Single-line plain text. Tags, line breaks and extra whitespace are removed.
	 *
	 * @param mixed $value      Input.
	 * @param int   $max_length Maximum characters, 0 for no limit.
	 * @return string Empty for non-scalar input.
	 */
	public static function text( mixed $value, int $max_length = 0 ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		return self::limit( sanitize_text_field( (string) $value ), $max_length );
	}

	/**
	 * Multi-line plain text. Line breaks are kept; tags are removed.
	 *
	 * @param mixed $value      Input.
	 * @param int   $max_length Maximum characters, 0 for no limit.
	 * @return string Empty for non-scalar input.
	 */
	public static function textarea( mixed $value, int $max_length = 0 ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		return self::limit( sanitize_textarea_field( (string) $value ), $max_length );
	}

	/**
	 * Lowercase key: letters, digits, underscores and hyphens.
	 *
	 * @param mixed $value Input.
	 * @return string Empty for non-scalar input.
	 */
	public static function key( mixed $value ): string {
		return is_scalar( $value ) ? sanitize_key( (string) $value ) : '';
	}

	/**
	 * Whole number, clamped to the bounds.
	 *
	 * @param mixed    $value    An int or a string of digits with an optional minus sign.
	 * @param int      $fallback Returned for floats, empty and non-numeric input.
	 * @param int|null $min      Lower bound.
	 * @param int|null $max      Upper bound.
	 * @return int
	 */
	public static function int( mixed $value, int $fallback = 0, ?int $min = null, ?int $max = null ): int {
		if ( is_int( $value ) ) {
			$number = $value;
		} elseif ( is_string( $value ) && 1 === preg_match( '/^\s*-?\d{1,18}\s*$/', $value ) ) {
			$number = (int) trim( $value );
		} else {
			return $fallback;
		}

		if ( null !== $min && $number < $min ) {
			return $min;
		}

		if ( null !== $max && $number > $max ) {
			return $max;
		}

		return $number;
	}

	/**
	 * Decimal number rounded to a fixed precision, clamped to the bounds.
	 *
	 * @param mixed      $value     An int, a finite float, or a decimal string.
	 * @param int        $precision Decimal places kept.
	 * @param float      $fallback  Returned for non-numeric input.
	 * @param float|null $min       Lower bound.
	 * @param float|null $max       Upper bound.
	 * @return float
	 */
	public static function decimal( mixed $value, int $precision, float $fallback = 0.0, ?float $min = null, ?float $max = null ): float {
		if ( is_int( $value ) || ( is_float( $value ) && is_finite( $value ) ) ) {
			$number = (float) $value;
		} elseif ( is_string( $value ) && 1 === preg_match( '/^\s*-?\d{1,15}(?:\.\d+)?\s*$/', $value ) ) {
			$number = (float) trim( $value );
		} else {
			return $fallback;
		}

		$number = round( $number, max( 0, $precision ) );

		if ( null !== $min && $number < $min ) {
			return $min;
		}

		if ( null !== $max && $number > $max ) {
			return $max;
		}

		return $number;
	}

	/**
	 * Boolean. True only for true, 1, and the strings 1, true, yes and on.
	 *
	 * @param mixed $value Input.
	 * @return bool
	 */
	public static function bool( mixed $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_int( $value ) ) {
			return 1 === $value;
		}

		if ( is_string( $value ) ) {
			return in_array( strtolower( trim( $value ) ), array( '1', 'true', 'yes', 'on' ), true );
		}

		return false;
	}

	/**
	 * Email address.
	 *
	 * @param mixed $value Input.
	 * @return string Empty unless the result is a valid address.
	 */
	public static function email( mixed $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}

		$email = sanitize_email( $value );

		return false !== is_email( $email ) ? $email : '';
	}

	/**
	 * HTTP or HTTPS URL for storage.
	 *
	 * @param mixed $value Input.
	 * @return string Empty for other schemes and non-string input.
	 */
	public static function url( mixed $value ): string {
		return is_string( $value ) ? esc_url_raw( trim( $value ), array( 'http', 'https' ) ) : '';
	}

	/**
	 * One of an allowed set of strings.
	 *
	 * @param mixed              $value    Input.
	 * @param array<int, string> $allowed  Allowed values, compared strictly.
	 * @param string             $fallback Returned when the input is not allowed.
	 * @return string
	 */
	public static function enum( mixed $value, array $allowed, string $fallback ): string {
		return is_string( $value ) && in_array( $value, $allowed, true ) ? $value : $fallback;
	}

	/**
	 * Positive integer ids, de-duplicated, in first-seen order.
	 *
	 * @param mixed $value     An array, or a comma-separated string.
	 * @param int   $max_items Maximum ids kept, 0 for no limit.
	 * @return array<int, int>
	 */
	public static function id_list( mixed $value, int $max_items = 0 ): array {
		if ( is_string( $value ) ) {
			$value = explode( ',', $value );
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		$ids = array();

		foreach ( $value as $item ) {
			$id = self::int( $item, 0, 0 );

			if ( $id > 0 && ! in_array( $id, $ids, true ) ) {
				$ids[] = $id;
			}

			if ( $max_items > 0 && count( $ids ) >= $max_items ) {
				break;
			}
		}

		return $ids;
	}

	/**
	 * Hard character limit that never splits a multi-byte character.
	 *
	 * @param string $text       Text.
	 * @param int    $max_length Maximum characters, 0 for no limit.
	 * @return string
	 */
	private static function limit( string $text, int $max_length ): string {
		if ( $max_length < 1 ) {
			return $text;
		}

		$chars = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );

		if ( false === $chars || count( $chars ) <= $max_length ) {
			return $text;
		}

		return implode( '', array_slice( $chars, 0, $max_length ) );
	}
}
