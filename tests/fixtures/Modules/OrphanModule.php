<?php
/**
 * Module whose dependency is never added.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Fixtures\Modules;

use Rameshwari\Core\Container;
use Rameshwari\Core\Module;

/**
 * Needs a module that does not exist.
 */
final class OrphanModule implements Module {

	/**
	 * Module id.
	 *
	 * @return string
	 */
	public static function id(): string {
		return 'orphan';
	}

	/**
	 * Needs absent.
	 *
	 * @return array<int, string>
	 */
	public static function requires(): array {
		return array( 'absent' );
	}

	/**
	 * Never reached.
	 *
	 * @param Container $container Container.
	 * @return void
	 */
	public function register( Container $container ): void {}
}
