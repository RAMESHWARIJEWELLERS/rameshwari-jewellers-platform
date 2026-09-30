<?php
/**
 * Shared install routine.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core;

use Rameshwari\Core\Support\Logger;

/**
 * The one routine activation and the upgrade check both call: bring the
 * schema current, re-assert capabilities, and stamp the install time once.
 * It holds no schema knowledge of its own; Schema and the migrations do.
 */
final class Installer {

	public const ADMIN_INIT = 'admin_init';

	public const ACTIVATION = 'activation';

	public const CLI = 'cli';

	/**
	 * Brings the installation up to date through one entry point.
	 *
	 * The name only selects which entry point's check applies; the check
	 * itself looks at the running hook or environment, so naming an entry
	 * point from anywhere else is refused.
	 *
	 * @param string $entry One of the entry-point constants.
	 * @return bool True when the schema is current afterwards.
	 */
	public static function install( string $entry ): bool {
		$upgrader = new Upgrader( self::logger() );

		$ok = match ( $entry ) {
			self::ADMIN_INIT => $upgrader->admin_init(),
			self::ACTIVATION => $upgrader->activation(),
			self::CLI        => $upgrader->cli(),
			default          => false,
		};

		if ( $ok ) {
			self::stamp();
		}

		return $ok;
	}

	/**
	 * The shared logger when the plugin has booted, otherwise a new one.
	 * Activation runs before plugins_loaded, so both cases occur.
	 *
	 * @return Logger
	 */
	public static function logger(): Logger {
		$plugin = Plugin::instance();

		if ( null !== $plugin ) {
			$logger = $plugin->container()->get( Logger::class );

			if ( $logger instanceof Logger ) {
				return $logger;
			}
		}

		return new Logger( null, defined( 'WP_DEBUG' ) && WP_DEBUG );
	}

	/**
	 * Records the first successful install time.
	 *
	 * @return void
	 */
	private static function stamp(): void {
		$state = get_option( Upgrader::STATE_OPTION, array() );
		$state = is_array( $state ) ? $state : array();
		if ( ! isset( $state['install_timestamp'] ) && isset( $state['installed_at'] ) ) {
			$state['install_timestamp'] = $state['installed_at'];
		}
		if ( ! isset( $state['completed_migration_ids'] ) && isset( $state['migrations'] ) && is_array( $state['migrations'] ) ) {
			$state['completed_migration_ids'] = $state['migrations'];
		}
		$state = array_merge(
			array(
				'install_timestamp'       => null,
				'completed_migration_ids' => array(),
				'last_rebuild_times'      => array(),
				'seeded'                  => false,
			),
			$state
		);
		unset( $state['installed_at'], $state['migrations'] );

		if ( ! isset( $state['install_timestamp'] ) ) {
			$state['install_timestamp'] = gmdate( 'Y-m-d H:i:s' );
			update_option( Upgrader::STATE_OPTION, $state, false );
		}
	}
}
