<?php
/**
 * Rate limiter tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Unit;

use Rameshwari\Core\Support\Cache;
use Rameshwari\Core\Support\RateLimiter;
use WP_UnitTestCase;

/**
 * Fixed-window counting, with a controllable clock.
 */
final class RateLimiterTest extends WP_UnitTestCase {

	private const BUCKET = 'lead:5f2b8c9e';

	/**
	 * Current test time.
	 *
	 * @var int
	 */
	private int $now = 1000000;

	/**
	 * A limiter reading $this->now.
	 *
	 * @param bool $persistent Cache mode.
	 * @return RateLimiter
	 */
	private function limiter( bool $persistent = false ): RateLimiter {
		return new RateLimiter( new Cache( 'test_rate', $persistent ), fn(): int => $this->now );
	}

	/**
	 * The first request is allowed and counted.
	 */
	public function test_first_request_allowed(): void {
		$limiter = $this->limiter();

		$this->assertTrue( $limiter->hit( self::BUCKET, 3, 600 ) );
		$this->assertSame( 1, $limiter->count( self::BUCKET, 600 ) );
	}

	/**
	 * Each request increments the count.
	 */
	public function test_requests_increment(): void {
		$limiter = $this->limiter();
		$limiter->hit( self::BUCKET, 3, 600 );
		$limiter->hit( self::BUCKET, 3, 600 );

		$this->assertSame( 2, $limiter->count( self::BUCKET, 600 ) );
		$this->assertSame( 1, $limiter->remaining( self::BUCKET, 3, 600 ) );
	}

	/**
	 * The request after the limit is refused.
	 */
	public function test_limit_reached(): void {
		$limiter = $this->limiter();

		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertTrue( $limiter->hit( self::BUCKET, 3, 600 ) );
		}

		$this->assertFalse( $limiter->hit( self::BUCKET, 3, 600 ) );
		$this->assertTrue( $limiter->is_limited( self::BUCKET, 3, 600 ) );
		$this->assertSame( 0, $limiter->remaining( self::BUCKET, 3, 600 ) );
	}

	/**
	 * A new window starts from zero.
	 */
	public function test_window_expiry_starts_fresh(): void {
		$limiter = $this->limiter();

		for ( $i = 0; $i < 4; $i++ ) {
			$limiter->hit( self::BUCKET, 3, 600 );
		}

		$this->now += 600;

		$this->assertTrue( $limiter->hit( self::BUCKET, 3, 600 ) );
		$this->assertSame( 1, $limiter->count( self::BUCKET, 600 ) );
	}

	/**
	 * Reset clears the current window.
	 */
	public function test_reset_clears_count(): void {
		$limiter = $this->limiter();
		$limiter->hit( self::BUCKET, 3, 600 );
		$limiter->reset( self::BUCKET, 600 );

		$this->assertSame( 0, $limiter->count( self::BUCKET, 600 ) );
	}

	/**
	 * Buckets are counted separately.
	 */
	public function test_buckets_are_independent(): void {
		$limiter = $this->limiter();
		$limiter->hit( self::BUCKET, 3, 600 );

		$this->assertSame( 0, $limiter->count( 'lead:0a1b2c3d', 600 ) );
	}

	/**
	 * Counting works the same with and without a persistent object cache.
	 */
	public function test_modes_behave_identically(): void {
		$results = array();

		foreach ( array( true, false ) as $persistent ) {
			$limiter = $this->limiter( $persistent );
			$run     = array();

			for ( $i = 0; $i < 3; $i++ ) {
				$run[] = $limiter->hit( self::BUCKET, 2, 600 );
			}

			$results[] = $run;
		}

		$this->assertSame( array( true, true, false ), $results[0] );
		$this->assertSame( $results[0], $results[1] );
	}

	/**
	 * Raw addresses are refused as buckets.
	 */
	public function test_rejects_raw_addresses(): void {
		$refused = 0;

		foreach ( array( '203.0.113.9', '2001:db8::1', 'lead:203.0.113.9', 'lead|2001:db8::7' ) as $bucket ) {
			try {
				$this->limiter()->hit( $bucket, 3, 600 );
			} catch ( \InvalidArgumentException $e ) {
				++$refused;
			}
		}

		$this->assertSame( 4, $refused );
	}
}
