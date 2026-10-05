<?php
/**
 * Alt text generation tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Unit\Media;

use PHPUnit\Framework\TestCase;
use Rameshwari\Core\Services\Media\AltText;
use Rameshwari\Core\Services\Media\Value\AltContext;

/**
 * Generation is pure: the same context gives the same text and nothing is read.
 */
final class AltTextTest extends TestCase {

	/**
	 * A full context reads name (Hindi), role, category.
	 */
	public function test_full_context(): void {
		$this->assertSame(
			'Gold Ring (सोने की अंगूठी), front view, Rings',
			( new AltText() )->generate( new AltContext( 'Gold Ring', 'सोने की अंगूठी', 'Rings', 'front view' ) )
		);
	}

	/**
	 * English only.
	 */
	public function test_english_name_only(): void {
		$this->assertSame( 'Gold Ring', ( new AltText() )->generate( new AltContext( 'Gold Ring', '' ) ) );
	}

	/**
	 * Hindi only.
	 */
	public function test_hindi_name_only(): void {
		$this->assertSame( 'अंगूठी', ( new AltText() )->generate( new AltContext( '', 'अंगूठी' ) ) );
	}

	/**
	 * Identical names are not repeated.
	 */
	public function test_identical_names_are_not_repeated(): void {
		$this->assertSame( 'Kada', ( new AltText() )->generate( new AltContext( 'Kada', 'Kada' ) ) );
	}

	/**
	 * Category and role each appear when given, in that order after the name.
	 */
	public function test_category_and_role_are_included(): void {
		$alt = new AltText();

		$this->assertSame( 'Ring, Rings', $alt->generate( new AltContext( 'Ring', '', 'Rings' ) ) );
		$this->assertSame( 'Ring, side view', $alt->generate( new AltContext( 'Ring', '', '', 'side view' ) ) );
		$this->assertSame( 'Ring, side view, Rings', $alt->generate( new AltContext( 'Ring', '', 'Rings', 'side view' ) ) );
	}

	/**
	 * The result is never blank and carries no markup.
	 */
	public function test_result_is_not_blank_and_has_no_markup(): void {
		$text = ( new AltText() )->generate( new AltContext( '<script>x</script>Ring', '' ) );

		$this->assertNotSame( '', $text );
		$this->assertStringNotContainsString( '<', $text );
	}

	/**
	 * The same context gives the same text.
	 */
	public function test_generation_is_deterministic(): void {
		$c = new AltContext( 'Ring', 'अंगूठी', 'Rings', 'front view' );

		$this->assertSame( ( new AltText() )->generate( $c ), ( new AltText() )->generate( $c ) );
	}

	/**
	 * The service has no dependencies and its source reaches for no data source or AI service.
	 */
	public function test_no_repository_or_ai_dependency(): void {
		$class = new \ReflectionClass( AltText::class );

		$this->assertNull( $class->getConstructor() );

		$source = (string) file_get_contents( (string) $class->getFileName() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads this plugin's own source file.

		foreach ( array( 'Repository', 'ProductService', 'get_posts', 'WP_Query', 'get_term', 'wp_remote_', 'openai', 'anthropic', 'curl_' ) as $needle ) {
			$this->assertStringNotContainsStringIgnoringCase( $needle, $source, $needle );
		}
	}
}
