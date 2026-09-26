<?php
/**
 * Autoloader tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Unit;

use Rameshwari\Core\Support\Autoloader;
use WP_UnitTestCase;

/**
 * Registers a loader over a temporary directory and checks what it will load.
 */
final class AutoloaderTest extends WP_UnitTestCase {

	/**
	 * Temporary class directory.
	 *
	 * @var string
	 */
	private string $dir;

	/**
	 * Unique namespace so tests never collide with real classes.
	 *
	 * @var string
	 */
	private string $prefix;

	/**
	 * Writes one class file and registers a loader for it.
	 */
	public function set_up(): void {
		parent::set_up();

		$unique       = 'T' . wp_generate_password( 8, false );
		$this->prefix = 'RjAutoloadTest\\' . $unique . '\\';
		$this->dir    = get_temp_dir() . 'rj-autoload-' . $unique . '/';

		wp_mkdir_p( $this->dir . 'Deep' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture on the temp filesystem.
		file_put_contents( $this->dir . 'Deep/Found.php', '<?php namespace RjAutoloadTest\\' . $unique . '\\Deep; final class Found {}' );

		Autoloader::register( $this->prefix, $this->dir );
	}

	/**
	 * Removes the temporary class directory.
	 */
	public function tear_down(): void {
		wp_delete_file( $this->dir . 'Deep/Found.php' );
		rmdir( $this->dir . 'Deep' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test cleanup on the temp filesystem.
		rmdir( $this->dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test cleanup on the temp filesystem.
		parent::tear_down();
	}

	/**
	 * A class under the prefix loads from its PSR-4 path.
	 */
	public function test_loads_class_under_prefix(): void {
		$this->assertTrue( class_exists( $this->prefix . 'Deep\\Found' ) );
	}

	/**
	 * A missing file is ignored, not fatal.
	 */
	public function test_missing_class_returns_false(): void {
		$this->assertFalse( class_exists( $this->prefix . 'Deep\\Absent' ) );
	}

	/**
	 * A segment that could escape the base directory is refused before any file check.
	 */
	public function test_rejects_traversal_segment(): void {
		$outside = dirname( $this->dir ) . '/Escaped.php';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture on the temp filesystem.
		file_put_contents( $outside, '<?php final class RjEscapedMarker {}' );

		spl_autoload_call( $this->prefix . '..\\Escaped' );

		$this->assertFalse( class_exists( 'RjEscapedMarker', false ) );

		wp_delete_file( $outside );
	}
}
