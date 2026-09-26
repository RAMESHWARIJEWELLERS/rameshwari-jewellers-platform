<?php
/**
 * Test module that needs BaseModule.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Fixtures\Modules;

use Rameshwari\Core\Container;
use Rameshwari\Core\Module;

/**
 * Fails loudly if registered before its dependency.
 */
final class DependentModule implements Module {

	/**
	 * Module id.
	 *
	 * @return string
	 */
	public static function id(): string {
		return 'dependent';
	}

	/**
	 * Needs base.
	 *
	 * @return array<int, string>
	 */
	public static function requires(): array {
		return array( 'base' );
	}

	/**
	 * Registers only if base is already present.
	 *
	 * @param Container $container Container.
	 * @return void
	 * @throws \LogicException When base was not registered first.
	 */
	public function register( Container $container ): void {
		if ( ! $container->has( 'marker.base' ) ) {
			throw new \LogicException( 'Registered before base.' );
		}
		$container->set( 'marker.dependent', static fn(): string => 'dependent' );
	}
}
