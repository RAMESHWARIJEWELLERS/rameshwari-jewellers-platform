<?php
/**
 * Salted address hashing.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Support;

/**
 * The only place in the platform that accepts a raw client address. It is
 * normalised and hashed inside ip() and never stored, written anywhere or
 * returned; only the one-way hash leaves this class.
 *
 * The key is a WordPress salt from wp-config.php, which is server-side
 * configuration and never reaches the browser.
 */
final class Hash {

	private const DOMAIN = 'rameshwari-ip|';

	/**
	 * Key override, for tests. Null uses the WordPress auth salt.
	 *
	 * @var string|null
	 */
	private ?string $salt;

	/**
	 * Sets up the hasher.
	 *
	 * @param string|null $salt Key override. Null or empty uses wp_salt( 'auth' ).
	 */
	public function __construct( ?string $salt = null ) {
		$this->salt = $salt;
	}

	/**
	 * Hashes a client address.
	 *
	 * Equivalent forms of one address (such as a compressed and an expanded
	 * IPv6 address) produce the same hash. The context separates hashes made
	 * for different purposes.
	 *
	 * @param string $address IPv4 or IPv6 address.
	 * @param string $context Purpose, such as leads or login.
	 * @return string 64-character lowercase hex, or empty for input that is not an address.
	 */
	public function ip( string $address, string $context = 'default' ): string {
		$normal = self::normalize( $address );

		if ( '' === $normal ) {
			return '';
		}

		return hash_hmac( 'sha256', self::DOMAIN . $context . '|' . $normal, $this->key() );
	}

	/**
	 * The HMAC key.
	 *
	 * @return string
	 */
	private function key(): string {
		return ( null !== $this->salt && '' !== $this->salt ) ? $this->salt : wp_salt( 'auth' );
	}

	/**
	 * Canonical text form of an address, or empty.
	 *
	 * @param string $address Address.
	 * @return string
	 */
	private static function normalize( string $address ): string {
		$address = trim( $address );

		if ( false === filter_var( $address, FILTER_VALIDATE_IP ) ) {
			return '';
		}

		$packed = inet_pton( $address );
		$text   = false === $packed ? false : inet_ntop( $packed );

		return false === $text ? '' : $text;
	}
}
