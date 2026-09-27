<?php
/**
 * Migration router tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Integration;

use Rameshwari\Core\Activator;
use Rameshwari\Core\Data\Migrations\Migration_001;
use Rameshwari\Core\Data\Schema;
use Rameshwari\Core\Installer;
use Rameshwari\Core\Support\Lock;
use Rameshwari\Core\Upgrader;
use Rameshwari\Tests\Fixtures\Migrations\FailingMigration;
use Rameshwari\Tests\Fixtures\Migrations\FirstMigration;
use Rameshwari\Tests\Fixtures\Migrations\SecondMigration;

require_once __DIR__ . '/DatabaseTestCase.php';
require_once __DIR__ . '/../fixtures/Migrations/RecordingMigrations.php';

/**
 * Ordering, idempotency, locking, context and failure.
 */
final class MigrationTest extends DatabaseTestCase {

	/**
	 * Resets the fixture log.
	 */
	public function set_up(): void {
		parent::set_up();
		FirstMigration::$ran = array();
	}

	/**
	 * Running Migration 001 again changes nothing.
	 */
	public function test_rerun_is_idempotent(): void {
		$this->install();
		$before = $this->snapshot();

		( new Migration_001() )->up();

		$this->assertCount( 8, $before );
		$this->assertSame( $before, $this->snapshot() );
	}

	/**
	 * The schema version is stored after the migration.
	 */
	public function test_version_recorded(): void {
		$this->assertSame( 0, Upgrader::stored_version() );

		$this->install();

		$this->assertSame( 1, Upgrader::stored_version() );
		$this->assertSame( RJ_DB_VERSION, Upgrader::stored_version() );
	}

	/**
	 * Completed migrations are recorded in the install state.
	 */
	public function test_install_state_records_migrations(): void {
		$this->install();

		$state = get_option( Upgrader::STATE_OPTION );

		$this->assertIsArray( $state );
		$this->assertSame( array( 1 ), $state['migrations'] );
	}

	/**
	 * Pending migrations run in number order, whatever order they are listed in.
	 */
	public function test_migrations_run_in_order(): void {
		$upgrader = new Upgrader(
			$this->logger(),
			array(
				2 => SecondMigration::class,
				1 => FirstMigration::class,
			)
		);

		$this->assertTrue( $this->install( $upgrader ) );
		$this->assertSame( array( 'first', 'second' ), FirstMigration::$ran );
		$this->assertSame( 2, Upgrader::stored_version() );
	}

	/**
	 * A second run while the lock is held does nothing.
	 */
	public function test_locked_run_is_skipped(): void {
		$lock  = new Lock();
		$token = (string) $lock->acquire( Upgrader::LOCK_NAME, 60 );

		$this->assertFalse( $this->install() );
		$this->assertSame( 0, Upgrader::stored_version() );
		$this->assertFalse( $this->table_exists( Schema::table( 'rj_leads' ) ) );

		$lock->release( Upgrader::LOCK_NAME, $token );
	}

	/**
	 * Called directly, outside its hook or environment, no entry point migrates.
	 */
	public function test_direct_invocation_refused(): void {
		$upgrader = new Upgrader( $this->logger() );

		$this->assertFalse( $upgrader->admin_init() );
		$this->assertFalse( $upgrader->activation() );
		$this->assertFalse( $upgrader->cli() );
		$this->assertFalse( Installer::install( Installer::ADMIN_INIT ) );
		$this->assertFalse( Installer::install( 'front' ) );

		Upgrader::on_admin_init();
		Activator::activate();

		$this->assertSame( 0, Upgrader::stored_version() );
		$this->assertFalse( $this->table_exists( Schema::table( 'rj_leads' ) ) );
	}

	/**
	 * An admin-ajax request fires admin_init but never migrates.
	 */
	public function test_ajax_does_not_migrate(): void {
		add_filter( 'wp_doing_ajax', '__return_true' );
		$this->assertFalse( $this->install() );
		remove_filter( 'wp_doing_ajax', '__return_true' );

		$this->assertSame( 0, Upgrader::stored_version() );
	}

	/**
	 * A cron request never migrates.
	 */
	public function test_cron_does_not_migrate(): void {
		add_filter( 'wp_doing_cron', '__return_true' );
		$this->assertFalse( $this->install() );
		remove_filter( 'wp_doing_cron', '__return_true' );

		$this->assertSame( 0, Upgrader::stored_version() );
	}

	/**
	 * Without WP-CLI the CLI entry point refuses.
	 */
	public function test_cli_refused_without_wp_cli(): void {
		$this->assertFalse( defined( 'WP_CLI' ) && WP_CLI );
		$this->assertFalse( ( new Upgrader( $this->logger() ) )->cli() );
		$this->assertSame( 0, Upgrader::stored_version() );
	}

	/**
	 * Under WP-CLI the CLI entry point migrates. Runs alone because the
	 * constant cannot be undefined afterwards.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_cli_accepted_under_wp_cli(): void {
		define( 'WP_CLI', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WP-CLI's own constant.

		$this->assertTrue( ( new Upgrader( $this->logger() ) )->cli() );
		$this->assertSame( 1, Upgrader::stored_version() );
	}

	/**
	 * A failing migration stops the run at the last completed step and frees the lock.
	 */
	public function test_failure_leaves_consistent_state(): void {
		$upgrader = new Upgrader(
			$this->logger(),
			array(
				1 => Migration_001::class,
				2 => FailingMigration::class,
			)
		);

		$this->assertFalse( $this->install( $upgrader ) );
		$this->assertSame( 1, Upgrader::stored_version() );
		$this->assertCount( 8, $this->snapshot() );
		$this->assertFalse( ( new Lock() )->is_locked( Upgrader::LOCK_NAME ) );
		$this->assertStringContainsString( 'Injected failure.', implode( "\n", $this->log ) );
	}
}
