<?php
/**
 * Media size registration tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Integration\Media;

use Rameshwari\Core\Services\Media\Sizes;

/**
 * Registration with WordPress.
 */
final class SizesRegistrationTest extends \WP_UnitTestCase {

	/**
	 * Removes the registered sizes so tests stay independent.
	 */
	public function tear_down(): void {
		foreach ( ( new Sizes() )->all() as $definition ) {
			remove_image_size( $definition['key'] );
		}

		parent::tear_down();
	}

	/**
	 * All five sizes are registered with exact dimensions and the right crop mode.
	 */
	public function test_register_adds_the_five_sizes(): void {
		$sizes = new Sizes();
		$sizes->register();

		$registered = wp_get_additional_image_sizes();

		foreach ( $sizes->all() as $name => $definition ) {
			$key = $definition['key'];

			$this->assertArrayHasKey( $key, $registered, $name );
			$this->assertSame( $definition['width'], $registered[ $key ]['width'], $name );
			$this->assertSame( $definition['height'], $registered[ $key ]['height'], $name );
			$this->assertNotFalse( $registered[ $key ]['crop'], $name );
		}

		$this->assertTrue( $registered['rj_thumb']['crop'] );
		$this->assertTrue( $registered['rj_card']['crop'] );
		$this->assertTrue( $registered['rj_reel_poster']['crop'] );
		$this->assertSame( array( 'center', 'center' ), $registered['rj_detail']['crop'] );
		$this->assertSame( array( 'center', 'center' ), $registered['rj_hero']['crop'] );
	}

	/**
	 * Registering twice changes nothing.
	 */
	public function test_register_is_idempotent(): void {
		$sizes = new Sizes();
		$sizes->register();
		$sizes->register();

		$keys = array_keys( wp_get_additional_image_sizes() );

		$this->assertCount( 5, array_intersect( $keys, array_column( $sizes->all(), 'key' ) ) );
	}
}
