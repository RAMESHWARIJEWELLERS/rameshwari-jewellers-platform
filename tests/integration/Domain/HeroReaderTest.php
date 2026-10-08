<?php
/**
 * Hero reader tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Integration\Domain;

use Rameshwari\Core\Domain\PageSection\HeroReader;
use Rameshwari\Core\Domain\PageSection\HeroSelection;
use Rameshwari\Core\Domain\PageSection\PageSection;
use Rameshwari\Core\Domain\PageSection\PageSectionRepository;
use Rameshwari\Core\Support\Cache;
use Rameshwari\Core\Support\Logger;

/**
 * Which heroes show now, from real posts, with a real cache.
 */
final class HeroReaderTest extends \WP_UnitTestCase {

	/**
	 * Fixed clock in the Kolkata timezone.
	 *
	 * @param string $time Y-m-d H:i:s.
	 * @return \DateTimeImmutable
	 */
	private function now( string $time = '2026-10-06 12:00:00' ): \DateTimeImmutable {
		return new \DateTimeImmutable( $time, new \DateTimeZone( 'Asia/Kolkata' ) );
	}

	/**
	 * A quiet logger.
	 *
	 * @return Logger
	 */
	private function quiet(): Logger {
		return new Logger( static function (): void {} );
	}

	/**
	 * A page section post with meta.
	 *
	 * @param array<string,mixed> $meta   Meta overrides.
	 * @param string              $status Post status.
	 * @return int
	 */
	private function section( array $meta = array(), string $status = 'publish' ): int {
		$args = array(
			'post_type'   => 'rj_page_section',
			'post_status' => $status,
		);

		if ( 'future' === $status ) {
			$args['post_date']     = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );
			$args['post_date_gmt'] = $args['post_date'];
		}

		$id = self::factory()->post->create( $args );

		foreach ( array_merge(
			array(
				'_rj_section_key' => 'hero',
				'_rj_active'      => true,
			),
			$meta
		) as $key => $value ) {
			update_post_meta( $id, $key, $value );
		}

		return $id;
	}

	/**
	 * Writes a meta value straight to the table, creating the row if it does not exist, as a legacy record would hold it.
	 *
	 * @param int    $id    Post ID.
	 * @param string $key   Meta key.
	 * @param string $value Raw value.
	 * @return void
	 */
	private function legacy( int $id, string $key, string $value ): void {
		global $wpdb;

		$data  = array(
			'meta_value' => $value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Intentional test fixture write of a raw meta value.
		);
		$where = array(
			'post_id'  => $id,
			'meta_key' => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Intentional test fixture write of the registered meta key.
		);

		wp_cache_delete( $id, 'post_meta' );

		if ( metadata_exists( 'post', $id, $key ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixture writes a legacy value that registered sanitising would clean.
			$wpdb->update( $wpdb->postmeta, $data, $where );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixture creates a legacy row that was never written through registered meta.
			$wpdb->insert( $wpdb->postmeta, $where + $data );
		}

		wp_cache_delete( $id, 'post_meta' );
	}

	/**
	 * IDs of a selection's desktop list.
	 *
	 * @param HeroSelection $selection Selection.
	 * @return array<int,int>
	 */
	private function ids( HeroSelection $selection ): array {
		return array_map( static fn( PageSection $section ): int => $section->id, $selection->desktop() );
	}

	/**
	 * A reader with a fixed clock.
	 *
	 * @param \DateTimeImmutable|null    $now   Clock value.
	 * @param Cache|null                 $cache Cache.
	 * @param PageSectionRepository|null $repo  Repository.
	 * @param Logger|null                $log   Logger.
	 * @return HeroReader
	 */
	private function reader( ?\DateTimeImmutable $now = null, ?Cache $cache = null, ?PageSectionRepository $repo = null, ?Logger $log = null ): HeroReader {
		$moment = $now ?? $this->now();

		return new HeroReader( $repo ?? new PageSectionRepository( $this->quiet() ), $cache ?? new Cache( HeroReader::GROUP, false ), $log ?? $this->quiet(), static fn(): \DateTimeImmutable => $moment );
	}

	/**
	 * The expiry stored with the cached answer, or minus one.
	 *
	 * @param Cache $cache Cache.
	 * @return int
	 */
	private function until( Cache $cache ): int {
		$payload = $cache->get( HeroReader::KEY );

		return is_array( $payload ) && isset( $payload['valid_until'] ) && is_int( $payload['valid_until'] ) ? $payload['valid_until'] : -1;
	}

	/**
	 * Only published, switched-on, in-window heroes are listed.
	 */
	public function test_eligibility_matrix(): void {
		$ok = $this->section(
			array(
				'_rj_order' => 1,
			)
		);

		foreach ( array( 'draft', 'private', 'pending', 'future', 'trash', 'auto-draft' ) as $status ) {
			$this->section( array(), $status );
		}

		$this->section(
			array(
				'_rj_active' => false,
			)
		);
		$this->section(
			array(
				'_rj_section_key' => 'about',
			)
		);
		$this->section(
			array(
				'_rj_starts_at' => '2026-10-06 12:00:01',
			)
		);
		$this->section(
			array(
				'_rj_ends_at' => '2026-10-06 12:00:00',
			)
		);
		$this->section(
			array(
				'_rj_starts_at' => '2026-10-06 13:00:00',
				'_rj_ends_at'   => '2026-10-06 13:00:00',
			)
		);

		$this->assertSame( array( $ok ), $this->ids( $this->reader()->selection() ) );
	}

	/**
	 * Exact start shows, exact end does not, and an image is not required.
	 */
	public function test_boundaries_and_no_image(): void {
		$start = $this->section(
			array(
				'_rj_starts_at' => '2026-10-06 12:00:00',
				'_rj_order'     => 1,
			)
		);

		$this->section(
			array(
				'_rj_ends_at' => '2026-10-06 12:00:00',
				'_rj_order'   => 2,
			)
		);

		$list = $this->reader()->selection()->desktop();

		$this->assertSame( array( $start ), $this->ids( $this->reader( null, new Cache( HeroReader::GROUP, true ) )->selection() ) );
		$this->assertSame( 0, $list[0]->image_desktop );
	}

	/**
	 * Order ascending, then post ID; invalid legacy orders come after every valid one.
	 */
	public function test_ordering(): void {
		$late    = $this->section(
			array(
				'_rj_order' => 50,
			)
		);
		$tie_a   = $this->section(
			array(
				'_rj_order' => 10,
			)
		);
		$tie_b   = $this->section(
			array(
				'_rj_order' => 10,
			)
		);
		$bad_neg = $this->section(
			array(
				'_rj_order' => 1,
			)
		);
		$bad_big = $this->section(
			array(
				'_rj_order' => 1,
			)
		);
		$bad_txt = $this->section(
			array(
				'_rj_order' => 1,
			)
		);
		$top     = $this->section(
			array(
				'_rj_order' => 0,
			)
		);

		$this->legacy( $bad_neg, '_rj_order', '-5' );
		$this->legacy( $bad_big, '_rj_order', '99999' );
		$this->legacy( $bad_txt, '_rj_order', 'abc' );

		$this->assertSame( array( $top, $tie_a, $tie_b, $late, $bad_neg, $bad_big, $bad_txt ), $this->ids( $this->reader()->selection() ) );
	}

	/**
	 * Desktop gets every hero; mobile gets exactly the first; empty is explicit.
	 */
	public function test_desktop_mobile_and_empty(): void {
		$empty = $this->reader();

		$this->assertTrue( $empty->selection()->is_empty() );
		$this->assertNull( $empty->selection()->mobile() );
		$this->assertSame( array(), $empty->for_context( true ) );
		$this->assertSame( array(), $empty->for_context( false ) );

		$second = $this->section(
			array(
				'_rj_order' => 20,
			)
		);
		$first  = $this->section(
			array(
				'_rj_order' => 10,
			)
		);
		$reader = $this->reader( null, new Cache( HeroReader::GROUP, false ) );

		$this->assertSame( array( $first, $second ), array_map( static fn( PageSection $s ): int => $s->id, $reader->for_context( false ) ) );
		$this->assertSame( array( $first ), array_map( static fn( PageSection $s ): int => $s->id, $reader->for_context( true ) ) );
	}

	/**
	 * A malformed legacy date makes the section ineligible and is logged without its value.
	 */
	public function test_malformed_dates_fail_closed_and_log_safely(): void {
		$lines = array();
		$log   = new Logger(
			static function ( string $line ) use ( &$lines ): void {
				$lines[] = $line;
			}
		);
		$good  = $this->section(
			array(
				'_rj_order' => 1,
			)
		);
		$bad   = $this->section(
			array(
				'_rj_order' => 2,
			)
		);

		$this->legacy( $bad, '_rj_ends_at', 'garbage-value' );

		$this->assertSame( array( $good ), $this->ids( $this->reader( null, null, null, $log )->selection() ) );
		$this->assertCount( 1, $lines );
		$this->assertStringContainsString( 'invalid schedule window', $lines[0] );
		$this->assertStringNotContainsString( 'garbage-value', $lines[0] );
	}

	/**
	 * Cached lifetime follows the next start or end, at most 3600 seconds.
	 */
	public function test_ttl_follows_the_next_boundary(): void {
		$now   = $this->now();
		$stamp = $now->getTimestamp();
		$cache = new Cache( HeroReader::GROUP, false );

		$cache->bump();
		$this->reader( $now, $cache )->selection();
		$this->assertSame( $stamp + 3600, $this->until( $cache ) );

		$this->section(
			array(
				'_rj_starts_at' => '2026-10-06 12:30:00',
			)
		);
		$cache->bump();
		$this->reader( $now, $cache )->selection();
		$this->assertSame( $stamp + 1800, $this->until( $cache ) );

		$this->section(
			array(
				'_rj_ends_at' => '2026-10-06 12:10:00',
			)
		);
		$cache->bump();
		$this->reader( $now, $cache )->selection();
		$this->assertSame( $stamp + 600, $this->until( $cache ) );
	}

	/**
	 * A boundary further than an hour away is capped at 3600.
	 */
	public function test_far_boundary_is_capped(): void {
		$now   = $this->now();
		$cache = new Cache( HeroReader::GROUP, false );

		$this->section(
			array(
				'_rj_starts_at' => '2026-10-07 12:00:00',
			)
		);
		$cache->bump();
		$this->reader( $now, $cache )->selection();

		$this->assertSame( $now->getTimestamp() + 3600, $this->until( $cache ) );
	}

	/**
	 * A second read is a hit; a change made outside the hooks is not seen until invalidation.
	 */
	public function test_hit_does_not_recompute_and_bump_does(): void {
		$cache = new Cache( HeroReader::GROUP, false );
		$a     = $this->section(
			array(
				'_rj_order' => 5,
			)
		);
		$b     = $this->section(
			array(
				'_rj_order' => 1,
			)
		);
		$r     = $this->reader( null, $cache );

		$this->assertSame( array( $b, $a ), $this->ids( $r->selection() ) );

		$this->legacy( $b, '_rj_order', '9' );

		$this->assertSame( array( $b, $a ), $this->ids( $r->selection() ) );

		$cache->bump();

		$this->assertSame( array( $a, $b ), $this->ids( $r->selection() ) );
	}

	/**
	 * An answer past its own expiry time, or with an absurd one, is a miss.
	 */
	public function test_valid_until_guard(): void {
		$now   = $this->now();
		$stamp = $now->getTimestamp();
		$cache = new Cache( HeroReader::GROUP, false );
		$id    = $this->section();

		foreach ( array( $stamp - 1, $stamp, $stamp + 999999 ) as $until ) {
			$cache->set(
				HeroReader::KEY,
				array(
					'valid_until' => $until,
					'sections'    => array(),
				),
				60
			);

			$this->assertSame( array( $id ), $this->ids( $this->reader( $now, $cache )->selection() ), (string) $until );
		}
	}

	/**
	 * A damaged cache entry, a lost version marker and a write that stores nothing all recompute.
	 */
	public function test_cache_failures_recompute(): void {
		$cache = new Cache( HeroReader::GROUP, false );
		$id    = $this->section();
		$r     = $this->reader( null, $cache );

		$this->assertSame( array( $id ), $this->ids( $r->selection() ) );
		$this->assertTrue( $cache->has( HeroReader::KEY ) );

		delete_transient( 'rj_cv_' . HeroReader::GROUP );

		$this->assertFalse( $cache->has( HeroReader::KEY ) );
		$this->assertSame( array( $id ), $this->ids( $r->selection() ) );

		add_filter( 'pre_transient_' . $cache->storage_key( HeroReader::KEY ), static fn(): string => 'corrupt' );

		$ids = $this->ids( $r->selection() );

		$this->assertCount( 1, $ids );
		$this->assertSame( $id, $ids[0] );

		remove_all_filters( 'pre_transient_' . $cache->storage_key( HeroReader::KEY ) );
		add_filter( 'pre_set_transient_' . $cache->storage_key( HeroReader::KEY ), '__return_true' );
		$cache->delete( HeroReader::KEY );

		$ids = $this->ids( $r->selection() );

		$this->assertCount( 1, $ids );
		$this->assertSame( $id, $ids[0] );
		$this->assertFalse( $cache->has( HeroReader::KEY ) );
	}

	/**
	 * Both cache modes give the same answer and the same expiry.
	 */
	public function test_transient_and_persistent_modes_agree(): void {
		$now = $this->now();

		$this->section(
			array(
				'_rj_order'   => 2,
				'_rj_ends_at' => '2026-10-06 12:20:00',
			)
		);
		$this->section(
			array(
				'_rj_order' => 1,
			)
		);

		$seen = array();

		foreach ( array( false, true ) as $persistent ) {
			$cache = new Cache( HeroReader::GROUP, $persistent );

			$cache->bump();

			$seen[] = array( $this->ids( $this->reader( $now, $cache )->selection() ), $this->until( $cache ) );
		}

		$this->assertSame( $seen[0], $seen[1] );
		$this->assertSame( $now->getTimestamp() + 1200, $seen[0][1] );
	}

	/**
	 * Too many sections gives an empty, uncached answer instead of a partial one.
	 */
	public function test_overflow_is_empty_and_uncached(): void {
		$cache = new Cache( HeroReader::GROUP, false );

		$this->section();
		$this->section();
		$this->section();

		$reader = $this->reader( null, $cache, new PageSectionRepository( $this->quiet(), 2 ) );

		$this->assertTrue( $reader->selection()->is_empty() );
		$this->assertFalse( $cache->has( HeroReader::KEY ) );
	}

	/**
	 * With the default clock the dates are read in the site timezone.
	 */
	public function test_default_clock_uses_the_site_timezone(): void {
		update_option( 'timezone_string', 'Pacific/Kiritimati' );

		$zone  = wp_timezone();
		$local = new \DateTimeImmutable( 'now', $zone );
		$id    = $this->section(
			array(
				'_rj_starts_at' => $local->modify( '-1 hour' )->format( 'Y-m-d H:i:s' ),
				'_rj_ends_at'   => $local->modify( '+1 hour' )->format( 'Y-m-d H:i:s' ),
			)
		);

		$reader = new HeroReader( new PageSectionRepository( $this->quiet() ), new Cache( HeroReader::GROUP, false ), $this->quiet() );

		$this->assertSame( array( $id ), $this->ids( $reader->selection() ) );
	}

	/**
	 * No scheduled job decides anything: a future start and a past end work from the clock alone.
	 */
	public function test_works_with_cron_disabled(): void {
		$now   = $this->now();
		$later = $this->now( '2026-10-06 13:00:00' );
		$id    = $this->section(
			array(
				'_rj_starts_at' => '2026-10-06 12:30:00',
				'_rj_ends_at'   => '2026-10-06 13:00:00',
			)
		);

		$this->assertFalse( wp_next_scheduled( 'rj_page_section_activate' ) );
		$this->assertSame( array(), $this->ids( $this->reader( $now, new Cache( HeroReader::GROUP, false ) )->selection() ) );
		$this->assertSame( array( $id ), $this->ids( $this->reader( $this->now( '2026-10-06 12:45:00' ), new Cache( HeroReader::GROUP, false ) )->selection() ) );
		$this->assertSame( array(), $this->ids( $this->reader( $later, new Cache( HeroReader::GROUP, false ) )->selection() ) );
		$this->assertSame( 'publish', get_post_status( $id ) );
		$this->assertFalse( metadata_exists( 'post', $id, '_rj_is_current' ) );
	}
}
