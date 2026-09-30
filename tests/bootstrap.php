<?php
/**
 * PHPUnit bootstrap for the Rameshwari platform.
 *
 * Loads the WordPress core test library, then the core plugin as a
 * must-use plugin so it is active for every test.
 *
 * @package Rameshwari
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- `rj` is the approved project prefix; WPCS rejects prefixes under three characters. Test-bootstrap global only.
$rj_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $rj_tests_dir ) {
	$rj_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

// PHPUnit 9 runs @runInSeparateProcess tests in a child PHP process. In
// PHPUnit\Util\PHP\AbstractPhpProcess::processChildResult(), non-empty child stderr
// becomes the test's error, new PHPUnit\Framework\Exception( trim( $stderr ) ), and so
// does stdout that fails to unserialize. In that child, WordPress deprecation notices
// reach those streams three ways:
// 1. The WordPress test bootstrap reinstalls the test site by running install.php in a
// further PHP process through system(). That process loads WordPress, and its notices
// go to its stderr, which is the child's stderr. No ini_set() or error_reporting() in
// this process can reach it, which is why every earlier attempt failed. The parent run
// has already installed the site, so the child skips the reinstall through the test
// library's own WP_TESTS_SKIP_INSTALL switch.
// 2. wp_debug_mode() turns display_errors on because WP_DEBUG is true, printing notices
// to stdout ahead of the serialized result. WP_DEBUG_DISPLAY false keeps it off.
// 3. The command-line error log writes to stderr when error_log is unset. It is sent to
// a file instead.
// The child is detected by the display_errors value PHPUnit's TestCaseMethod.tpl sets
// before loading this bootstrap. error_reporting and PHPUnit's error handler are
// unchanged, and every notice is still logged, to the file.
if ( 'stderr' === ini_get( 'display_errors' ) ) {
	putenv( 'WP_TESTS_SKIP_INSTALL=1' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Isolated PHPUnit child only; see above.

	if ( ! defined( 'WP_DEBUG_DISPLAY' ) ) {
		define( 'WP_DEBUG_DISPLAY', false );
	}

	ini_set( 'display_errors', '0' ); // phpcs:ignore WordPress.PHP.IniSet.display_errors_Disallowed -- Isolated PHPUnit child only; see above.
	ini_set( 'error_log', rtrim( sys_get_temp_dir(), '/\\' ) . '/rameshwari-phpunit-isolated.log' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Isolated PHPUnit child only; see above.
}

if ( ! file_exists( $rj_tests_dir . '/includes/functions.php' ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI-only diagnostic.
	fwrite( STDERR, 'WordPress test library not found at ' . $rj_tests_dir . '. Run build/bin/install-wp-tests.sh first.' . PHP_EOL );
	exit( 1 );
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Name is fixed by the WordPress test library, which reads it.
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
