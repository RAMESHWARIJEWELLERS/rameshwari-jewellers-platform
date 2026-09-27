<?php
/**
 * Static-analysis stubs for the WordPress core test library.
 *
 * PHPStan reads this file through scanFiles; it is never executed and never
 * loaded by PHPUnit. The real classes come from the test library that
 * build/bin/install-wp-tests.sh downloads, which does not exist when
 * PHPStan runs. Only the symbols the tests actually use are declared.
 *
 * @package Rameshwari
 */

/**
 * Base class for WordPress integration tests.
 */
abstract class WP_UnitTestCase extends \PHPUnit\Framework\TestCase {

	/**
	 * Runs before each test.
	 *
	 * @return void
	 */
	public function set_up(): void {}

	/**
	 * Runs after each test.
	 *
	 * @return void
	 */
	public function tear_down(): void {}

	/**
	 * Query filter that makes CREATE TABLE temporary inside a test.
	 *
	 * @param string $query SQL.
	 * @return string
	 */
	public function _create_temporary_tables( $query ) {
		return $query;
	}

	/**
	 * Query filter that makes DROP TABLE temporary inside a test.
	 *
	 * @param string $query SQL.
	 * @return string
	 */
	public function _drop_temporary_tables( $query ) {
		return $query;
	}
}

/**
 * Adds a hook before WordPress loads in the test run.
 *
 * @param string   $hook_name     Hook name.
 * @param callable $callback      Callback.
 * @param int      $priority      Priority.
 * @param int      $accepted_args Number of accepted arguments.
 * @return bool
 */
function tests_add_filter( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) {
	return true;
}
