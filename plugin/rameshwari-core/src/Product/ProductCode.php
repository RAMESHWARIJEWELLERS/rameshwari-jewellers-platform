<?php
/**
 * Product Code value object.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Product;

use Rameshwari\Core\Data\Meta;

/**
 * A normalised Product Code: uppercase A-Z, 0-9 and hyphen, at most 64
 * characters. Normalisation is the existing _rj_code sanitiser; no new
 * format rule exists.
 */
final class ProductCode {

	/**
	 * Builds the value object.
	 *
	 * @param string $value Normalised code.
	 */
	private function __construct( private string $value ) {
	}

	/**
	 * Normalises raw input with the registered _rj_code rule.
	 *
	 * @param string $raw Raw input.
	 * @return string Normalised code, empty when nothing valid remains.
	 */
	public static function normalise( string $raw ): string {
		return (string) Meta::sanitize( 'code', $raw );
	}

	/**
	 * A code from raw input, or null when nothing valid remains.
	 *
	 * @param string $raw Raw input.
	 * @return self|null
	 */
	public static function from( string $raw ): ?self {
		$value = self::normalise( $raw );

		return '' === $value ? null : new self( $value );
	}

	/**
	 * The normalised code.
	 *
	 * @return string
	 */
	public function value(): string {
		return $this->value;
	}
}
