<?php
/**
 * Version guard tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Unit;

use WP_UnitTestCase;

/**
 * Covers rj_requirement_errors() at and around the locked floors.
 */
final class RequirementsTest extends WP_UnitTestCase {

	/**
	 * The locked floors are what the constants hold.
	 */
	public function test_constants_hold_locked_floors(): void {
		$this->assertSame( '8.2', RJ_MIN_PHP );
		$this->assertSame( '6.5', RJ_MIN_WP );
	}

	/**
	 * Exactly at both floors passes.
	 */
	public function test_floor_versions_pass(): void {
		$this->assertSame( array(), rj_requirement_errors( '8.2.0', '6.5' ) );
	}

	/**
	 * One patch release below the PHP floor fails on PHP only.
	 */
	public function test_php_below_floor_fails(): void {
		$errors = rj_requirement_errors( '8.1.31', '6.5' );

		$this->assertCount( 1, $errors );
		$this->assertSame( 'PHP', $errors[0]['component'] );
		$this->assertSame( '8.1.31', $errors[0]['current'] );
	}

	/**
	 * WordPress 6.4.x fails on WordPress only. 6.4 was once proposed and is below Elementor Free's floor.
	 */
	public function test_wordpress_64_fails(): void {
		$errors = rj_requirement_errors( '8.3.0', '6.4.5' );

		$this->assertCount( 1, $errors );
		$this->assertSame( 'WordPress', $errors[0]['component'] );
	}

	/**
	 * Both below reports both, PHP first.
	 */
	public function test_both_below_reports_both(): void {
		$errors = rj_requirement_errors( '8.0.30', '6.3' );

		$this->assertSame( array( 'PHP', 'WordPress' ), array_column( $errors, 'component' ) );
	}

	/**
	 * Newer versions pass, so a host upgrade never deactivates the plugin.
	 */
	public function test_newer_versions_pass(): void {
		$this->assertSame( array(), rj_requirement_errors( '8.4.1', '7.0' ) );
	}

	/**
	 * The running test environment meets the floors, so boot was not skipped.
	 */
	public function test_boot_hook_registered(): void {
		$this->assertNotFalse( has_action( 'plugins_loaded', array( \Rameshwari\Core\Plugin::class, 'boot' ) ) );
	}
}
