<?php
/**
 * Lock tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Unit;

use Rameshwari\Core\Support\Lock;
use WP_UnitTestCase;

/**
 * Lock behaviour, with a controllable clock.
 */
final class LockTest extends WP_UnitTestCase {

	/**
	 * Current test time.
	 *
	 * @var int
	 */
	private int $now = 1000000;

	/**
	 * A lock store reading $this->now.
	 *
	 * @param bool $persistent Mode.
	 * @return Lock
	 */
	private function lock( bool $persistent = false ): Lock {
		return new Lock( $persistent, fn(): int => $this->now );
	}

	/**
	 * A free lock is acquired and returns a token.
	 */
	public function test_acquire_returns_token(): void {
		$token = $this->lock()->acquire( 'import', 60 );

		$this->assertIsString( $token );
		$this->assertNotSame( '', $token );
	}

	/**
	 * A held lock cannot be acquired again.
	 */
	public function test_overlapping_acquire_is_blocked(): void {
		$lock = $this->lock();
		$lock->acquire( 'import', 60 );

		$this->now += 30;

		$this->assertNull( $lock->acquire( 'import', 60 ) );
		$this->assertTrue( $lock->is_locked( 'import' ) );
	}

	/**
	 * Releasing frees the lock for the next caller.
	 */
	public function test_release_frees_lock(): void {
		$lock  = $this->lock();
		$token = (string) $lock->acquire( 'import', 60 );

		$this->assertTrue( $lock->release( 'import', $token ) );
		$this->assertFalse( $lock->is_locked( 'import' ) );
		$this->assertIsString( $lock->acquire( 'import', 60 ) );
	}

	/**
	 * Only the owner's token releases the lock.
	 */
	public function test_release_requires_owner_token(): void {
		$lock = $this->lock();
		$lock->acquire( 'import', 60 );

		$this->assertFalse( $lock->release( 'import', 'not-the-token' ) );
		$this->assertTrue( $lock->is_locked( 'import' ) );
	}

	/**
	 * An expired lock is reclaimed, and the old token no longer releases it.
	 */
	public function test_expired_lock_is_reclaimed(): void {
		$lock  = $this->lock();
		$first = (string) $lock->acquire( 'cron', 60 );

		$this->now += 61;

		$second = $lock->acquire( 'cron', 60 );

		$this->assertIsString( $second );
		$this->assertNotSame( $first, $second );
		$this->assertFalse( $lock->release( 'cron', $first ) );
	}

	/**
	 * The object-cache mode blocks overlap and reclaims expiry the same way.
	 */
	public function test_persistent_mode(): void {
		$lock = $this->lock( true );

		$this->assertIsString( $lock->acquire( 'worker', 60 ) );
		$this->assertNull( $lock->acquire( 'worker', 60 ) );

		$this->now += 61;

		$this->assertIsString( $lock->acquire( 'worker', 60 ) );
	}

	/**
	 * A corrupt record is treated as abandoned, not as a permanent lock.
	 */
	public function test_corrupt_record_is_reclaimed(): void {
		update_option( 'rj_lock_worker', 'garbage', false );

		$this->assertIsString( $this->lock()->acquire( 'worker', 60 ) );
	}

	/**
	 * With no mode given, an undecided object-cache flag still yields a working lock.
	 */
	public function test_default_mode_normalises_to_bool(): void {
		global $_wp_using_ext_object_cache;

		$saved                      = $_wp_using_ext_object_cache;
		$_wp_using_ext_object_cache = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Reproduces the undecided state.

		try {
			$lock = new Lock( null, fn(): int => $this->now );

			$this->assertIsString( $lock->acquire( 'default-mode', 60 ) );
			$this->assertTrue( $lock->is_locked( 'default-mode' ) );
		} finally {
			$_wp_using_ext_object_cache = $saved; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restores the original state.
		}
	}

	/**
	 * Names must be safe storage keys.
	 */
	public function test_rejects_invalid_name(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->lock()->acquire( 'Bad Name!', 60 );
	}

	/**
	 * Every lock expires within a day.
	 */
	public function test_rejects_unbounded_ttl(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->lock()->acquire( 'import', 86401 );
	}
}
