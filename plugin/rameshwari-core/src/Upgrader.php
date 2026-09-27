<?php
/**
 * Migration router.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core;

use Rameshwari\Core\Data\Capabilities;
use Rameshwari\Core\Data\Migrations\Migration;
use Rameshwari\Core\Data\Migrations\Migration_001;
use Rameshwari\Core\Support\Lock;
use Rameshwari\Core\Support\Logger;

/**
 * Runs pending migrations in number order behind a cooperative lock, then
 * re-asserts the capability map.
 *
 * Three public entry points, each checking where it was actually called
 * from rather than trusting an argument:
 *
 * - admin_init(): only while WordPress is running the admin_init hook on
 *   an admin page, never for admin-ajax or cron;
 * - activation(): only while WordPress is running this plugin's
 *   activation hook;
 * - cli(): only under WP-CLI.
 *
 * Called any other way, including from a front-end request, they refuse
 * and change nothing. The migration logic itself is private.
 *
 * After each successful migration the stored version moves to that
 * migration's number, so a failure leaves the database at the last step
 * that completed. There is no data rollback: recovery from a destructive
 * failure is a verified restore.
 */
final class Upgrader {

	public const VERSION_OPTION = 'rj_db_version';

	public const STATE_OPTION = 'rj_install_state';

	public const LOCK_NAME = 'migrations';

	private const LOCK_TTL = 300;

	/**
	 * Migrations keyed by number.
	 *
	 * @var array<int, class-string<Migration>>
	 */
	private array $migrations;

	/**
	 * Cooperative lock.
	 *
	 * @var Lock
	 */
	private Lock $lock;

	/**
	 * Sets up the router.
	 *
	 * @param Logger                                   $logger     Diagnostics.
	 * @param array<int, class-string<Migration>>|null $migrations Migrations keyed by number. Null uses the plugin's own.
	 * @param Lock|null                                $lock       Lock store. Null detects the backend.
	 */
	public function __construct( private Logger $logger, ?array $migrations = null, ?Lock $lock = null ) {
		$this->migrations = $migrations ?? array( 1 => Migration_001::class );
		$this->lock       = $lock ?? new Lock();
	}

	/**
	 * The admin_init hook callback.
	 *
	 * @return void
	 */
	public static function on_admin_init(): void {
		Installer::install( Installer::ADMIN_INIT );
	}

	/**
	 * Migrates from the admin_init hook on an admin page.
	 *
	 * @return bool True when the schema is current afterwards.
	 */
	public function admin_init(): bool {
		$allowed = doing_action( 'admin_init' ) && is_admin() && ! wp_doing_ajax() && ! wp_doing_cron();

		return $this->guarded( $allowed, 'admin_init' );
	}

	/**
	 * Migrates from this plugin's activation hook.
	 *
	 * @return bool True when the schema is current afterwards.
	 */
	public function activation(): bool {
		return $this->guarded( doing_action( 'activate_' . plugin_basename( RJ_FILE ) ), 'activation' );
	}

	/**
	 * Migrates from WP-CLI.
	 *
	 * @return bool True when the schema is current afterwards.
	 */
	public function cli(): bool {
		return $this->guarded( defined( 'WP_CLI' ) && WP_CLI, 'cli' );
	}

	/**
	 * The stored schema version, 0 before the first migration.
	 *
	 * @return int
	 */
	public static function stored_version(): int {
		return (int) get_option( self::VERSION_OPTION, 0 );
	}

	/**
	 * Runs the upgrade if the entry point's check passed.
	 *
	 * @param bool   $allowed Whether the caller is the entry point it claims to be.
	 * @param string $entry   Entry point name, for the log.
	 * @return bool
	 */
	private function guarded( bool $allowed, string $entry ): bool {
		if ( ! $allowed ) {
			$this->logger->warning( 'Migration refused: not called from its entry point.', array( 'entry' => $entry ) );
			return false;
		}

		$current = self::stored_version();
		$pending = array_filter( $this->migrations, static fn( int $number ): bool => $number > $current, ARRAY_FILTER_USE_KEY );
		ksort( $pending );

		$ok = array() === $pending || $this->migrate( $pending );

		Capabilities::apply();

		return $ok;
	}

	/**
	 * Runs the pending migrations behind the lock.
	 *
	 * @param array<int, class-string<Migration>> $pending Pending migrations in order.
	 * @return bool
	 */
	private function migrate( array $pending ): bool {
		$token = $this->lock->acquire( self::LOCK_NAME, self::LOCK_TTL );

		if ( null === $token ) {
			$this->logger->warning( 'Migration skipped: another run holds the lock.' );
			return false;
		}

		try {
			foreach ( $pending as $number => $class ) {
				( new $class() )->up();
				update_option( self::VERSION_OPTION, $number, true );
				$this->record( $number );
			}

			return true;
		} catch ( \Throwable $e ) {
			$this->logger->error(
				'Migration failed; schema left at the last completed step.',
				array(
					'version' => self::stored_version(),
					'reason'  => $e->getMessage(),
				)
			);

			return false;
		} finally {
			$this->lock->release( self::LOCK_NAME, $token );
		}
	}

	/**
	 * Adds a completed migration to the install state.
	 *
	 * @param int $number Migration number.
	 * @return void
	 */
	private function record( int $number ): void {
		$state = get_option( self::STATE_OPTION, array() );
		$state = is_array( $state ) ? $state : array();
		$done  = isset( $state['migrations'] ) && is_array( $state['migrations'] ) ? $state['migrations'] : array();

		if ( ! in_array( $number, $done, true ) ) {
			$done[] = $number;
		}

		$state['migrations'] = $done;

		update_option( self::STATE_OPTION, $state, false );
	}
}
