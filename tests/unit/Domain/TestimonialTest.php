<?php
/**
 * Testimonial model tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use Rameshwari\Core\Domain\Testimonial\Testimonial;

/**
 * The testimonial contract: author, rating, photo, context, source and order.
 */
final class TestimonialTest extends TestCase {

	/**
	 * A testimonial with named overrides.
	 *
	 * @param array<string,mixed> $over Field overrides.
	 * @return Testimonial
	 */
	private function make( array $over = array() ): Testimonial {
		return new Testimonial(
			...array_merge(
				array(
					'id'       => 13,
					'status'   => 'publish',
					'author'   => 'Meena',
					'rating'   => 5,
					'photo_id' => 40,
					'context'  => 'Wedding purchase',
					'source'   => 'In store',
					'order'    => 2,
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
		$item = $this->make();

		$this->assertSame( 13, $item->id );
		$this->assertSame( 'Meena', $item->author );
		$this->assertSame( 5, $item->rating );
		$this->assertSame( 40, $item->photo_id );
		$this->assertSame( 'Wedding purchase', $item->context );
		$this->assertSame( 'In store', $item->source );
		$this->assertSame( 2, $item->order );
		$this->assertTrue( $item->is_rated() );
		$this->assertTrue( $item->has_photo() );
	}

	/**
	 * Zero means no rating and no photo.
	 */
	public function test_unrated_and_photoless(): void {
		$item = $this->make(
			array(
				'rating'   => 0,
				'photo_id' => 0,
			)
		);

		$this->assertFalse( $item->is_rated() );
		$this->assertFalse( $item->has_photo() );
	}

	/**
	 * Rating, author and order accept their limits and refuse beyond.
	 */
	public function test_limits(): void {
		$this->assertSame( 0, $this->make( array( 'rating' => 0 ) )->rating );
		$this->assertSame( 5, $this->make( array( 'rating' => 5 ) )->rating );
		$this->assertSame( 9999, $this->make( array( 'order' => 9999 ) )->order );
		$this->assertSame( 120, mb_strlen( $this->make( array( 'author' => str_repeat( 'a', 120 ) ) )->author ) );

		$this->assert_all_rejected(
			array(
				array( 'rating' => 6 ),
				array( 'rating' => -1 ),
				array( 'order' => 10000 ),
				array( 'author' => str_repeat( 'a', 121 ) ),
				array( 'photo_id' => -1 ),
				array( 'id' => 0 ),
				array( 'status' => '' ),
			)
		);
	}

	/**
	 * Hindi author names are counted as characters.
	 */
	public function test_hindi_author_survives(): void {
		$this->assertSame( 'मीना शर्मा', $this->make( array( 'author' => 'मीना शर्मा' ) )->author );
	}
}
