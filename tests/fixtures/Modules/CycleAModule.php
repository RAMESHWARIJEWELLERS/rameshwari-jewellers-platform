<?php
/**
 * Half of a dependency cycle.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Fixtures\Modules;

use Rameshwari\Core\Container;
use Rameshwari\Core\Module;

/**
 * Needs cycle-b, which needs this.
 */
final class CycleAModule implements Module {

	/**
	 * Module id.
	 *
	 * @return string
	 */
	public static function id(): string {
		return 'cycle-a';
	}

	/**
	 * Needs cycle-b.
	 *
	 * @return array<int, string>
	 */
	public static function requires(): array {
		return array( 'cycle-b' );
	}

	/**
	 * Never reached.
	 *
	 * @param Container $container Container.
	 * @return void
	 */
	public function register( Container $container ): void {}
}
