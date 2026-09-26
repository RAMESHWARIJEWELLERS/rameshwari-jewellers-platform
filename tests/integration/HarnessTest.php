<?php
/**
 * Stage 0 harness test.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Integration;

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Proves the harness runs against a real WordPress install at or above
 * the locked floors. The WordPress test bootstrap installs WordPress into
 * the test database before this runs, so the database is exercised too.
 */
final class HarnessTest extends TestCase {

	/**
	 * WordPress is loaded and meets the 6.5 floor.
	 */
	public function test_wordpress_meets_minimum_version(): void {
		$this->assertTrue( function_exists( 'add_action' ) );
		$this->assertTrue( version_compare( get_bloginfo( 'version' ), '6.5', '>=' ) );
	}

	/**
	 * PHP meets the 8.2 floor.
	 */
	public function test_php_meets_minimum_version(): void {
		$this->assertTrue( version_compare( PHP_VERSION, '8.2', '>=' ) );
	}
}
