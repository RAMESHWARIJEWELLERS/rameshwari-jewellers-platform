<?php
/**
 * Migration 001: the eight custom tables.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Data\Migrations;

use Rameshwari\Core\Data\Schema;

/**
 * Creates the eight tables through dbDelta, which only adds what is
 * missing, so a second run is a no-op. Additive only: it never drops or
 * alters data. Verifies every table exists before reporting success.
 */
final class Migration_001 implements Migration {

	/**
	 * Creates or completes the eight tables.
	 *
	 * @return void
	 * @throws \RuntimeException When a table is missing afterwards.
	 */
	public function up(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( Schema::statements() );

		foreach ( Schema::table_names() as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check during migration.
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );

			if ( $table !== $found ) {
				throw new \RuntimeException( esc_html( 'Table missing after migration: ' . $table ) );
			}
		}
	}
}
