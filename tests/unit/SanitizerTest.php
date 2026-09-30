<?php
/**
 * Sanitizer tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Unit;

use Rameshwari\Core\Support\Sanitizer;
use WP_UnitTestCase;

/**
 * Valid, invalid and boundary input for each sanitiser.
 */
final class SanitizerTest extends WP_UnitTestCase {

	/**
	 * Single-line text loses tags and respects the length limit.
	 */
	public function test_text(): void {
		$this->assertSame( 'Gold', Sanitizer::text( '<b>Gold</b> ' ) );
		$this->assertSame( 'Gol', Sanitizer::text( 'Golden', 3 ) );
		$this->assertSame( '', Sanitizer::text( array( 'x' ) ) );
	}

	/**
	 * Multi-line text keeps its line breaks.
	 */
	public function test_textarea_keeps_line_breaks(): void {
		$this->assertSame( "line one\nline two", Sanitizer::textarea( "line one\nline two" ) );
	}

	/**
	 * Keys are lowercase and stripped of other characters.
	 */
	public function test_key(): void {
		$this->assertSame( 'goldset', Sanitizer::key( 'Gold Set!' ) );
	}

	/**
	 * Whole numbers: valid, invalid and clamped.
	 */
	public function test_int(): void {
		$this->assertSame( 42, Sanitizer::int( '42' ) );
		$this->assertSame( 7, Sanitizer::int( ' 7 ' ) );
		$this->assertSame( -1, Sanitizer::int( '4.5', -1 ) );
		$this->assertSame( 9, Sanitizer::int( 1.5, 9 ) );
		$this->assertSame( 0, Sanitizer::int( 'abc' ) );
		$this->assertSame( 100, Sanitizer::int( 150, 0, 0, 100 ) );
		$this->assertSame( 0, Sanitizer::int( -5, 0, 0 ) );
	}

	/**
	 * Decimals are rounded, rejected when non-numeric, and clamped.
	 */
	public function test_decimal(): void {
		$this->assertSame( 12.346, Sanitizer::decimal( '12.3456', 3 ) );
		$this->assertSame( 0.0, Sanitizer::decimal( 'abc', 3 ) );
		$this->assertSame( 0.0, Sanitizer::decimal( -1, 3, 0.0, 0.0 ) );
	}

	/**
	 * Booleans accept only explicit true values.
	 */
	public function test_bool(): void {
		$this->assertTrue( Sanitizer::bool( 'yes' ) );
		$this->assertTrue( Sanitizer::bool( 1 ) );
		$this->assertFalse( Sanitizer::bool( 'off' ) );
		$this->assertFalse( Sanitizer::bool( 0 ) );
		$this->assertFalse( Sanitizer::bool( array() ) );
	}

	/**
	 * Invalid email addresses become empty.
	 */
	public function test_email(): void {
		$this->assertSame( 'owner@example.com', Sanitizer::email( 'owner@example.com' ) );
		$this->assertSame( '', Sanitizer::email( 'not-an-email' ) );
	}

	/**
	 * Only http and https URLs survive.
	 */
	public function test_url(): void {
		$this->assertSame( 'https://example.com/a', Sanitizer::url( 'https://example.com/a' ) );
		$this->assertSame( '', Sanitizer::url( 'javascript:alert(1)' ) );
		$this->assertSame( '', Sanitizer::url( array() ) );
	}

	/**
	 * Values outside the allowed set become the fallback.
	 */
	public function test_enum(): void {
		$allowed = array( 'gold', 'silver' );

		$this->assertSame( 'gold', Sanitizer::enum( 'gold', $allowed, 'silver' ) );
		$this->assertSame( 'silver', Sanitizer::enum( 'tin', $allowed, 'silver' ) );
		$this->assertSame( 'silver', Sanitizer::enum( 5, $allowed, 'silver' ) );
	}

	/**
	 * Id lists keep positive, unique ids in order, up to the limit.
	 */
	public function test_id_list(): void {
		$this->assertSame( array( 3, 5, 9 ), Sanitizer::id_list( '3, 5,3,x,-2,0,9' ) );
		$this->assertSame( array( 3, 5 ), Sanitizer::id_list( '3,5,9', 2 ) );
		$this->assertSame( array( 7, 8 ), Sanitizer::id_list( array( 7, '8' ) ) );
		$this->assertSame( array(), Sanitizer::id_list( 12 ) );
	}
}
