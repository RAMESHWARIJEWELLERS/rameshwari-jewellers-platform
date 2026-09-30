<?php
/**
 * Uninstall.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core;

use Rameshwari\Core\Data\Capabilities;
use Rameshwari\Core\Data\Schema;

/**
 * Removes platform data only when the owner has opted in. By default it
 * removes nothing: deleting the plugin leaves every table, row, option,
 * role and capability in place.
 */
final class Uninstaller {

	/**
	 * Uninstall routine.
	 *
	 * @param bool $opted_in Whether the owner has chosen to delete platform data.
	 * @return void
	 */
	public static function uninstall( bool $opted_in ): void {
		if ( ! $opted_in ) {
			return;
		}

		global $wpdb;

		foreach ( Schema::table_names() as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Opted-in uninstall.
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
		}

		delete_option( Upgrader::VERSION_OPTION );
		delete_option( Upgrader::STATE_OPTION );

		Capabilities::remove();
	}
}
