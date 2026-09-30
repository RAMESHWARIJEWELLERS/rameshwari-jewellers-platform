<?php
/**
 * Roles and capabilities.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Data;

/**
 * The role-to-capability map of Platform Architecture §1.2, applied at
 * activation and re-asserted on every upgrade check.
 *
 * Applying is a repair, not only an add: a missing capability is restored
 * and a platform capability a role must not hold is removed, so a role
 * edited by another plugin corrects itself. Only the eleven platform
 * capabilities are managed on built-in roles; nothing else is touched.
 */
final class Capabilities {

	/**
	 * Every platform capability.
	 */
	public const ALL = array(
		'rj_manage_catalogue',
		'rj_manage_categories',
		'rj_manage_reels',
		'rj_manage_showrooms',
		'rj_read_leads',
		'rj_manage_leads',
		'rj_read_customers',
		'rj_export_data',
		'rj_import_data',
		'rj_manage_settings',
		'rj_view_activity_log',
	);

	/**
	 * The platform's own roles, with their display names.
	 */
	public const ROLES = array(
		'rj_catalogue_manager' => 'Catalogue Manager',
		'rj_enquiry_agent'     => 'Enquiry Agent',
		'rj_customer'          => 'Customer',
	);

	/**
	 * WordPress core capabilities each platform role needs.
	 *
	 * Every role can log in (read). Catalogue staff also manage media, as
	 * their §1.2 boundary states. The customer holds read and nothing else.
	 */
	private const CORE = array(
		'rj_catalogue_manager' => array( 'read', 'upload_files' ),
		'rj_enquiry_agent'     => array( 'read' ),
		'rj_customer'          => array( 'read' ),
	);

	/**
	 * The approved matrix: platform capabilities per role.
	 *
	 * Filterable through rj/capabilities, the hook Platform Architecture
	 * defines for the map at activation.
	 *
	 * @return array<string, array<int, string>>
	 */
	public static function map(): array {
		$map = array(
			'administrator'        => self::ALL,
			'rj_catalogue_manager' => array( 'rj_manage_catalogue', 'rj_manage_categories', 'rj_manage_reels' ),
			'rj_enquiry_agent'     => array( 'rj_read_leads', 'rj_manage_leads' ),
			'rj_customer'          => array(),
		);

		$filtered = apply_filters( 'rj/capabilities', $map ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.ValidHookName.UseUnderscores -- rj/capabilities is the approved, documented public hook; renaming it would break compatibility.

		if ( ! is_array( $filtered ) ) {
			return $map;
		}

		$clean = array();

		foreach ( $filtered as $role => $caps ) {
			if ( is_string( $role ) && is_array( $caps ) ) {
				$clean[ $role ] = array_values( array_filter( $caps, 'is_string' ) );
			}
		}

		return $clean;
	}

	/**
	 * Creates the platform roles if missing and brings every mapped role in
	 * line with the matrix. Writes only where a role differs.
	 *
	 * @return void
	 */
	public static function apply(): void {
		foreach ( self::ROLES as $slug => $label ) {
			if ( null === get_role( $slug ) ) {
				add_role( $slug, $label, array_fill_keys( self::CORE[ $slug ], true ) );
			}
		}

		foreach ( self::map() as $slug => $wanted ) {
			$role = get_role( $slug );

			if ( null === $role ) {
				continue;
			}

			foreach ( self::CORE[ $slug ] ?? array() as $cap ) {
				if ( ! $role->has_cap( $cap ) ) {
					$role->add_cap( $cap );
				}
			}

			foreach ( self::ALL as $cap ) {
				$should = in_array( $cap, $wanted, true );

				if ( $should && ! $role->has_cap( $cap ) ) {
					$role->add_cap( $cap );
				} elseif ( ! $should && $role->has_cap( $cap ) ) {
					$role->remove_cap( $cap );
				}
			}
		}
	}

	/**
	 * Removes the platform roles and strips platform capabilities from the
	 * administrator. Used only by an opted-in uninstall.
	 *
	 * @return void
	 */
	public static function remove(): void {
		foreach ( array_keys( self::ROLES ) as $slug ) {
			remove_role( $slug );
		}

		$admin = get_role( 'administrator' );

		if ( null !== $admin ) {
			foreach ( self::ALL as $cap ) {
				$admin->remove_cap( $cap );
			}
		}
	}
}
