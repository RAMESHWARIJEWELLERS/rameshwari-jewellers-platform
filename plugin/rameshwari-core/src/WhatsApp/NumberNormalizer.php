<?php
/**
 * Phone number normalising.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\WhatsApp;

/**
 * Brings an Indian phone number to the 12-digit 91 form the rj_whatsapp option stores.
 *
 * Options validates numbers on save with the same rule; this keeps the engine safe
 * when a stored value was written another way.
 */
final class NumberNormalizer {

	/**
	 * The normalised number, or an empty string when it is not a valid one.
	 *
	 * @param string $raw Number as typed or stored.
	 * @return string
	 */
	public static function normalize( string $raw ): string {
		$digits = preg_replace( '/\D+/', '', $raw );
		$digits = is_string( $digits ) ? $digits : '';

		if ( 11 === strlen( $digits ) && '0' === $digits[0] ) {
			$digits = substr( $digits, 1 );
		}

		if ( 10 === strlen( $digits ) ) {
			$digits = '91' . $digits;
		}

		return ( 12 === strlen( $digits ) && str_starts_with( $digits, '91' ) ) ? $digits : '';
	}
}
