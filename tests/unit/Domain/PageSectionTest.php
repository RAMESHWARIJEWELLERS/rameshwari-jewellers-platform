<?php
/**
 * Page section model tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use Rameshwari\Core\Domain\PageSection\HeroSelection;
use Rameshwari\Core\Domain\PageSection\PageSection;

/**
 * Eligibility, the schedule window, ordering and the cache form of a section.
 */
final class PageSectionTest extends TestCase {

	/**
	 * A section with named overrides.
	 *
	 * @param array<string,mixed> $over Field overrides.
	 * @return PageSection
	 */
	private function section( array $over = array() ): PageSection {
		return new PageSection(
			...array_merge(
				array(
					'id'            => 7,
					'status'        => 'publish',
					'section_key'   => 'hero',
					'title_hi'      => 'शीर्षक',
					'title_en'      => 'Title',
					'description'   => 'Text',
					'cta_label'     => 'Shop',
					'cta_url'       => '/c/wedding/',
					'image_desktop' => 21,
					'image_mobile'  => 22,
					'active'        => true,
					'starts_at'     => '',
					'ends_at'       => '',
					'order'         => 10,
				),
				$over
			)
		);
	}

	/**
	 * A moment in a zone.
	 *
	 * @param string $time Y-m-d H:i:s.
	 * @param string $zone Timezone.
	 * @return \DateTimeImmutable
	 */
	private function moment( string $time, string $zone = 'Asia/Kolkata' ): \DateTimeImmutable {
		return new \DateTimeImmutable( $time, new \DateTimeZone( $zone ) );
	}

	/**
	 * A section with both dates empty is always eligible, with or without images.
	 */
	public function test_evergreen_is_eligible_and_image_is_irrelevant(): void {
		$now = $this->moment( '2026-10-06 12:00:00' );

		$this->assertTrue( $this->section()->is_eligible( $now ) );
		$this->assertTrue(
			$this->section(
				array(
					'image_desktop' => 0,
					'image_mobile'  => 0,
				)
			)->is_eligible( $now )
		);
	}

	/**
	 * Start is inclusive and end is exclusive, to the second.
	 */
	public function test_window_boundaries(): void {
		$s = $this->section(
			array(
				'starts_at' => '2026-10-06 10:00:00',
				'ends_at'   => '2026-10-06 14:00:00',
			)
		);

		$this->assertFalse( $s->is_eligible( $this->moment( '2026-10-06 09:59:59' ) ) );
		$this->assertTrue( $s->is_eligible( $this->moment( '2026-10-06 10:00:00' ) ) );
		$this->assertTrue( $s->is_eligible( $this->moment( '2026-10-06 12:00:00' ) ) );
		$this->assertTrue( $s->is_eligible( $this->moment( '2026-10-06 13:59:59' ) ) );
		$this->assertFalse( $s->is_eligible( $this->moment( '2026-10-06 14:00:00' ) ) );
		$this->assertFalse( $s->is_eligible( $this->moment( '2026-10-06 14:00:01' ) ) );
	}

	/**
	 * An empty start or end is open-ended.
	 */
	public function test_open_ended_windows(): void {
		$now = $this->moment( '2026-10-06 12:00:00' );

		$this->assertTrue(
			$this->section(
				array(
					'starts_at' => '2026-10-06 12:00:00',
				)
			)->is_eligible( $now )
		);
		$this->assertFalse(
			$this->section(
				array(
					'starts_at' => '2026-10-06 12:00:01',
				)
			)->is_eligible( $now )
		);
		$this->assertTrue(
			$this->section(
				array(
					'ends_at' => '2026-10-06 12:00:01',
				)
			)->is_eligible( $now )
		);
		$this->assertFalse(
			$this->section(
				array(
					'ends_at' => '2026-10-06 12:00:00',
				)
			)->is_eligible( $now )
		);
	}

	/**
	 * An end that is not after the start is invalid and fails closed.
	 */
	public function test_reversed_or_empty_window_is_ineligible(): void {
		$now = $this->moment( '2026-10-06 12:00:00' );

		$this->assertFalse(
			$this->section(
				array(
					'starts_at' => '2026-10-06 10:00:00',
					'ends_at'   => '2026-10-06 10:00:00',
				)
			)->is_eligible( $now )
		);
		$this->assertFalse(
			$this->section(
				array(
					'starts_at' => '2026-10-06 11:00:00',
					'ends_at'   => '2026-10-06 10:00:00',
				)
			)->is_eligible( $now )
		);
		$this->assertFalse(
			$this->section(
				array(
					'starts_at' => '2026-10-06 11:00:00',
					'ends_at'   => '2026-10-06 10:00:00',
				)
			)->has_valid_window( new \DateTimeZone( 'UTC' ) )
		);
	}

	/**
	 * A malformed non-empty date is never read as empty.
	 */
	public function test_malformed_dates_are_ineligible(): void {
		$now = $this->moment( '2026-10-06 12:00:00' );

		foreach ( array( 'tomorrow', '2026-10-06', '2026-02-31 00:00:00', '2026-10-06T10:00:00' ) as $bad ) {
			$this->assertFalse(
				$this->section(
					array(
						'starts_at' => $bad,
					)
				)->is_eligible( $now ),
				$bad
			);
			$this->assertFalse(
				$this->section(
					array(
						'ends_at' => $bad,
					)
				)->is_eligible( $now ),
				$bad
			);
			$this->assertFalse(
				$this->section(
					array(
						'ends_at' => $bad,
					)
				)->has_valid_window( $now->getTimezone() ),
				$bad
			);
		}
	}

	/**
	 * Only a published, switched-on section with a valid key is eligible.
	 */
	public function test_status_active_and_key(): void {
		$now = $this->moment( '2026-10-06 12:00:00' );

		foreach ( array( 'draft', 'private', 'pending', 'trash', 'future', 'auto-draft', 'inherit' ) as $status ) {
			$this->assertFalse(
				$this->section(
					array(
						'status' => $status,
					)
				)->is_eligible( $now ),
				$status
			);
		}

		$this->assertFalse(
			$this->section(
				array(
					'active' => false,
				)
			)->is_eligible( $now )
		);

		foreach ( PageSection::KEYS as $key ) {
			$this->assertTrue(
				$this->section(
					array(
						'section_key' => $key,
					)
				)->is_eligible( $now ),
				$key
			);
		}

		foreach ( array( 'sidebar', '', 'Hero' ) as $key ) {
			$this->assertFalse(
				$this->section(
					array(
						'section_key' => $key,
					)
				)->is_eligible( $now ),
				$key
			);
		}
	}

	/**
	 * Hero candidacy is key, status and switch, and ignores the schedule.
	 */
	public function test_hero_candidate(): void {
		$this->assertTrue(
			$this->section(
				array(
					'starts_at' => '2030-01-01 00:00:00',
				)
			)->is_hero_candidate()
		);
		$this->assertFalse(
			$this->section(
				array(
					'section_key' => 'about',
				)
			)->is_hero_candidate()
		);
		$this->assertFalse(
			$this->section(
				array(
					'active' => false,
				)
			)->is_hero_candidate()
		);
		$this->assertFalse(
			$this->section(
				array(
					'status' => 'draft',
				)
			)->is_hero_candidate()
		);
	}

	/**
	 * Dates are read in the zone of the clock given: one instant, two answers.
	 */
	public function test_dates_are_read_in_the_clock_timezone(): void {
		$s       = $this->section(
			array(
				'starts_at' => '2026-10-06 17:00:00',
				'ends_at'   => '2026-10-06 18:00:00',
			)
		);
		$utc     = $this->moment( '2026-10-06 12:00:00', 'UTC' );
		$kolkata = $utc->setTimezone( new \DateTimeZone( 'Asia/Kolkata' ) );

		$this->assertSame( $utc->getTimestamp(), $kolkata->getTimestamp() );
		$this->assertFalse( $s->is_eligible( $utc ) );
		$this->assertTrue( $s->is_eligible( $kolkata ) );
	}

	/**
	 * The next boundary is the nearest future start or end.
	 */
	public function test_next_boundary(): void {
		$now   = $this->moment( '2026-10-06 12:00:00' );
		$later = $this->moment( '2026-10-06 12:30:00' )->getTimestamp();
		$end   = $this->moment( '2026-10-06 13:00:00' )->getTimestamp();

		$this->assertNull( $this->section()->next_boundary( $now ) );
		$this->assertSame(
			$later,
			$this->section(
				array(
					'starts_at' => '2026-10-06 12:30:00',
				)
			)->next_boundary( $now )
		);
		$this->assertSame(
			$end,
			$this->section(
				array(
					'ends_at' => '2026-10-06 13:00:00',
				)
			)->next_boundary( $now )
		);
		$this->assertSame(
			$later,
			$this->section(
				array(
					'starts_at' => '2026-10-06 12:30:00',
					'ends_at'   => '2026-10-06 13:00:00',
				)
			)->next_boundary( $now )
		);
		$this->assertSame(
			$end,
			$this->section(
				array(
					'starts_at' => '2026-10-06 11:00:00',
					'ends_at'   => '2026-10-06 13:00:00',
				)
			)->next_boundary( $now )
		);
		$this->assertNull(
			$this->section(
				array(
					'starts_at' => '2026-10-06 10:00:00',
					'ends_at'   => '2026-10-06 11:00:00',
				)
			)->next_boundary( $now )
		);
		$this->assertNull(
			$this->section(
				array(
					'ends_at' => 'garbage',
				)
			)->next_boundary( $now )
		);
	}

	/**
	 * Valid orders keep their value; anything else is invalid and sorts after them.
	 */
	public function test_order_validation_and_sort_key(): void {
		$this->assertSame( 0, PageSection::order_from( 0 ) );
		$this->assertSame( 9999, PageSection::order_from( '9999' ) );
		$this->assertSame( 42, PageSection::order_from( '42' ) );

		foreach ( array( -1, '-5', 10000, '10000', 'abc', 3.5, null, '', array() ) as $bad ) {
			$this->assertNull( PageSection::order_from( $bad ), gettype( $bad ) );
		}

		$valid_last  = $this->section(
			array(
				'id'    => 100,
				'order' => 9999,
			)
		)->sort_key();
		$invalid     = $this->section(
			array(
				'id'    => 2,
				'order' => null,
			)
		)->sort_key();
		$tie_low_id  = $this->section(
			array(
				'id'    => 3,
				'order' => 5,
			)
		)->sort_key();
		$tie_high_id = $this->section(
			array(
				'id'    => 4,
				'order' => 5,
			)
		)->sort_key();

		$this->assertTrue( $valid_last < $invalid );
		$this->assertTrue( $tie_low_id < $tie_high_id );
	}

	/**
	 * Impossible IDs, statuses and attachment IDs are refused.
	 */
	public function test_invalid_construction(): void {
		foreach ( array(
			array(
				'id' => 0,
			),
			array(
				'status' => '',
			),
			array(
				'image_desktop' => -1,
			),
			array(
				'image_mobile' => -1,
			),
		) as $over ) {
			try {
				$this->section( $over );

				$this->fail( 'Accepted a bad value for ' . implode( ',', array_keys( $over ) ) );
			} catch ( \InvalidArgumentException $expected ) {
				$this->assertNotSame( '', $expected->getMessage() );
			}
		}
	}

	/**
	 * The cache form round-trips and refuses damaged data.
	 */
	public function test_array_round_trip(): void {
		$s = $this->section(
			array(
				'order'     => null,
				'starts_at' => '2026-10-06 10:00:00',
			)
		);

		$this->assertEquals( $s, PageSection::from_array( $s->to_array() ) );

		$bad = $s->to_array();
		unset( $bad['status'] );

		foreach ( array(
			$bad,
			array(),
			array_merge(
				$s->to_array(),
				array(
					'active' => 'yes',
				)
			),
			array_merge(
				$s->to_array(),
				array(
					'order' => '5',
				)
			),
			array_merge(
				$s->to_array(),
				array(
					'title_en' => 5,
				)
			),
		) as $broken ) {
			try {
				PageSection::from_array( $broken );

				$this->fail( 'Accepted damaged data.' );
			} catch ( \InvalidArgumentException $expected ) {
				$this->assertNotSame( '', $expected->getMessage() );
			}
		}
	}

	/**
	 * The selection shows all on desktop and the first on mobile; empty is explicit.
	 */
	public function test_hero_selection(): void {
		$a   = $this->section(
			array(
				'id' => 1,
			)
		);
		$b   = $this->section(
			array(
				'id' => 2,
			)
		);
		$sel = new HeroSelection(
			array(
				4 => $a,
				9 => $b,
			),
			500
		);

		$this->assertSame( array( $a, $b ), $sel->desktop() );
		$this->assertSame( $a, $sel->mobile() );
		$this->assertSame( array( $a ), $sel->for_context( true ) );
		$this->assertSame( array( $a, $b ), $sel->for_context( false ) );
		$this->assertSame( 500, $sel->valid_until() );
		$this->assertFalse( $sel->is_empty() );

		$none = new HeroSelection( array(), 10 );

		$this->assertTrue( $none->is_empty() );
		$this->assertNull( $none->mobile() );
		$this->assertSame( array(), $none->for_context( true ) );
		$this->assertSame( array(), $none->for_context( false ) );
	}
}
