<?php
/**
 * Showroom model tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use Rameshwari\Core\Data\Meta;
use Rameshwari\Core\Domain\Showroom\Showroom;

/**
 * The showroom contract, with _rj_timings as the only source of hours.
 */
final class ShowroomTest extends TestCase {

	/**
	 * A showroom with named overrides.
	 *
	 * @param array<string,mixed> $over Field overrides.
	 * @return Showroom
	 */
	private function make( array $over = array() ): Showroom {
		return new Showroom(
			...array_merge(
				array(
					'id'       => 11,
					'status'   => 'publish',
					'address'  => 'Near Example Road',
					'city'     => 'Jaipur',
					'state'    => 'Rajasthan',
					'pincode'  => '302012',
					'phone'    => '911234567890',
					'whatsapp' => '919876543210',
					'timings'  => array(
						'mon' => array(
							'open'  => '10:00',
							'close' => '20:00',
						),
						'sun' => array( 'closed' => true ),
					),
					'map_url'  => 'https://maps.example.test/x',
					'lat'      => 26.9124,
					'lng'      => 75.7873,
					'gallery'  => array( 5, 6, 7 ),
				),
				$over
			)
		);
	}

	/**
	 * Every listed override breaks the contract and is refused.
	 *
	 * @param array<int,array<string,mixed>> $bad Overrides.
	 * @return void
	 */
	private function assert_all_rejected( array $bad ): void {
		foreach ( $bad as $over ) {
			try {
				$this->make( $over );

				$this->fail( 'Accepted a bad value for ' . implode( ',', array_keys( $over ) ) );
			} catch ( \InvalidArgumentException $expected ) {
				$this->assertNotSame( '', $expected->getMessage() );
			}
		}
	}

	/**
	 * Every field is readable as given.
	 */
	public function test_construction_keeps_every_field(): void {
		$showroom = $this->make();

		$this->assertSame( 11, $showroom->id );
		$this->assertSame( 'Near Example Road', $showroom->address );
		$this->assertSame( array( 'Jaipur', 'Rajasthan', '302012' ), array( $showroom->city, $showroom->state, $showroom->pincode ) );
		$this->assertSame( array( '911234567890', '919876543210' ), array( $showroom->phone, $showroom->whatsapp ) );
		$this->assertSame( 'https://maps.example.test/x', $showroom->map_url );
		$this->assertSame( array( 26.9124, 75.7873 ), array( $showroom->lat, $showroom->lng ) );
		$this->assertSame( array( 5, 6, 7 ), $showroom->gallery );
		$this->assertTrue( $showroom->has_location() );
	}

	/**
	 * Hours come from timings by day and nowhere else.
	 */
	public function test_hours_come_only_from_timings(): void {
		$showroom = $this->make();

		$this->assertSame(
			array(
				'open'  => '10:00',
				'close' => '20:00',
			),
			$showroom->timing_for( 'mon' )
		);
		$this->assertSame( array( 'closed' => true ), $showroom->timing_for( 'sun' ) );
		$this->assertSame( array(), $showroom->timing_for( 'tue' ) );
	}

	/**
	 * The model has no second hours field, and neither does the registered meta.
	 */
	public function test_timings_is_the_sole_hours_field(): void {
		$props = array_map( static fn( \ReflectionProperty $p ): string => $p->getName(), ( new \ReflectionClass( Showroom::class ) )->getProperties() );

		$this->assertSame( array(), preg_grep( '/hour|opening|open_|close/i', $props ) );
		$this->assertContains( 'timings', $props );

		$keys = array_keys( Meta::post_meta()['rj_showroom'] );

		$this->assertSame( array( '_rj_timings' ), array_values( preg_grep( '/hour|timing|open/i', $keys ) ) );
	}

	/**
	 * Without coordinates the showroom has no location.
	 */
	public function test_no_coordinates_means_no_location(): void {
		$this->assertFalse(
			$this->make(
				array(
					'lat' => 0.0,
					'lng' => 0.0,
				)
			)->has_location()
		);
	}

	/**
	 * Limits are accepted at the edge and refused beyond.
	 */
	public function test_coordinate_pincode_and_gallery_limits(): void {
		$this->assertSame( 90.0, $this->make( array( 'lat' => 90.0 ) )->lat );
		$this->assertSame( -180.0, $this->make( array( 'lng' => -180.0 ) )->lng );
		$this->assertSame( '', $this->make( array( 'pincode' => '' ) )->pincode );

		$this->assert_all_rejected(
			array(
				array( 'lat' => 90.1 ),
				array( 'lng' => 180.1 ),
				array( 'pincode' => '30201' ),
				array( 'pincode' => '30201A' ),
				array( 'gallery' => array( 0 ) ),
				array( 'timings' => array( 'noon' => array() ) ),
				array( 'id' => 0 ),
			)
		);
	}

	/**
	 * Gallery length is not limited by the model.
	 */
	public function test_gallery_is_not_capped(): void {
		$ids = range( 1, 40 );

		$this->assertSame( $ids, $this->make( array( 'gallery' => $ids ) )->gallery );
	}

	/**
	 * A moment in a timezone. 2026-10-05 is a Monday.
	 *
	 * @param string $when Date and time.
	 * @param string $zone Timezone name.
	 * @return \DateTimeImmutable
	 */
	private function at_time( string $when, string $zone = 'Asia/Kolkata' ): \DateTimeImmutable {
		return new \DateTimeImmutable( $when, new \DateTimeZone( $zone ) );
	}

	/**
	 * A showroom with the given timings.
	 *
	 * @param array<string,mixed> $timings Hours by day.
	 * @return Showroom
	 */
	private function with( array $timings ): Showroom {
		return $this->make( array( 'timings' => $timings ) );
	}

	/**
	 * The three states are fixed names.
	 */
	public function test_states_are_the_three_runtime_names(): void {
		$this->assertSame( array( 'OPEN', 'CLOSED', 'UNAVAILABLE' ), array( Showroom::STATE_OPEN, Showroom::STATE_CLOSED, Showroom::STATE_UNAVAILABLE ) );
	}

	/**
	 * Same-day hours: before, exactly at opening, inside, exactly at closing, after.
	 */
	public function test_same_day_interval_boundaries(): void {
		$shop = $this->make();

		$this->assertSame( Showroom::STATE_CLOSED, $shop->state_at( $this->at_time( '2026-10-05 09:59' ) ) );
		$this->assertSame( Showroom::STATE_OPEN, $shop->state_at( $this->at_time( '2026-10-05 10:00' ) ) );
		$this->assertSame( Showroom::STATE_OPEN, $shop->state_at( $this->at_time( '2026-10-05 15:00' ) ) );
		$this->assertSame( Showroom::STATE_OPEN, $shop->state_at( $this->at_time( '2026-10-05 19:59' ) ) );
		$this->assertSame( Showroom::STATE_CLOSED, $shop->state_at( $this->at_time( '2026-10-05 20:00' ) ) );
		$this->assertSame( Showroom::STATE_CLOSED, $shop->state_at( $this->at_time( '2026-10-05 20:01' ) ) );
		$this->assertTrue( $shop->is_open_at( $this->at_time( '2026-10-05 12:00' ) ) );
		$this->assertFalse( $shop->is_open_at( $this->at_time( '2026-10-05 21:00' ) ) );
	}

	/**
	 * An explicit closed day is CLOSED. A day with no entry is UNAVAILABLE, never assumed closed.
	 */
	public function test_closed_day_missing_day_and_empty_timings(): void {
		$shop = $this->make();

		$this->assertSame( Showroom::STATE_CLOSED, $shop->state_at( $this->at_time( '2026-10-04 12:00' ) ) );
		$this->assertSame( Showroom::STATE_UNAVAILABLE, $shop->state_at( $this->at_time( '2026-10-06 12:00' ) ) );
		$this->assertSame( Showroom::STATE_UNAVAILABLE, $this->with( array() )->state_at( $this->at_time( '2026-10-05 12:00' ) ) );
	}

	/**
	 * An overnight day is open until midnight and again after midnight, closing exactly at its close.
	 */
	public function test_overnight_interval(): void {
		$shop = $this->with(
			array(
				'mon' => array(
					'open'  => '18:00',
					'close' => '02:00',
				),
				'tue' => array(
					'open'  => '10:00',
					'close' => '20:00',
				),
			)
		);

		$this->assertSame( Showroom::STATE_CLOSED, $shop->state_at( $this->at_time( '2026-10-05 17:59' ) ) );
		$this->assertSame( Showroom::STATE_OPEN, $shop->state_at( $this->at_time( '2026-10-05 18:00' ) ) );
		$this->assertSame( Showroom::STATE_OPEN, $shop->state_at( $this->at_time( '2026-10-05 23:59' ) ) );
		$this->assertSame( Showroom::STATE_OPEN, $shop->state_at( $this->at_time( '2026-10-06 00:00' ) ) );
		$this->assertSame( Showroom::STATE_OPEN, $shop->state_at( $this->at_time( '2026-10-06 01:59' ) ) );
		$this->assertSame( Showroom::STATE_CLOSED, $shop->state_at( $this->at_time( '2026-10-06 02:00' ) ) );
		$this->assertSame( Showroom::STATE_CLOSED, $shop->state_at( $this->at_time( '2026-10-06 09:59' ) ) );
	}

	/**
	 * The spill from yesterday is read before a closed day is declared closed.
	 */
	public function test_spill_overrides_a_closed_day(): void {
		$shop = $this->with(
			array(
				'mon' => array(
					'open'  => '18:00',
					'close' => '02:00',
				),
				'tue' => array( 'closed' => true ),
			)
		);

		$this->assertSame( Showroom::STATE_OPEN, $shop->state_at( $this->at_time( '2026-10-06 01:00' ) ) );
		$this->assertSame( Showroom::STATE_CLOSED, $shop->state_at( $this->at_time( '2026-10-06 02:00' ) ) );
		$this->assertSame( Showroom::STATE_CLOSED, $shop->state_at( $this->at_time( '2026-10-06 12:00' ) ) );
	}

	/**
	 * Sunday's overnight hours run into Monday morning.
	 */
	public function test_sunday_spills_into_monday(): void {
		$shop = $this->with(
			array(
				'sun' => array(
					'open'  => '20:00',
					'close' => '03:00',
				),
				'mon' => array(
					'open'  => '10:00',
					'close' => '20:00',
				),
			)
		);

		$this->assertSame( Showroom::STATE_OPEN, $shop->state_at( $this->at_time( '2026-10-05 02:59' ) ) );
		$this->assertSame( Showroom::STATE_CLOSED, $shop->state_at( $this->at_time( '2026-10-05 03:00' ) ) );
		$this->assertSame( Showroom::STATE_OPEN, $shop->state_at( $this->at_time( '2026-10-04 23:00' ) ) );
	}

	/**
	 * Equal open and close is not read as a 24-hour day, and cannot spill into the next day.
	 */
	public function test_open_equal_to_close_is_unavailable(): void {
		$shop = $this->with(
			array(
				'mon' => array(
					'open'  => '10:00',
					'close' => '10:00',
				),
				'tue' => array(
					'open'  => '10:00',
					'close' => '20:00',
				),
			)
		);

		$this->assertSame( Showroom::STATE_UNAVAILABLE, $shop->state_at( $this->at_time( '2026-10-05 10:00' ) ) );
		$this->assertSame( Showroom::STATE_UNAVAILABLE, $shop->state_at( $this->at_time( '2026-10-05 15:00' ) ) );
		$this->assertSame( Showroom::STATE_CLOSED, $shop->state_at( $this->at_time( '2026-10-06 00:30' ) ) );
	}

	/**
	 * Unusable stored days never throw and never invent hours; other days still work.
	 */
	public function test_malformed_timings_are_unavailable(): void {
		$bad = array(
			'25:00 hour'       => array(
				'open'  => '25:00',
				'close' => '20:00',
			),
			'unpadded'         => array(
				'open'  => '9:00',
				'close' => '20:00',
			),
			'missing close'    => array( 'open' => '10:00' ),
			'not an array'     => 'open all day',
			'string closed'    => array( 'closed' => 'yes' ),
			'false closed'     => array( 'closed' => false ),
			'non-string times' => array(
				'open'  => 10,
				'close' => 20,
			),
			'empty entry'      => array(),
		);

		foreach ( $bad as $label => $entry ) {
			$shop = $this->with(
				array(
					'mon' => $entry,
					'tue' => array(
						'open'  => '10:00',
						'close' => '20:00',
					),
				)
			);

			$this->assertSame( Showroom::STATE_UNAVAILABLE, $shop->state_at( $this->at_time( '2026-10-05 12:00' ) ), $label );
			$this->assertSame( Showroom::STATE_OPEN, $shop->state_at( $this->at_time( '2026-10-06 12:00' ) ), $label );
		}
	}

	/**
	 * The same instant gives the answer for the timezone it is read in.
	 */
	public function test_timezone_decides_the_answer(): void {
		$shop  = $this->make();
		$local = $this->at_time( '2026-10-05 10:00', 'Asia/Kolkata' );
		$utc   = $local->setTimezone( new \DateTimeZone( 'UTC' ) );

		$this->assertSame( Showroom::STATE_OPEN, $shop->state_at( $local ) );
		$this->assertSame( Showroom::STATE_CLOSED, $shop->state_at( $utc ) );
		$this->assertSame( $local->getTimestamp(), $utc->getTimestamp() );
	}

	/**
	 * Evaluating does not change the showroom, and gives the same answer again.
	 */
	public function test_evaluation_is_pure(): void {
		$shop   = $this->make();
		$before = $shop->timings;
		$first  = $shop->state_at( $this->at_time( '2026-10-05 12:00' ) );

		$this->assertSame( $first, $shop->state_at( $this->at_time( '2026-10-05 12:00' ) ) );
		$this->assertSame( $before, $shop->timings );
	}
}
