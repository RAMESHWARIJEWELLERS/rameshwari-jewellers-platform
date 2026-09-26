<?php
/**
 * Other half of the dependency cycle.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Fixtures\Modules;

use Rameshwari\Core\Container;
use Rameshwari\Core\Module;

/**
 * Needs cycle-a, which needs this.
 */
final class CycleBModule implements Module {

	/**
	 * Module id.
	 *
	 * @return string
	 */
	public static function id(): string {
		return 'cycle-b';
	}

	/**
	 * Needs cycle-a.
	 *
	 * @return array<int, string>
	 */
	public static function requires(): array {
		return array( 'cycle-a' );
	}

	/**
	 * Never reached.
	 *
	 * @param Container $container Container.
	 * @return void
	 */
	public function register( Container $container ): void {}
}
