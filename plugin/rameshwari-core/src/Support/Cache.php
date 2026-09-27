<?php
/**
 * Versioned-key cache.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Support;

/**
 * Caches values in the persistent object cache when one exists and in
 * transients when it does not. Both modes share one interface.
 *
 * Every key carries its group's version, so bump() invalidates the whole
 * group in one write. A lost version marker is replaced by a new unique
 * value, never by an old one, so a cache failure can only cause a miss:
 * slower, never wrong. Nothing here clears the cache globally.
 */
final class Cache {

	private const OBJECT_GROUP = 'rameshwari';

	/**
	 * Lifetime of a group's version marker: 30 days.
	 */
	private const VERSION_TTL = 2592000;

	/**
	 * Cache group.
	 *
	 * @var string
	 */
	private string $group;

	/**
	 * Whether the persistent object cache is used.
	 *
	 * @var bool
	 */
	private bool $persistent;

	/**
	 * Sets up a cache group.
	 *
	 * @param string    $group      Lowercase letters, digits and underscores, up to 40 characters.
	 * @param bool|null $persistent Force a mode. Null detects a persistent object cache.
	 * @throws \InvalidArgumentException For an invalid group name.
	 */
	public function __construct( string $group, ?bool $persistent = null ) {
		if ( 1 !== preg_match( '/^[a-z0-9_]{1,40}$/', $group ) ) {
			throw new \InvalidArgumentException( esc_html( 'Invalid cache group: ' . $group ) );
		}

		$this->group      = $group;
		$this->persistent = $persistent ?? wp_using_ext_object_cache();
	}

	/**
	 * Whether this cache uses the persistent object cache.
	 *
	 * @return bool
	 */
	public function is_persistent(): bool {
		return $this->persistent;
	}

	/**
	 * Reads a value.
	 *
	 * @param string $key      Key within the group.
	 * @param mixed  $fallback Returned on a miss.
	 * @return mixed
	 */
	public function get( string $key, mixed $fallback = null ): mixed {
		$entry = $this->read( $this->storage_key( $key ) );

		return is_array( $entry ) && array_key_exists( 'value', $entry ) ? $entry['value'] : $fallback;
	}

	/**
	 * Whether a value is stored, including a stored false or null.
	 *
	 * @param string $key Key within the group.
	 * @return bool
	 */
	public function has( string $key ): bool {
		$entry = $this->read( $this->storage_key( $key ) );

		return is_array( $entry ) && array_key_exists( 'value', $entry );
	}

	/**
	 * Stores a value.
	 *
	 * @param string $key   Key within the group.
	 * @param mixed  $value Any serialisable value.
	 * @param int    $ttl   Seconds, at least 1. Nothing is stored forever.
	 * @return bool Whether the backend accepted the write.
	 * @throws \InvalidArgumentException For a TTL below one second.
	 */
	public function set( string $key, mixed $value, int $ttl ): bool {
		if ( $ttl < 1 ) {
			throw new \InvalidArgumentException( 'Cache TTL must be at least one second.' );
		}

		return $this->write( $this->storage_key( $key ), array( 'value' => $value ), $ttl );
	}

	/**
	 * Removes a value.
	 *
	 * @param string $key Key within the group.
	 * @return bool
	 */
	public function delete( string $key ): bool {
		$storage_key = $this->storage_key( $key );

		return $this->persistent ? wp_cache_delete( $storage_key, self::OBJECT_GROUP ) : delete_transient( $storage_key );
	}

	/**
	 * Returns the cached value, computing and storing it on a miss.
	 *
	 * @param string   $key     Key within the group.
	 * @param int      $ttl     Seconds, at least 1.
	 * @param callable $compute Produces the value on a miss.
	 * @return mixed
	 */
	public function remember( string $key, int $ttl, callable $compute ): mixed {
		$entry = $this->read( $this->storage_key( $key ) );

		if ( is_array( $entry ) && array_key_exists( 'value', $entry ) ) {
			return $entry['value'];
		}

		$value = $compute();
		$this->set( $key, $value, $ttl );

		return $value;
	}

	/**
	 * Invalidates every key in the group with one write.
	 *
	 * @return void
	 */
	public function bump(): void {
		$this->write( $this->version_key(), $this->new_version(), self::VERSION_TTL );
	}

	/**
	 * The backend key for a group key, including the group's current version.
	 *
	 * @param string $key Key within the group.
	 * @return string
	 */
	public function storage_key( string $key ): string {
		return 'rj_c_' . md5( $this->group . '|' . $this->version() . '|' . $key );
	}

	/**
	 * The group's current version, creating a new one if the marker is missing.
	 *
	 * @return string
	 */
	private function version(): string {
		$version = $this->read( $this->version_key() );

		if ( is_string( $version ) && '' !== $version ) {
			return $version;
		}

		$version = $this->new_version();
		$this->write( $this->version_key(), $version, self::VERSION_TTL );

		return $version;
	}

	/**
	 * Backend key of the group's version marker.
	 *
	 * @return string
	 */
	private function version_key(): string {
		return 'rj_cv_' . $this->group;
	}

	/**
	 * A version value that has never been used before.
	 *
	 * @return string
	 */
	private function new_version(): string {
		return str_replace( '.', '', uniqid( '', true ) );
	}

	/**
	 * Reads a raw backend entry. False means a miss.
	 *
	 * @param string $storage_key Backend key.
	 * @return mixed
	 */
	private function read( string $storage_key ): mixed {
		if ( $this->persistent ) {
			$found = false;
			$value = wp_cache_get( $storage_key, self::OBJECT_GROUP, false, $found );

			return $found ? $value : false;
		}

		return get_transient( $storage_key );
	}

	/**
	 * Writes a raw backend entry.
	 *
	 * @param string $storage_key Backend key.
	 * @param mixed  $value       Value.
	 * @param int    $ttl         Seconds.
	 * @return bool
	 */
	private function write( string $storage_key, mixed $value, int $ttl ): bool {
		if ( $this->persistent ) {
			return wp_cache_set( $storage_key, $value, self::OBJECT_GROUP, $ttl );
		}

		return set_transient( $storage_key, $value, $ttl );
	}
}
