<?php
/**
 * PHPUnit bootstrap for the Rameshwari platform.
 *
 * Stage 0 loads the WordPress core test library only. No plugin is loaded yet.
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
require_once $rj_tests_dir . '/includes/bootstrap.php';
