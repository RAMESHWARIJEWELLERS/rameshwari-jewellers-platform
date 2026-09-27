<?php
/**
 * Escaper tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Unit;

use Rameshwari\Core\Support\Escaper;
use WP_UnitTestCase;

/**
 * One check per output context.
 */
final class EscaperTest extends WP_UnitTestCase {

	/**
	 * HTML text is escaped.
	 */
	public function test_html(): void {
		$this->assertSame( '&lt;b&gt;&amp;', Escaper::html( '<b>&' ) );
	}

	/**
	 * Attribute quotes are escaped.
	 */
	public function test_attr(): void {
		$this->assertSame( '&quot;x&quot;', Escaper::attr( '"x"' ) );
	}

	/**
	 * Unsafe URL schemes are removed.
	 */
	public function test_url(): void {
		$this->assertSame( '', Escaper::url( 'javascript:alert(1)' ) );
		$this->assertSame( 'https://example.com/', Escaper::url( 'https://example.com/' ) );
	}

	/**
	 * Textarea content is escaped.
	 */
	public function test_textarea(): void {
		$this->assertSame( '&lt;b&gt;', Escaper::textarea( '<b>' ) );
	}

	/**
	 * Rich text keeps allowed tags and drops scripts.
	 */
	public function test_rich(): void {
		$output = Escaper::rich( '<strong>ok</strong><script>alert(1)</script>' );

		$this->assertStringContainsString( '<strong>ok</strong>', $output );
		$this->assertStringNotContainsString( '<script>', $output );
	}
}
