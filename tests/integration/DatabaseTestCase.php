<?php
/**
 * Base for tests that create real tables.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Integration;

use Rameshwari\Core\Data\Capabilities;
use Rameshwari\Core\Data\PostTypes;
use Rameshwari\Core\Data\Schema;
use Rameshwari\Core\Data\Taxonomies;
use Rameshwari\Core\Support\Logger;
use Rameshwari\Core\Upgrader;
use WP_UnitTestCase;

/**
 * The WordPress test library turns CREATE TABLE into CREATE TEMPORARY
 * TABLE, which SHOW TABLES and dbDelta cannot see. These tests need real
 * tables, so the rewrite is switched off and everything Stage 3 writes is
 * removed by hand after each test: DDL commits the test transaction, so
 * the usual rollback cannot undo it.
 */
abstract class DatabaseTestCase extends WP_UnitTestCase {

	/**
	 * Lines written by the test logger.
	 *
	 * @var array<int, string>
	 */
	protected array $log = array();

	/**
	 * Uses real tables for this test.
	 */
	public function set_up(): void {
		parent::set_up();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$this->clean();
	}

	/**
	 * Removes the tables, options, roles and capabilities Stage 3 writes.
	 */
	public function tear_down(): void {
		$this->clean();

		// A deactivation test unregisters the post types and taxonomies for the rest of the process.
		PostTypes::register_all();
		Taxonomies::register_all();

		parent::tear_down();
	}

	/**
	 * A logger that records into $this->log.
	 *
	 * @return Logger
	 */
	protected function logger(): Logger {
		return new Logger(
			function ( string $line ): void {
				$this->log[] = $line;
			},
			true
		);
	}

	/**
	 * Runs a callback as if WordPress were running the given hook.
	 *
	 * WordPress's doing_action() reads $wp_current_filter, so this reproduces exactly
	 * what the entry-point checks look at, without firing core's other
	 * callbacks on that hook.
	 *
	 * @param string   $hook     Hook name.
	 * @param callable $callback Callback.
	 * @return mixed The callback's result.
	 */
	protected function in_hook( string $hook, callable $callback ): mixed {
		global $wp_current_filter;

		$wp_current_filter[] = $hook; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulates the running hook.

		try {
			return $callback();
		} finally {
			array_pop( $wp_current_filter );
		}
	}

	/**
	 * Runs a callback on an admin page.
	 *
	 * @param callable $callback Callback.
	 * @return mixed The callback's result.
	 */
	protected function on_admin_page( callable $callback ): mixed {
		set_current_screen( 'dashboard' );

		try {
			return $callback();
		} finally {
			set_current_screen( 'front' );
		}
	}

	/**
	 * Migrates through the admin_init entry point on an admin page.
	 *
	 * @param Upgrader|null $upgrader Router. Null uses the plugin's own migrations.
	 * @return bool
	 */
	protected function install( ?Upgrader $upgrader = null ): bool {
		$upgrader = $upgrader ?? new Upgrader( $this->logger() );

		return (bool) $this->on_admin_page( fn() => $this->in_hook( 'admin_init', static fn(): bool => $upgrader->admin_init() ) );
	}

	/**
	 * Fires the plugin's real admin_init callback on an admin page.
	 *
	 * @return void
	 */
	protected function admin_init(): void {
		$this->on_admin_page( fn() => $this->in_hook( 'admin_init', static fn() => Upgrader::on_admin_init() ) );
	}

	/**
	 * Fires this plugin's real activation hook.
	 *
	 * @return void
	 */
	protected function activate(): void {
		do_action( 'activate_' . plugin_basename( RJ_FILE ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core's activation hook for this plugin; the real lifecycle path under test.
	}

	/**
	 * Whether a table exists.
	 *
	 * @param string $table Full table name.
	 * @return bool
	 */
	protected function table_exists( string $table ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test inspection.
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	/**
	 * SHOW CREATE TABLE for every table that exists.
	 *
	 * @return array<string, string>
	 */
	protected function snapshot(): array {
		global $wpdb;

		$snapshot = array();

		foreach ( Schema::table_names() as $table ) {
			if ( $this->table_exists( $table ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Read-only SHOW CREATE TABLE for test inspection.
				$row                = $wpdb->get_row( $wpdb->prepare( 'SHOW CREATE TABLE %i', $table ), ARRAY_N );
				$snapshot[ $table ] = is_array( $row ) ? (string) $row[1] : '';
			}
		}

		return $snapshot;
	}

	/**
	 * Removes Stage 3 options, roles and capabilities, then drops the tables.
	 *
	 * The order matters. The test library runs each test inside a
	 * transaction and rolls it back afterwards, but DROP TABLE commits
	 * implicitly. Deleting first and dropping last means the drop commits
	 * the deletions too, so the closing rollback cannot bring back an
	 * option such as rj_db_version for a later test to find.
	 *
	 * @return void
	 */
	private function clean(): void {
		global $wpdb;

		foreach ( array( Upgrader::VERSION_OPTION, Upgrader::STATE_OPTION, 'rj_lock_' . Upgrader::LOCK_NAME ) as $option ) {
			delete_option( $option );
		}

		foreach ( array_keys( Capabilities::ROLES ) as $role ) {
			remove_role( $role );
		}

		$admin = get_role( 'administrator' );

		if ( null !== $admin ) {
			foreach ( Capabilities::ALL as $cap ) {
				$admin->remove_cap( $cap );
			}
		}

		foreach ( Schema::table_names() as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Test cleanup.
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
		}
	}
}
