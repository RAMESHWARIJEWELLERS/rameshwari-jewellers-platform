<?php
/**
 * Reuses the base id to test duplicate detection.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Fixtures\Modules;

use Rameshwari\Core\Container;
use Rameshwari\Core\Module;

/**
 * Records its registration in the container.
 */
final class DuplicateBaseModule implements Module {

	/**
	 * Module id.
	 *
	 * @return string
	 */
	public static function id(): string {
		return 'base';
	}

	/**
	 * No dependencies.
	 *
	 * @return array<int, string>
	 */
	public static function requires(): array {
		return array();
	}

	/**
	 * Registers a marker service.
	 *
	 * @param Container $container Container.
	 * @return void
	 */
	public function register( Container $container ): void {
		$container->set( 'marker.base', static fn(): string => 'base' );
	}
}
