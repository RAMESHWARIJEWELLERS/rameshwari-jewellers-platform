<?php
/**
 * Asset registration tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Unit;

use Rameshwari\Core\Support\Assets;
use WP_UnitTestCase;

/**
 * Registration, versioning and conditional enqueueing against fixture files.
 */
final class AssetsTest extends WP_UnitTestCase {

	/**
	 * Removes the test handles.
	 */
	public function tear_down(): void {
		wp_deregister_script( 'rameshwari-test' );
		wp_deregister_style( 'rameshwari-test-style' );
		parent::tear_down();
	}

	/**
	 * A registrar over the fixture directory.
	 *
	 * @param bool $development Development mode.
	 * @return Assets
	 */
	private function assets( bool $development ): Assets {
		return new Assets( __DIR__ . '/../fixtures/assets/', 'https://example.test/assets/', '9.9.9', $development );
	}

	/**
	 * The registered version of a script.
	 *
	 * @param string $handle Handle.
	 * @return string
	 */
	private function script_version( string $handle ): string {
		return (string) wp_scripts()->registered[ $handle ]->ver;
	}

	/**
	 * Development uses the file's modification time.
	 */
	public function test_development_version_is_modification_time(): void {
		$this->assets( true )->script( 'rameshwari-test', 'app.js' );

		$this->assertSame( (string) filemtime( __DIR__ . '/../fixtures/assets/app.js' ), $this->script_version( 'rameshwari-test' ) );
	}

	/**
	 * Production uses the plugin version.
	 */
	public function test_production_version_is_plugin_version(): void {
		$this->assets( false )->script( 'rameshwari-test', 'app.js' );

		$this->assertSame( '9.9.9', $this->script_version( 'rameshwari-test' ) );
	}

	/**
	 * A missing file falls back to the plugin version.
	 */
	public function test_missing_file_falls_back_to_plugin_version(): void {
		$this->assets( true )->script( 'rameshwari-test', 'missing.js' );

		$this->assertSame( '9.9.9', $this->script_version( 'rameshwari-test' ) );
	}

	/**
	 * Styles register too.
	 */
	public function test_registers_style(): void {
		$this->assets( false )->style( 'rameshwari-test-style', 'app.css' );

		$this->assertTrue( wp_style_is( 'rameshwari-test-style', 'registered' ) );
	}

	/**
	 * Handles must carry the rameshwari- prefix.
	 */
	public function test_rejects_unprefixed_handle(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->assets( false )->script( 'jquery-extra', 'app.js' );
	}

	/**
	 * Paths cannot leave the asset directory.
	 */
	public function test_rejects_path_traversal(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->assets( false )->script( 'rameshwari-test', '../secret.js' );
	}

	/**
	 * Assets enqueue only when the condition holds.
	 */
	public function test_conditional_enqueue(): void {
		$assets = $this->assets( false );
		$assets->script( 'rameshwari-test', 'app.js' );

		$this->assertFalse( $assets->enqueue_when( 'rameshwari-test', false ) );
		$this->assertFalse( wp_script_is( 'rameshwari-test', 'enqueued' ) );
		$this->assertTrue( $assets->enqueue_when( 'rameshwari-test', static fn(): bool => true ) );
		$this->assertTrue( wp_script_is( 'rameshwari-test', 'enqueued' ) );
	}

	/**
	 * Enqueueing an unknown handle reports failure.
	 */
	public function test_enqueue_unknown_handle(): void {
		$this->assertFalse( $this->assets( false )->enqueue( 'rameshwari-nothing' ) );
	}
}
