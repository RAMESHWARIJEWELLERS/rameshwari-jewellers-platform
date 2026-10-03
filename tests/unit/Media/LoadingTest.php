<?php
/**
 * Loading hint tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Unit\Media;

use PHPUnit\Framework\TestCase;
use Rameshwari\Core\Services\Media\Loading;
use Rameshwari\Core\Services\Media\Sizes;
use Rameshwari\Core\Services\Media\Value\LoadingHint;

/**
 * The caller decides above the fold; the service only maps that to attributes.
 */
final class LoadingTest extends TestCase {

	/**
	 * A hero image loads eagerly with high priority.
	 */
	public function test_hero_is_eager_and_prioritised(): void {
		$hint = ( new Loading( new Sizes() ) )->hint( false, true );

		$this->assertSame( 'eager', $hint->loading() );
		$this->assertSame( 'high', $hint->fetchpriority() );
	}

	/**
	 * An above-the-fold image loads eagerly with high priority.
	 */
	public function test_above_fold_is_eager_and_prioritised(): void {
		$hint = ( new Loading( new Sizes() ) )->hint( true, false );

		$this->assertSame( 'eager', $hint->loading() );
		$this->assertSame( 'high', $hint->fetchpriority() );
	}

	/**
	 * Hero and above the fold together stay eager and prioritised.
	 */
	public function test_hero_above_fold_is_eager_and_prioritised(): void {
		$this->assertTrue( ( new Loading( new Sizes() ) )->hint( true, true )->equals( new LoadingHint( 'eager', 'high', 'auto' ) ) );
	}

	/**
	 * Any other image is lazy, async and makes no priority claim.
	 */
	public function test_below_fold_is_lazy_async_and_not_prioritised(): void {
		$hint = ( new Loading( new Sizes() ) )->hint( false, false );

		$this->assertSame( 'lazy', $hint->loading() );
		$this->assertSame( 'async', $hint->decoding() );
		$this->assertSame( 'auto', $hint->fetchpriority() );
	}

	/**
	 * The same inputs always give an equal hint.
	 */
	public function test_hint_is_deterministic(): void {
		$loading = new Loading( new Sizes() );

		$this->assertTrue( $loading->hint( true, false )->equals( $loading->hint( true, false ) ) );
		$this->assertFalse( $loading->hint( true, false )->equals( $loading->hint( false, false ) ) );
	}

	/**
	 * The service holds only the size contract, so it has nothing to guess a layout from.
	 */
	public function test_service_holds_no_layout_state(): void {
		$properties = ( new \ReflectionClass( Loading::class ) )->getProperties();

		$this->assertSame( array( 'sizes' ), array_map( static fn ( \ReflectionProperty $p ): string => $p->getName(), $properties ) );
		$this->assertSame( array( '__construct', 'hint', 'dimensions' ), array_map( static fn ( \ReflectionMethod $m ): string => $m->getName(), ( new \ReflectionClass( Loading::class ) )->getMethods( \ReflectionMethod::IS_PUBLIC ) ) );
	}

	/**
	 * Declared dimensions are exactly the registered size.
	 */
	public function test_dimensions_come_from_the_registered_size(): void {
		$loading = new Loading( new Sizes() );

		foreach ( ( new Sizes() )->all() as $name => $definition ) {
			$this->assertSame(
				array(
					'width'  => $definition['width'],
					'height' => $definition['height'],
				),
				$loading->dimensions( $name ),
				$name
			);
		}
	}

	/**
	 * An unknown size fails explicitly and never falls back.
	 */
	public function test_unknown_size_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );

		( new Loading( new Sizes() ) )->dimensions( 'rj_card' );
	}

	/**
	 * The returned hint cannot be changed.
	 */
	public function test_hint_is_immutable(): void {
		$hint = ( new Loading( new Sizes() ) )->hint( false, false );

		foreach ( ( new \ReflectionClass( $hint ) )->getProperties() as $property ) {
			$this->assertTrue( $property->isReadOnly(), $property->getName() );
		}
	}
}
