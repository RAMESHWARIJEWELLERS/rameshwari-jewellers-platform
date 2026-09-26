<?php
/**
 * Plugin bootstrap integration tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Integration;

use Rameshwari\Core\ModuleRegistry;
use Rameshwari\Core\Plugin;
use Rameshwari\Core\Support\Logger;
use Rameshwari\Tests\Fixtures\Modules\BaseModule;
use Rameshwari\Tests\Fixtures\Modules\CycleAModule;
use Rameshwari\Tests\Fixtures\Modules\CycleBModule;
use Rameshwari\Tests\Fixtures\Modules\DependentModule;
use WP_UnitTestCase;

/**
 * Runs against the plugin as loaded by the test bootstrap.
 */
final class PluginTest extends WP_UnitTestCase {

	/**
	 * All eight constants are defined.
	 */
	public function test_constants_defined(): void {
		foreach ( array( 'RJ_VERSION', 'RJ_DB_VERSION', 'RJ_FILE', 'RJ_PATH', 'RJ_URL', 'RJ_MIN_PHP', 'RJ_MIN_WP', 'RJ_TEXTDOMAIN' ) as $name ) {
			$this->assertTrue( defined( $name ), $name . ' is not defined.' );
		}
	}

	/**
	 * Boot ran on plugins_loaded and exposes a container holding the logger.
	 */
	public function test_booted_with_logger(): void {
		$plugin = Plugin::instance();

		$this->assertInstanceOf( Plugin::class, $plugin );
		$this->assertInstanceOf( Logger::class, $plugin->container()->get( Logger::class ) );
	}

	/**
	 * Calling boot again does not replace the instance.
	 */
	public function test_boot_is_idempotent(): void {
		$first = Plugin::instance();
		Plugin::boot();

		$this->assertSame( $first, Plugin::instance() );
	}

	/**
	 * Stage 1 registers no modules and no post types, taxonomies or options.
	 */
	public function test_stage_1_registers_nothing_else(): void {
		$this->assertSame( array(), ( new Plugin( new ModuleRegistry() ) )->register_modules() );

		foreach ( get_post_types() as $post_type ) {
			$this->assertStringStartsNotWith( 'rj_', $post_type );
		}
		foreach ( get_taxonomies() as $taxonomy ) {
			$this->assertStringStartsNotWith( 'rj_', $taxonomy );
		}
		$this->assertFalse( get_option( 'rj_db_version' ) );
	}

	/**
	 * Modules register in dependency order and can see each other's services.
	 */
	public function test_modules_register_in_order(): void {
		$registry = new ModuleRegistry();
		$registry->add( DependentModule::class );
		$registry->add( BaseModule::class );
		$plugin = new Plugin( $registry );

		$this->assertSame( array( 'base', 'dependent' ), $plugin->register_modules() );
		$this->assertSame( 'dependent', $plugin->container()->get( 'marker.dependent' ) );
	}

	/**
	 * An invalid registry registers nothing and logs an error instead of fataling.
	 */
	public function test_invalid_registry_registers_nothing(): void {
		$registry = new ModuleRegistry();
		$registry->add( CycleAModule::class );
		$registry->add( CycleBModule::class );

		$this->assertSame( array(), ( new Plugin( $registry ) )->register_modules() );
	}

	/**
	 * Activation at supported versions returns without dying or writing options.
	 */
	public function test_activation_is_clean(): void {
		$before = wp_load_alloptions();
		rj_activate();

		$this->assertSame( array_keys( $before ), array_keys( wp_load_alloptions() ) );
	}
}
