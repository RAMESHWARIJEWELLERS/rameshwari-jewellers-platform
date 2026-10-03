<?php
/**
 * Media size contract tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Unit\Media;

use PHPUnit\Framework\TestCase;
use Rameshwari\Core\Services\Media\Sizes;

/**
 * The five registered sizes are the frozen contract.
 */
final class SizesTest extends TestCase {

	/**
	 * Exactly five public sizes, with these contract names, in this order.
	 */
	public function test_exactly_five_contract_names(): void {
		$this->assertSame(
			array( 'thumb', 'card', 'detail', 'hero', 'reel poster' ),
			array_keys( ( new Sizes() )->all() )
		);
	}

	/**
	 * Exact dimensions for all five.
	 */
	public function test_exact_dimensions(): void {
		$expected = array(
			'thumb'       => array( 300, 300 ),
			'card'        => array( 600, 600 ),
			'detail'      => array( 1200, 1500 ),
			'hero'        => array( 1920, 1200 ),
			'reel poster' => array( 720, 1280 ),
		);

		foreach ( ( new Sizes() )->all() as $name => $definition ) {
			$this->assertSame( $expected[ $name ], array( $definition['width'], $definition['height'] ), $name );
		}
	}

	/**
	 * Contract names map to deterministic WordPress keys.
	 */
	public function test_deterministic_wordpress_keys(): void {
		$sizes = new Sizes();

		$this->assertSame( 'rj_thumb', $sizes->key( 'thumb' ) );
		$this->assertSame( 'rj_card', $sizes->key( 'card' ) );
		$this->assertSame( 'rj_detail', $sizes->key( 'detail' ) );
		$this->assertSame( 'rj_hero', $sizes->key( 'hero' ) );
		$this->assertSame( 'rj_reel_poster', $sizes->key( 'reel poster' ) );
		$this->assertCount( 5, array_unique( array_column( $sizes->all(), 'key' ) ) );
	}

	/**
	 * Known names exist; anything else does not.
	 */
	public function test_exists(): void {
		$sizes = new Sizes();

		foreach ( array( 'thumb', 'card', 'detail', 'hero', 'reel poster' ) as $name ) {
			$this->assertTrue( $sizes->exists( $name ), $name );
		}

		foreach ( array( '', 'Thumb', 'rj_card', 'mobile hero', 'reel_poster', 'large' ) as $name ) {
			$this->assertFalse( $sizes->exists( $name ), $name );
		}
	}

	/**
	 * An unknown name fails explicitly, never with a fallback.
	 */
	public function test_unknown_name_fails_explicitly(): void {
		$this->expectException( \InvalidArgumentException::class );

		( new Sizes() )->key( 'large' );
	}

	/**
	 * The raw WordPress key is not a contract name.
	 */
	public function test_wordpress_key_is_not_accepted_as_a_name(): void {
		$this->expectException( \InvalidArgumentException::class );

		( new Sizes() )->key( 'rj_card' );
	}

	/**
	 * The mobile hero guidance size is not registered.
	 */
	public function test_mobile_hero_1080_by_1350_is_not_registered(): void {
		foreach ( ( new Sizes() )->all() as $definition ) {
			$this->assertFalse( 1080 === $definition['width'] && 1350 === $definition['height'] );
		}
	}

	/**
	 * Crop policy follows the frozen CL-3 rules.
	 */
	public function test_crop_policy_matches_the_frozen_definitions(): void {
		$crop = array_column( ( new Sizes() )->all(), 'crop' );

		$this->assertSame(
			array(
				'thumb'       => 'hard',
				'card'        => 'hard',
				'detail'      => 'soft',
				'hero'        => 'soft',
				'reel poster' => 'hard',
			),
			array_combine( array_keys( ( new Sizes() )->all() ), $crop )
		);
	}
}
