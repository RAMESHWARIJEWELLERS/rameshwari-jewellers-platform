<?php
/**
 * Alt context tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Unit\Media;

use PHPUnit\Framework\TestCase;
use Rameshwari\Core\Services\Media\Value\AltContext;

/**
 * The context keeps plain text and needs at least one owner name.
 */
final class AltContextTest extends TestCase {

	/**
	 * Values are kept and exposed.
	 */
	public function test_values_are_exposed(): void {
		$c = new AltContext( 'Gold Ring', 'सोने की अंगूठी', 'Rings', 'front view' );

		$this->assertSame( array( 'Gold Ring', 'सोने की अंगूठी', 'Rings', 'front view' ), array( $c->owner_en(), $c->owner_hi(), $c->category(), $c->role() ) );
	}

	/**
	 * Markup, control characters and repeated whitespace are flattened.
	 */
	public function test_text_is_flattened(): void {
		$c = new AltContext( "  <b>Gold</b>\t Ring\n ", '', "Rin\x01gs", "front\u{00A0}\u{00A0}view" );

		$this->assertSame( 'Gold Ring', $c->owner_en() );
		$this->assertSame( 'Rin gs', $c->category() );
		$this->assertSame( 'front view', $c->role() );
	}

	/**
	 * Category and role are optional.
	 */
	public function test_category_and_role_are_optional(): void {
		$c = new AltContext( '', 'अंगूठी' );

		$this->assertSame( '', $c->category() );
		$this->assertSame( '', $c->role() );
	}

	/**
	 * No usable owner name is refused.
	 */
	public function test_requires_an_owner_name(): void {
		$this->expectException( \InvalidArgumentException::class );

		new AltContext( ' <i></i> ', "\u{00A0}", 'Rings', 'front view' );
	}

	/**
	 * The properties are read-only.
	 */
	public function test_context_is_immutable(): void {
		foreach ( ( new \ReflectionClass( AltContext::class ) )->getProperties() as $property ) {
			$this->assertTrue( $property->isReadOnly(), $property->getName() );
		}
	}
}
