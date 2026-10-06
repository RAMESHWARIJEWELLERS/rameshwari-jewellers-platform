<?php
/**
 * Collection model tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use Rameshwari\Core\Domain\Collection\Collection;

/**
 * The collection contract: cover, badge, order and the automatic rule.
 */
final class CollectionTest extends TestCase {

	/**
	 * A collection with named overrides.
	 *
	 * @param array<string,mixed> $over Field overrides.
	 * @return Collection
	 */
	private function make( array $over = array() ): Collection {
		return new Collection(
			...array_merge(
				array(
					'id'        => 9,
					'status'    => 'publish',
					'cover_id'  => 30,
					'badge'     => 'New',
					'order'     => 3,
					'auto_rule' => array(),
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
		$collection = $this->make();

		$this->assertSame( 9, $collection->id );
		$this->assertSame( 'publish', $collection->status );
		$this->assertSame( 30, $collection->cover_id );
		$this->assertSame( 'New', $collection->badge );
		$this->assertSame( 3, $collection->order );
		$this->assertTrue( $collection->has_cover() );
		$this->assertFalse( $collection->is_automatic() );
	}

	/**
	 * A rule makes the collection automatic; no cover is allowed.
	 */
	public function test_auto_rule_and_missing_cover(): void {
		$collection = $this->make(
			array(
				'cover_id'  => 0,
				'auto_rule' => array( 'metal' => 'gold' ),
			)
		);

		$this->assertTrue( $collection->is_automatic() );
		$this->assertFalse( $collection->has_cover() );
		$this->assertSame( array( 'metal' => 'gold' ), $collection->auto_rule );
	}

	/**
	 * Order and badge accept their limits and refuse beyond.
	 */
	public function test_order_and_badge_limits(): void {
		$this->assertSame( 0, $this->make( array( 'order' => 0 ) )->order );
		$this->assertSame( 9999, $this->make( array( 'order' => 9999 ) )->order );
		$this->assertSame( 24, mb_strlen( $this->make( array( 'badge' => str_repeat( 'a', 24 ) ) )->badge ) );

		$this->assert_all_rejected(
			array(
				array( 'order' => -1 ),
				array( 'order' => 10000 ),
				array( 'badge' => str_repeat( 'a', 25 ) ),
			)
		);
	}

	/**
	 * A badge counts characters, not bytes, so Hindi text is not cut short.
	 */
	public function test_badge_counts_characters(): void {
		$this->assertSame( 'नई कलेक्शन', $this->make( array( 'badge' => 'नई कलेक्शन' ) )->badge );
	}

	/**
	 * Identity, status and cover are checked.
	 */
	public function test_invalid_fields_are_rejected(): void {
		$this->assert_all_rejected(
			array(
				array( 'id' => 0 ),
				array( 'status' => '' ),
				array( 'cover_id' => -1 ),
			)
		);
	}

	/**
	 * Lifecycle states beyond draft and published are not stored, so none is derived.
	 */
	public function test_model_derives_no_extra_lifecycle_state(): void {
		$this->assertNotContains( 'state', get_class_methods( Collection::class ) );
		$this->assertNotContains( 'is_retired', get_class_methods( Collection::class ) );
	}
}
