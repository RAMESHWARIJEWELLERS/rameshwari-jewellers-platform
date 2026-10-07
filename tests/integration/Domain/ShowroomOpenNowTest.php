<?php
/**
 * Showroom open-now service tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Integration\Domain;

use Rameshwari\Core\Domain\Showroom\Showroom;
use Rameshwari\Core\Domain\Showroom\ShowroomOpenNow;
use Rameshwari\Core\Domain\Showroom\ShowroomRepository;

/**
 * Open-now answers for real showroom posts, with a fixed clock unless the site timezone is under test.
 */
final class ShowroomOpenNowTest extends \WP_UnitTestCase {

	/**
	 * Removes the timezone filter.
	 */
	public function tear_down(): void {
		remove_all_filters( 'pre_option_timezone_string' );

		parent::tear_down();
	}

	/**
	 * Hours: Monday 10:00 to 20:00 in a fixed Kolkata clock.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function hours(): array {
		return array(
			'mon' => array(
				'open'  => '10:00',
				'close' => '20:00',
			),
		);
	}

	/**
	 * The service with a fixed Monday-noon clock.
	 *
	 * @param string $when Moment, in Asia/Kolkata.
	 * @return ShowroomOpenNow
	 */
	private function service( string $when = '2026-10-05 12:00' ): ShowroomOpenNow {
		return new ShowroomOpenNow( null, static fn (): \DateTimeImmutable => new \DateTimeImmutable( $when, new \DateTimeZone( 'Asia/Kolkata' ) ) );
	}

	/**
	 * A showroom post.
	 *
	 * @param string $status Post status.
	 * @param bool   $hours  Whether to store hours.
	 * @return int
	 */
	private function showroom( string $status = 'publish', bool $hours = true ): int {
		$id = (int) self::factory()->post->create(
			array(
				'post_type'   => 'rj_showroom',
				'post_status' => $status,
			)
		);

		if ( $hours ) {
			update_post_meta( $id, '_rj_timings', $this->hours() );
		}

		return $id;
	}

	/**
	 * Stores a value the sanitiser would refuse, as old or hand-edited data could be.
	 *
	 * @param int    $id    Post ID.
	 * @param string $key   Meta key.
	 * @param mixed  $value Raw value.
	 * @return void
	 */
	private function raw_meta( int $id, string $key, mixed $value ): void {
		global $wpdb;

		delete_post_meta( $id, $key );

		$row = array(
			'post_id'    => $id,
			'meta_key'   => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Intentional: stores a legacy value the sanitiser would refuse.
			'meta_value' => maybe_serialize( $value ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Intentional: stores a legacy value the sanitiser would refuse.
		);

		$wpdb->insert( $wpdb->postmeta, $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture write.

		wp_cache_delete( $id, 'post_meta' );
	}

	/**
	 * A published showroom answers from its stored hours.
	 */
	public function test_published_showroom_reports_open_and_closed(): void {
		$id = $this->showroom();

		$this->assertSame( Showroom::STATE_OPEN, $this->service( '2026-10-05 12:00' )->state( $id ) );
		$this->assertTrue( $this->service( '2026-10-05 12:00' )->is_open( $id ) );
		$this->assertSame( Showroom::STATE_CLOSED, $this->service( '2026-10-05 20:00' )->state( $id ) );
		$this->assertFalse( $this->service( '2026-10-05 20:00' )->is_open( $id ) );
		$this->assertSame( Showroom::STATE_UNAVAILABLE, $this->service( '2026-10-06 12:00' )->state( $id ) );
	}

	/**
	 * A showroom with no stored hours has no fallback hours.
	 */
	public function test_no_stored_hours_is_unavailable(): void {
		$this->assertSame( Showroom::STATE_UNAVAILABLE, $this->service()->state( $this->showroom( 'publish', false ) ) );
	}

	/**
	 * Anything but a published showroom is UNAVAILABLE, even with hours that would be open.
	 */
	public function test_non_published_showrooms_are_unavailable(): void {
		foreach ( array( 'draft', 'pending', 'private' ) as $status ) {
			$this->assertSame( Showroom::STATE_UNAVAILABLE, $this->service()->state( $this->showroom( $status ) ), $status );
		}

		$trashed = $this->showroom();

		wp_trash_post( $trashed );

		$this->assertSame( Showroom::STATE_UNAVAILABLE, $this->service()->state( $trashed ) );
	}

	/**
	 * Other post types are not showrooms.
	 */
	public function test_non_showroom_posts_are_unavailable(): void {
		$page    = (int) self::factory()->post->create( array( 'post_type' => 'page' ) );
		$product = (int) self::factory()->post->create(
			array(
				'post_type'   => 'rj_product',
				'post_status' => 'draft',
			)
		);

		update_post_meta( $page, '_rj_timings', $this->hours() );

		$this->assertSame( Showroom::STATE_UNAVAILABLE, $this->service()->state( $page ) );
		$this->assertSame( Showroom::STATE_UNAVAILABLE, $this->service()->state( $product ) );
	}

	/**
	 * Zero, negative, unknown and deleted IDs are UNAVAILABLE. ID 0 never picks up the current post.
	 */
	public function test_invalid_and_deleted_ids_are_unavailable(): void {
		$id = $this->showroom();

		$GLOBALS['post'] = get_post( $id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Proves ID 0 does not resolve to the current post.

		$this->assertSame( Showroom::STATE_UNAVAILABLE, $this->service()->state( 0 ) );

		unset( $GLOBALS['post'] );

		foreach ( array( -5, 99999999 ) as $bad ) {
			$this->assertSame( Showroom::STATE_UNAVAILABLE, $this->service()->state( $bad ), (string) $bad );
		}

		wp_delete_post( $id, true );

		$this->assertSame( Showroom::STATE_UNAVAILABLE, $this->service()->state( $id ) );
	}

	/**
	 * Malformed stored data never reaches the visitor as an error.
	 */
	public function test_malformed_stored_data_is_unavailable(): void {
		$text  = $this->showroom( 'publish', false );
		$entry = $this->showroom( 'publish', false );
		$pin   = $this->showroom();

		$this->raw_meta( $text, '_rj_timings', 'not timings' );
		$this->raw_meta(
			$entry,
			'_rj_timings',
			array(
				'mon' => array(
					'open'  => '99:99',
					'close' => '20:00',
				),
			)
		);
		$this->raw_meta( $pin, '_rj_pincode', '12' );

		$this->assertSame( Showroom::STATE_UNAVAILABLE, $this->service()->state( $text ) );
		$this->assertSame( Showroom::STATE_UNAVAILABLE, $this->service()->state( $entry ) );
		$this->assertSame( Showroom::STATE_UNAVAILABLE, $this->service()->state( $pin ) );
	}

	/**
	 * A failing clock or a clock returning the wrong thing is UNAVAILABLE.
	 */
	public function test_a_bad_clock_is_unavailable(): void {
		$id = $this->showroom();

		$throws = new ShowroomOpenNow(
			null,
			static function (): \DateTimeImmutable {
				throw new \RuntimeException( 'clock failed' );
			}
		);

		$wrong = new ShowroomOpenNow( null, static fn (): string => '2026-10-05 12:00' );

		$this->assertSame( Showroom::STATE_UNAVAILABLE, $throws->state( $id ) );
		$this->assertSame( Showroom::STATE_UNAVAILABLE, $wrong->state( $id ) );
	}

	/**
	 * With no clock given the service reads the site timezone.
	 */
	public function test_default_clock_uses_the_site_timezone(): void {
		$id = $this->showroom( 'publish', false );

		add_filter( 'pre_option_timezone_string', fn (): string => 'Asia/Kolkata' );

		$now   = new \DateTimeImmutable( 'now', wp_timezone() );
		$from  = $now->modify( '-1 hour' )->format( 'H:i' );
		$until = $now->modify( '+1 hour' )->format( 'H:i' );
		$day   = array(
			'open'  => $from,
			'close' => $until,
		);

		update_post_meta( $id, '_rj_timings', array_fill_keys( Showroom::DAYS, $day ) );

		$this->assertSame( Showroom::STATE_OPEN, ( new ShowroomOpenNow() )->state( $id ) );

		remove_all_filters( 'pre_option_timezone_string' );
		add_filter( 'pre_option_timezone_string', fn (): string => 'America/New_York' );

		$this->assertSame( Showroom::STATE_CLOSED, ( new ShowroomOpenNow() )->state( $id ) );
	}

	/**
	 * Asking never writes: no meta, option, taxonomy, table or post change appears.
	 */
	public function test_nothing_is_persisted(): void {
		global $wpdb;

		$id       = $this->showroom();
		$meta     = get_post_meta( $id );
		$options  = wp_load_alloptions( true );
		$taxes    = get_taxonomies();
		$tables   = $wpdb->get_col( 'SHOW TABLES' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Proves no table is added.
		$modified = get_post( $id )->post_modified;

		$this->service( '2026-10-05 12:00' )->state( $id );
		$this->service( '2026-10-05 22:00' )->is_open( $id );

		$this->assertSame( $meta, get_post_meta( $id ) );
		$this->assertSame( $options, wp_load_alloptions( true ) );
		$this->assertSame( $taxes, get_taxonomies() );
		$this->assertSame( $tables, $wpdb->get_col( 'SHOW TABLES' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Proves no table is added.
		$this->assertSame( $modified, get_post( $id )->post_modified );
		$this->assertSame( array(), array_values( preg_grep( '/open|state|hours/i', array_keys( $meta ) ) ) );
	}

	/**
	 * The repository still reads the same showroom, so the service adds no second path to the data.
	 */
	public function test_repository_contract_is_unchanged(): void {
		$id = $this->showroom();

		$this->assertInstanceOf( Showroom::class, ( new ShowroomRepository() )->find( $id ) );
		$this->assertSame( $this->hours(), ( new ShowroomRepository() )->find( $id )->timings );
	}
}
