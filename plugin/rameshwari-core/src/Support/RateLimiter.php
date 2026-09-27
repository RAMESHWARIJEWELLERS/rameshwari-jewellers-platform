<?php
/**
 * Fixed-window rate limiter.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Support;

/**
 * Counts requests per bucket in fixed time windows, stored through Cache so
 * it works with or without a persistent object cache.
 *
 * A bucket is a hashed identifier, for example the output of Hash::ip() or
 * a credential id. Raw addresses are rejected.
 */
final class RateLimiter {

	private const MAX_WINDOW = 86400;

	/**
	 * Counter storage.
	 *
	 * @var Cache
	 */
	private Cache $cache;

	/**
	 * Returns the current Unix time.
	 *
	 * @var callable(): int
	 */
	private $clock;

	/**
	 * Sets up the limiter.
	 *
	 * @param Cache|null    $cache Counter storage. Defaults to the rate_limit group.
	 * @param callable|null $clock Returns the current Unix time. Defaults to time().
	 */
	public function __construct( ?Cache $cache = null, ?callable $clock = null ) {
		$this->cache = $cache ?? new Cache( 'rate_limit' );
		$this->clock = $clock ?? static fn(): int => time();
	}

	/**
	 * Records one request and reports whether it is within the limit.
	 *
	 * Requests past the limit are still counted, so sustained abuse stays
	 * blocked until the window ends.
	 *
	 * @param string $bucket Hashed identifier.
	 * @param int    $limit  Requests allowed per window, at least 1.
	 * @param int    $window Window length in seconds, 1 to 86400.
	 * @return bool True while the count is within the limit.
	 */
	public function hit( string $bucket, int $limit, int $window ): bool {
		$this->validate( $bucket, $limit, $window );

		$key   = $this->window_key( $bucket, $window );
		$count = $this->stored( $key ) + 1;

		$this->cache->set( $key, $count, $window );

		return $count <= $limit;
	}

	/**
	 * Requests counted in the current window.
	 *
	 * @param string $bucket Hashed identifier.
	 * @param int    $window Window length in seconds.
	 * @return int
	 */
	public function count( string $bucket, int $window ): int {
		$this->validate( $bucket, 1, $window );

		return $this->stored( $this->window_key( $bucket, $window ) );
	}

	/**
	 * Requests still allowed in the current window.
	 *
	 * @param string $bucket Hashed identifier.
	 * @param int    $limit  Requests allowed per window.
	 * @param int    $window Window length in seconds.
	 * @return int
	 */
	public function remaining( string $bucket, int $limit, int $window ): int {
		$this->validate( $bucket, $limit, $window );

		return max( 0, $limit - $this->stored( $this->window_key( $bucket, $window ) ) );
	}

	/**
	 * Whether the limit has been reached in the current window.
	 *
	 * @param string $bucket Hashed identifier.
	 * @param int    $limit  Requests allowed per window.
	 * @param int    $window Window length in seconds.
	 * @return bool
	 */
	public function is_limited( string $bucket, int $limit, int $window ): bool {
		$this->validate( $bucket, $limit, $window );

		return $this->stored( $this->window_key( $bucket, $window ) ) >= $limit;
	}

	/**
	 * Clears the current window's count for a bucket.
	 *
	 * @param string $bucket Hashed identifier.
	 * @param int    $window Window length in seconds.
	 * @return void
	 */
	public function reset( string $bucket, int $window ): void {
		$this->validate( $bucket, 1, $window );

		$this->cache->delete( $this->window_key( $bucket, $window ) );
	}

	/**
	 * Cache key of the bucket's current window.
	 *
	 * @param string $bucket Hashed identifier.
	 * @param int    $window Window length in seconds.
	 * @return string
	 */
	private function window_key( string $bucket, int $window ): string {
		return $bucket . '|' . $window . '|' . intdiv( (int) ( $this->clock )(), $window );
	}

	/**
	 * Stored count, 0 on a miss.
	 *
	 * @param string $key Cache key.
	 * @return int
	 */
	private function stored( string $key ): int {
		$value = $this->cache->get( $key, 0 );

		return is_int( $value ) ? $value : 0;
	}

	/**
	 * Rejects invalid arguments and anything that carries a raw address.
	 *
	 * @param string $bucket Hashed identifier.
	 * @param int    $limit  Requests allowed per window.
	 * @param int    $window Window length in seconds.
	 * @return void
	 * @throws \InvalidArgumentException For invalid input.
	 */
	private function validate( string $bucket, int $limit, int $window ): void {
		if ( '' === $bucket || strlen( $bucket ) > 191 ) {
			throw new \InvalidArgumentException( 'Rate-limit bucket must be 1 to 191 bytes.' );
		}

		if ( $this->contains_address( $bucket ) ) {
			throw new \InvalidArgumentException( 'Rate-limit buckets take a hashed identifier, never a raw address.' );
		}

		if ( $limit < 1 ) {
			throw new \InvalidArgumentException( 'Rate limit must be at least 1.' );
		}

		if ( $window < 1 || $window > self::MAX_WINDOW ) {
			throw new \InvalidArgumentException( 'Rate-limit window must be between 1 and 86400 seconds.' );
		}
	}

	/**
	 * Whether the bucket is, or contains, an IPv4 or IPv6 address.
	 *
	 * @param string $bucket Bucket.
	 * @return bool
	 */
	private function contains_address( string $bucket ): bool {
		if ( false !== filter_var( $bucket, FILTER_VALIDATE_IP ) ) {
			return true;
		}

		if ( 1 === preg_match( '/\d{1,3}(?:\.\d{1,3}){3}/', $bucket ) ) {
			return true;
		}

		preg_match_all( '/[0-9A-Fa-f:]{3,}/', $bucket, $matches );

		foreach ( $matches[0] as $candidate ) {
			if ( substr_count( $candidate, ':' ) < 2 ) {
				continue;
			}

			if ( false !== filter_var( $candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) || false !== filter_var( ltrim( $candidate, ':' ), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
				return true;
			}
		}

		return false;
	}
}
