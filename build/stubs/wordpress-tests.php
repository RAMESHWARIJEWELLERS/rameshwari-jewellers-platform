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
	 * WordPress test factory.
	 *
	 * @return WP_UnitTest_Factory
	 */
	public static function factory() {
		return new WP_UnitTest_Factory();
	}

	/**
	 * Assert that a value is a WordPress error.
	 *
	 * @param mixed $actual Value to check.
	 * @param string $message Assertion message.
	 * @return void
	 */
	public function assertWPError( $actual, $message = '' ) {}

	/**
	 * Set the permalink structure used by the test.
	 *
	 * @param string $permalink_structure Permalink structure.
	 * @return void
	 */
	public function set_permalink_structure( $permalink_structure ) {}

	/**
	 * Set up a simulated request.
	 *
	 * @param string $path Request path.
	 * @return void
	 */
	public function go_to( $path ) {}

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
 * Factory for objects used by WordPress integration tests.
 */
class WP_UnitTest_Factory {

	/** @var WP_UnitTest_Factory_For_User */
	public $user;

	/** @var WP_UnitTest_Factory_For_Post */
	public $post;

	/** @var WP_UnitTest_Factory_For_Term */
	public $term;

	/** @var WP_UnitTest_Factory_For_Attachment */
	public $attachment;
}

/** Factory for users. */
class WP_UnitTest_Factory_For_User {
	/** @param array<string, mixed> $args @return int */
	public function create( $args = array() ) {}
}

/** Factory for posts. */
class WP_UnitTest_Factory_For_Post {
	/** @param array<string, mixed> $args @return int */
	public function create( $args = array() ) {}
}

/** Factory for terms. */
class WP_UnitTest_Factory_For_Term {
	/** @param array<string, mixed> $args @return int */
	public function create( $args = array() ) {}
}

/** Factory for attachments. */
class WP_UnitTest_Factory_For_Attachment {
	/** @param array<string, mixed> $args @return int */
	public function create( $args = array() ) {}
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
