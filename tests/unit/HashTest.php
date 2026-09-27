<?php
/**
 * Address hashing tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Unit;

use Rameshwari\Core\Support\Hash;
use WP_UnitTestCase;

/**
 * Stable, salted, one-way hashing of client addresses.
 */
final class HashTest extends WP_UnitTestCase {

	/**
	 * The same address and salt always give the same 64-character hex hash.
	 */
	public function test_stable_hash(): void {
		$hash = new Hash( 'salt-a' );

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $hash->ip( '203.0.113.9' ) );
		$this->assertSame( $hash->ip( '203.0.113.9' ), $hash->ip( '203.0.113.9' ) );
	}

	/**
	 * Different addresses give different hashes.
	 */
	public function test_different_addresses_differ(): void {
		$hash = new Hash( 'salt-a' );

		$this->assertNotSame( $hash->ip( '203.0.113.9' ), $hash->ip( '203.0.113.10' ) );
	}

	/**
	 * The salt changes the hash.
	 */
	public function test_salt_changes_hash(): void {
		$this->assertNotSame( ( new Hash( 'salt-a' ) )->ip( '203.0.113.9' ), ( new Hash( 'salt-b' ) )->ip( '203.0.113.9' ) );
	}

	/**
	 * Hashes made for different purposes do not match.
	 */
	public function test_context_separates_hashes(): void {
		$hash = new Hash( 'salt-a' );

		$this->assertNotSame( $hash->ip( '203.0.113.9', 'leads' ), $hash->ip( '203.0.113.9', 'login' ) );
	}

	/**
	 * Equivalent IPv6 spellings give one hash.
	 */
	public function test_ipv6_forms_are_equal(): void {
		$hash = new Hash( 'salt-a' );

		$this->assertSame( $hash->ip( '2001:db8::1' ), $hash->ip( '2001:0db8:0:0:0:0:0:1' ) );
	}

	/**
	 * Input that is not an address gives an empty string.
	 */
	public function test_invalid_address_returns_empty(): void {
		$hash = new Hash( 'salt-a' );

		foreach ( array( '', 'not-an-ip', '999.1.1.1' ) as $input ) {
			$this->assertSame( '', $hash->ip( $input ), $input );
		}
	}

	/**
	 * Without an override the WordPress salt is used, and the address never appears in the output.
	 */
	public function test_default_salt(): void {
		$result = ( new Hash() )->ip( '203.0.113.9' );

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $result );
		$this->assertStringNotContainsString( '203.0.113.9', $result );
	}

	/**
	 * The class has no path that stores or logs the raw address.
	 */
	public function test_no_persistence_or_logging_path(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local source file.
		$source = (string) file_get_contents( __DIR__ . '/../../plugin/rameshwari-core/src/Support/Hash.php' );

		foreach ( array( 'error_log', 'update_option', 'add_option', 'set_transient', 'wp_cache_set', 'Logger' ) as $call ) {
			$this->assertStringNotContainsString( $call, $source, $call );
		}
	}
}
