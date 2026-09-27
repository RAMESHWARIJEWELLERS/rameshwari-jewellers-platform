<?php
/**
 * Cooperative locks.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Support;

/**
 * Named locks for work that must not overlap: cron jobs, imports,
 * migrations and queue workers. Stored in the persistent object cache when
 * one exists, otherwise as a non-autoloaded option. No custom table.
 *
 * Every lock carries an owner token and an expiry. Only the owner can
 * release it; once expired, anyone may reclaim it, so a crashed process
 * can never leave a lock held forever.
 *
 * The fallback row is transient runtime state, like the transient fallback
 * Cache uses: it is not a registered settings group, holds no business
 * data, is never autoloaded, and is deleted on release. add_option() is used
 * rather than a transient because it refuses to overwrite an existing row.
 */
final class Lock {

	private const PREFIX = 'rj_lock_';

	private const OBJECT_GROUP = 'rameshwari';

	private const MAX_TTL = 86400;

	/**
	 * Whether the persistent object cache is used.
	 *
	 * @var bool
	 */
	private bool $persistent;

	/**
	 * Returns the current Unix time.
	 *
	 * @var callable(): int
	 */
	private $clock;

	/**
	 * Sets up the lock store.
	 *
	 * @param bool|null     $persistent Force a mode. Null detects a persistent object cache.
	 * @param callable|null $clock      Returns the current Unix time. Defaults to time().
	 */
	public function __construct( ?bool $persistent = null, ?callable $clock = null ) {
		$this->persistent = $persistent ?? wp_using_ext_object_cache();
		$this->clock      = $clock ?? static fn(): int => time();
	}

	/**
	 * Takes the named lock.
	 *
	 * @param string $name Lowercase letters, digits, underscores and hyphens, up to 64 characters.
	 * @param int    $ttl  Seconds before the lock counts as abandoned, 1 to 86400.
	 * @return string|null Owner token needed to release, or null while another owner holds it.
	 * @throws \InvalidArgumentException For an invalid name or TTL.
	 */
	public function acquire( string $name, int $ttl ): ?string {
		$this->validate( $name );

		if ( $ttl < 1 || $ttl > self::MAX_TTL ) {
			throw new \InvalidArgumentException( 'Lock TTL must be between 1 and 86400 seconds.' );
		}

		$token  = wp_generate_password( 32, false, false );
		$record = array(
			'token'   => $token,
			'expires' => $this->now() + $ttl,
		);

		if ( $this->add( $name, $record, $ttl ) ) {
			return $token;
		}

		$current = $this->read( $name );

		if ( null !== $current && $current['expires'] > $this->now() ) {
			return null;
		}

		// Expired, abandoned or unreadable: remove it and try once more.
		$this->remove( $name );

		return $this->add( $name, $record, $ttl ) ? $token : null;
	}

	/**
	 * Releases the lock if the token matches its owner.
	 *
	 * @param string $name  Lock name.
	 * @param string $token Token returned by acquire().
	 * @return bool Whether the lock was released.
	 */
	public function release( string $name, string $token ): bool {
		$this->validate( $name );

		$current = $this->read( $name );

		if ( null === $current || ! hash_equals( $current['token'], $token ) ) {
			return false;
		}

		return $this->remove( $name );
	}

	/**
	 * Whether the lock is currently held and unexpired.
	 *
	 * @param string $name Lock name.
	 * @return bool
	 */
	public function is_locked( string $name ): bool {
		$this->validate( $name );

		$current = $this->read( $name );

		return null !== $current && $current['expires'] > $this->now();
	}

	/**
	 * Adds the record only if no record exists.
	 *
	 * @phpstan-impure
	 * @param string                             $name   Lock name.
	 * @param array{token: string, expires: int} $record Lock record.
	 * @param int                                $ttl    Seconds.
	 * @return bool
	 */
	private function add( string $name, array $record, int $ttl ): bool {
		if ( $this->persistent ) {
			// The logical expiry lives in the record; the backend expiry is only a safety net.
			return wp_cache_add( self::PREFIX . $name, $record, self::OBJECT_GROUP, $ttl + 60 );
		}

		return add_option( self::PREFIX . $name, $record, '', false );
	}

	/**
	 * Reads a well-formed record, or null.
	 *
	 * @param string $name Lock name.
	 * @return array{token: string, expires: int}|null
	 */
	private function read( string $name ): ?array {
		$value = $this->persistent
			? wp_cache_get( self::PREFIX . $name, self::OBJECT_GROUP )
			: get_option( self::PREFIX . $name );

		if ( ! is_array( $value ) || ! isset( $value['token'], $value['expires'] ) ) {
			return null;
		}

		if ( ! is_string( $value['token'] ) || ! is_int( $value['expires'] ) ) {
			return null;
		}

		return array(
			'token'   => $value['token'],
			'expires' => $value['expires'],
		);
	}

	/**
	 * Deletes the record.
	 *
	 * @param string $name Lock name.
	 * @return bool
	 */
	private function remove( string $name ): bool {
		return $this->persistent ? wp_cache_delete( self::PREFIX . $name, self::OBJECT_GROUP ) : delete_option( self::PREFIX . $name );
	}

	/**
	 * Rejects names that are not safe storage keys.
	 *
	 * @param string $name Lock name.
	 * @return void
	 * @throws \InvalidArgumentException For an invalid name.
	 */
	private function validate( string $name ): void {
		if ( 1 !== preg_match( '/^[a-z0-9_-]{1,64}$/', $name ) ) {
			throw new \InvalidArgumentException( esc_html( 'Invalid lock name: ' . $name ) );
		}
	}

	/**
	 * Current Unix time from the clock.
	 *
	 * @return int
	 */
	private function now(): int {
		return (int) ( $this->clock )();
	}
}
