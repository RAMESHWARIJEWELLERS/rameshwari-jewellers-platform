<?php
/**
 * Capability matrix tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Integration;

use Rameshwari\Core\Data\Capabilities;

require_once __DIR__ . '/DatabaseTestCase.php';

/**
 * The matrix of Platform Architecture §1.2 for all five actor types.
 */
final class CapabilitiesTest extends DatabaseTestCase {

	/**
	 * Applies the map before each test.
	 */
	public function set_up(): void {
		parent::set_up();
		Capabilities::apply();
	}

	/**
	 * Platform capabilities a role holds.
	 *
	 * @param string $slug Role.
	 * @return array<int, string>
	 */
	private function platform_caps( string $slug ): array {
		$role = get_role( $slug );

		$this->assertNotNull( $role, $slug );

		return array_values( array_filter( Capabilities::ALL, static fn( string $cap ): bool => $role->has_cap( $cap ) ) );
	}

	/**
	 * The owner holds all eleven.
	 */
	public function test_administrator_has_all(): void {
		$this->assertSame( Capabilities::ALL, $this->platform_caps( 'administrator' ) );
	}

	/**
	 * Catalogue staff manage the catalogue, categories, reels and media.
	 */
	public function test_catalogue_manager(): void {
		$this->assertSame( array( 'rj_manage_catalogue', 'rj_manage_categories', 'rj_manage_reels' ), $this->platform_caps( 'rj_catalogue_manager' ) );
		$this->assertTrue( get_role( 'rj_catalogue_manager' )->has_cap( 'upload_files' ) );
	}

	/**
	 * The enquiry desk reads and manages leads only.
	 */
	public function test_enquiry_agent(): void {
		$this->assertSame( array( 'rj_read_leads', 'rj_manage_leads' ), $this->platform_caps( 'rj_enquiry_agent' ) );
	}

	/**
	 * The customer holds read and nothing else.
	 */
	public function test_customer_read_only(): void {
		$this->assertSame( array( 'read' => true ), get_role( 'rj_customer' )->capabilities );
	}

	/**
	 * A guest holds no platform capability.
	 */
	public function test_guest_has_none(): void {
		$guest = new \WP_User( 0 );

		foreach ( Capabilities::ALL as $cap ) {
			$this->assertFalse( $guest->has_cap( $cap ), $cap );
		}
	}

	/**
	 * A capability removed by another plugin is restored.
	 */
	public function test_missing_capability_repaired(): void {
		get_role( 'administrator' )->remove_cap( 'rj_manage_settings' );
		get_role( 'rj_customer' )->remove_cap( 'read' );

		Capabilities::apply();

		$this->assertTrue( get_role( 'administrator' )->has_cap( 'rj_manage_settings' ) );
		$this->assertTrue( get_role( 'rj_customer' )->has_cap( 'read' ) );
	}

	/**
	 * A platform capability a role must not hold is removed.
	 */
	public function test_stray_capability_removed(): void {
		get_role( 'rj_enquiry_agent' )->add_cap( 'rj_export_data' );
		get_role( 'rj_customer' )->add_cap( 'rj_read_leads' );

		Capabilities::apply();

		$this->assertFalse( get_role( 'rj_enquiry_agent' )->has_cap( 'rj_export_data' ) );
		$this->assertFalse( get_role( 'rj_customer' )->has_cap( 'rj_read_leads' ) );
	}

	/**
	 * No role gains a platform capability outside the approved eleven.
	 */
	public function test_only_approved_capabilities(): void {
		foreach ( array( 'administrator', 'rj_catalogue_manager', 'rj_enquiry_agent', 'rj_customer' ) as $slug ) {
			foreach ( array_keys( get_role( $slug )->capabilities ) as $cap ) {
				if ( str_starts_with( $cap, 'rj_' ) ) {
					$this->assertContains( $cap, Capabilities::ALL, $slug . ': ' . $cap );
				}
			}
		}
	}
}
