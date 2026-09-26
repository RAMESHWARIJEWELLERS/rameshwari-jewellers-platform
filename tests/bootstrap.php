<?php
/**
 * PHPUnit bootstrap for the Rameshwari platform.
 *
 * Loads the WordPress core test library, then the core plugin as a
 * must-use plugin so it is active for every test.
 *
 * @package Rameshwari
 */

$rj_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $rj_tests_dir ) {
	$rj_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $rj_tests_dir . '/includes/functions.php' ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI-only diagnostic.
	fwrite( STDERR, 'WordPress test library not found at ' . $rj_tests_dir . '. Run build/bin/install-wp-tests.sh first.' . PHP_EOL );
	exit( 1 );
}

define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__ ) . '/build/vendor/yoast/phpunit-polyfills' );

require_once $rj_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__ ) . '/plugin/rameshwari-core/rameshwari-core.php';

		\Rameshwari\Core\Support\Autoloader::register( 'Rameshwari\\Tests\\Fixtures\\', __DIR__ . '/fixtures/' );
	}
);

require_once $rj_tests_dir . '/includes/bootstrap.php';
