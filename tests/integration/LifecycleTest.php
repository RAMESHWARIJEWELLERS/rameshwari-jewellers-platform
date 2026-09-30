<?php
/**
 * Lifecycle tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Integration;

use Rameshwari\Core\Data\Schema;
use Rameshwari\Core\Deactivator;
use Rameshwari\Core\Uninstaller;
use Rameshwari\Core\Upgrader;

require_once __DIR__ . '/DatabaseTestCase.php';

/**
 * Activation, upgrade, deactivation and uninstall.
 */
final class LifecycleTest extends DatabaseTestCase {

	/**
	 * The real activation hook creates the tables, stores the version and applies the roles.
	 */
	public function test_activation_installs_everything(): void {
		$this->activate();

		$this->assertCount( 8, $this->snapshot() );
		$this->assertSame( 1, Upgrader::stored_version() );
		$this->assertNotNull( get_role( 'rj_customer' ) );
		$this->assertTrue( get_role( 'administrator' )->has_cap( 'rj_manage_settings' ) );
		$this->assertSame(
			array( 'install_timestamp', 'completed_migration_ids', 'last_rebuild_times', 'seeded' ),
			array_keys( (array) get_option( Upgrader::STATE_OPTION ) )
		);
	}

	/**
	 * Activating again changes nothing.
	 */
	public function test_reactivation_idempotent(): void {
		$this->activate();
		$schema = $this->snapshot();
		$state  = get_option( Upgrader::STATE_OPTION );

		$this->activate();

		$this->assertSame( $schema, $this->snapshot() );
		$this->assertSame( $state, get_option( Upgrader::STATE_OPTION ) );
	}

	/**
	 * The real admin_init callback re-asserts the capability map.
	 */
	public function test_upgrade_reasserts_capabilities(): void {
		$this->activate();
		get_role( 'rj_enquiry_agent' )->remove_cap( 'rj_read_leads' );

		$this->admin_init();

		$this->assertTrue( get_role( 'rj_enquiry_agent' )->has_cap( 'rj_read_leads' ) );
	}

	/**
	 * An upgrade check on a current install migrates nothing.
	 */
	public function test_upgrade_noop_when_current(): void {
		$this->activate();
		$schema = $this->snapshot();

		$this->admin_init();

		$this->assertSame( $schema, $this->snapshot() );
		$this->assertSame( 1, Upgrader::stored_version() );
	}

	/**
	 * Deactivation keeps tables, rows, version and roles.
	 */
	public function test_deactivation_keeps_data(): void {
		global $wpdb;

		$this->activate();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Integration test writes a fixture row directly.
		$wpdb->insert(
			Schema::table( 'rj_leads' ),
			array(
				'created_at' => '2026-09-27 10:00:00',
				'source'     => 'product',
			)
		);

		Deactivator::deactivate();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test inspection.
		$this->assertSame( '1', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Schema::table( 'rj_leads' ) ) ) );
		$this->assertSame( 1, Upgrader::stored_version() );
		$this->assertNotNull( get_role( 'rj_customer' ) );
	}

	/**
	 * Uninstall without the owner's opt-in removes nothing.
	 */
	public function test_uninstall_default_keeps_everything(): void {
		$this->activate();
		$schema = $this->snapshot();

		Uninstaller::uninstall( false );

		$this->assertSame( $schema, $this->snapshot() );
		$this->assertSame( 1, Upgrader::stored_version() );
		$this->assertNotNull( get_role( 'rj_customer' ) );
	}

	/**
	 * An opted-in uninstall removes the tables, options, roles and capabilities.
	 */
	public function test_uninstall_opted_in_removes_plugin_data(): void {
		$this->activate();

		Uninstaller::uninstall( true );

		$this->assertSame( array(), $this->snapshot() );
		$this->assertFalse( get_option( Upgrader::VERSION_OPTION ) );
		$this->assertNull( get_role( 'rj_customer' ) );
		$this->assertFalse( get_role( 'administrator' )->has_cap( 'rj_manage_settings' ) );
	}
}
