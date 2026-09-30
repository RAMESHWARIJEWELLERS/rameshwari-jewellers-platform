<?php
/**
 * Cache tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Unit;

use Rameshwari\Core\Support\Cache;
use WP_UnitTestCase;

/**
 * Cache behaviour in both backend modes.
 */
final class CacheTest extends WP_UnitTestCase {

	/**
	 * A value written can be read back.
	 */
	public function test_set_then_get(): void {
		$cache = new Cache( 'test_cache', false );
		$cache->set( 'answer', 42, 60 );

		$this->assertSame( 42, $cache->get( 'answer' ) );
	}

	/**
	 * A missing key returns the fallback.
	 */
	public function test_miss_returns_fallback(): void {
		$cache = new Cache( 'test_cache', false );

		$this->assertSame( 'none', $cache->get( 'absent', 'none' ) );
		$this->assertFalse( $cache->has( 'absent' ) );
	}

	/**
	 * Stored false and null are hits, not misses.
	 */
	public function test_false_and_null_are_hits(): void {
		$cache = new Cache( 'test_cache', false );
		$cache->set( 'no', false, 60 );
		$cache->set( 'nothing', null, 60 );

		$this->assertTrue( $cache->has( 'no' ) );
		$this->assertFalse( $cache->get( 'no', 'fallback' ) );
		$this->assertTrue( $cache->has( 'nothing' ) );
	}

	/**
	 * An entry past its expiry is a miss.
	 */
	public function test_expired_entry_is_a_miss(): void {
		$cache = new Cache( 'test_cache', false );
		$cache->set( 'short', 'value', 60 );

		update_option( '_transient_timeout_' . $cache->storage_key( 'short' ), time() - 1 );

		$this->assertFalse( $cache->has( 'short' ) );
	}

	/**
	 * One bump invalidates every key in the group.
	 */
	public function test_bump_invalidates_group(): void {
		$cache = new Cache( 'test_cache', false );
		$cache->set( 'a', 1, 60 );
		$cache->set( 'b', 2, 60 );

		$cache->bump();

		$this->assertFalse( $cache->has( 'a' ) );
		$this->assertFalse( $cache->has( 'b' ) );
	}

	/**
	 * Bumping one group leaves another untouched.
	 */
	public function test_bump_leaves_other_groups(): void {
		$menu  = new Cache( 'test_menu', false );
		$terms = new Cache( 'test_terms', false );
		$menu->set( 'tree', 'm', 60 );
		$terms->set( 'tree', 't', 60 );

		$menu->bump();

		$this->assertFalse( $menu->has( 'tree' ) );
		$this->assertSame( 't', $terms->get( 'tree' ) );
	}

	/**
	 * Keys carry the group version: stable until a bump, different after.
	 */
	public function test_key_carries_group_version(): void {
		$cache  = new Cache( 'test_cache', false );
		$before = $cache->storage_key( 'k' );

		$this->assertSame( $before, $cache->storage_key( 'k' ) );

		$cache->bump();

		$this->assertNotSame( $before, $cache->storage_key( 'k' ) );
	}

	/**
	 * Persistent mode writes to the object cache, not to transients.
	 */
	public function test_persistent_mode_uses_object_cache(): void {
		$cache = new Cache( 'test_cache', true );
		$cache->set( 'k', 'v', 60 );

		$key   = $cache->storage_key( 'k' );
		$found = false;
		wp_cache_get( $key, 'rameshwari', false, $found );

		$this->assertTrue( $cache->is_persistent() );
		$this->assertTrue( $found );
		$this->assertFalse( get_transient( $key ) );
	}

	/**
	 * Fallback mode writes to transients.
	 */
	public function test_transient_mode_uses_transients(): void {
		$cache = new Cache( 'test_cache', false );
		$cache->set( 'k', 'v', 60 );

		$this->assertFalse( $cache->is_persistent() );
		$this->assertSame( array( 'value' => 'v' ), get_transient( $cache->storage_key( 'k' ) ) );
	}

	/**
	 * Both modes give the same results through the same calls.
	 */
	public function test_modes_behave_identically(): void {
		foreach ( array( true, false ) as $persistent ) {
			$cache = new Cache( 'test_modes', $persistent );
			$cache->set( 'k', array( 1, 2 ), 60 );

			$this->assertSame( array( 1, 2 ), $cache->get( 'k' ) );

			$cache->bump();

			$this->assertNull( $cache->get( 'k' ) );
		}
	}

	/**
	 * A lost version marker causes a miss, never a stale hit.
	 */
	public function test_lost_version_marker_causes_miss(): void {
		$cache = new Cache( 'test_cache', false );
		$cache->set( 'k', 'old', 60 );

		delete_transient( 'rj_cv_test_cache' );

		$this->assertFalse( $cache->has( 'k' ) );
	}

	/**
	 * Remember() computes once and serves the cached value after.
	 */
	public function test_remember_computes_once(): void {
		$cache = new Cache( 'test_cache', false );
		$calls = 0;
		$make  = static function () use ( &$calls ): string {
			++$calls;
			return 1 === $calls ? 'built' : 'changed';
		};

		$first = $cache->remember( 'k', 60, $make );
		$this->assertSame( 'built', $first );
		$second = $cache->remember( 'k', 60, $make );
		$this->assertSame( $first, $second );
		$this->assertSame( 1, $calls );
	}

	/**
	 * Nothing is stored without an expiry.
	 */
	public function test_rejects_non_positive_ttl(): void {
		$this->expectException( \InvalidArgumentException::class );
		( new Cache( 'test_cache', false ) )->set( 'k', 'v', 0 );
	}

	/**
	 * A bump never clears unrelated cache data, and the class never flushes.
	 */
	public function test_never_flushes_globally(): void {
		wp_cache_set( 'unrelated', 'kept', 'someone_else' );

		( new Cache( 'test_cache', true ) )->bump();

		$this->assertSame( 'kept', wp_cache_get( 'unrelated', 'someone_else' ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local source file.
		$source = (string) file_get_contents( __DIR__ . '/../../plugin/rameshwari-core/src/Support/Cache.php' );

		$this->assertStringNotContainsString( 'wp_cache_flush', $source );
	}
}
