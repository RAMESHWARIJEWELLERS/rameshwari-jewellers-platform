<?php
/**
 * Regeneration result tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Unit\Media;

use PHPUnit\Framework\TestCase;
use Rameshwari\Core\Services\Media\Value\RegenerationResult;

/**
 * The value type carries counts, a cursor and failures, and nothing is stored.
 */
final class RegenerationResultTest extends TestCase {

	/**
	 * Counts and the cursor are exposed and processed is their sum.
	 */
	public function test_counts_and_processed_total(): void {
		$result = new RegenerationResult( 40, 2, 3, array( 7 => 'source_missing' ), false );

		$this->assertSame( 40, $result->cursor() );
		$this->assertSame( 2, $result->regenerated() );
		$this->assertSame( 3, $result->skipped() );
		$this->assertSame( 1, $result->failed() );
		$this->assertSame( 6, $result->processed() );
		$this->assertSame( array( 7 => 'source_missing' ), $result->failures() );
		$this->assertFalse( $result->is_done() );
	}

	/**
	 * An empty batch is valid.
	 */
	public function test_empty_batch_is_valid(): void {
		$result = new RegenerationResult( 0, 0, 0, array(), true );

		$this->assertSame( 0, $result->processed() );
		$this->assertTrue( $result->is_done() );
	}

	/**
	 * Negative numbers are refused.
	 */
	public function test_negative_numbers_are_rejected(): void {
		foreach ( array( array( -1, 0, 0 ), array( 0, -1, 0 ), array( 0, 0, -1 ) ) as $args ) {
			try {
				new RegenerationResult( $args[0], $args[1], $args[2], array(), false );

				$this->fail( 'A negative number was accepted.' );
			} catch ( \InvalidArgumentException $expected ) {
				$this->assertNotSame( '', $expected->getMessage() );
			}
		}
	}

	/**
	 * A failure needs a positive ID and a code.
	 */
	public function test_malformed_failures_are_rejected(): void {
		foreach ( array( array( 0 => 'x' ), array( 5 => '  ' ) ) as $failures ) {
			try {
				new RegenerationResult( 1, 0, 0, $failures, false );

				$this->fail( 'A malformed failure was accepted.' );
			} catch ( \InvalidArgumentException $expected ) {
				$this->assertNotSame( '', $expected->getMessage() );
			}
		}
	}

	/**
	 * Nothing can be changed after construction.
	 */
	public function test_result_is_immutable(): void {
		foreach ( ( new \ReflectionClass( RegenerationResult::class ) )->getProperties() as $property ) {
			$this->assertTrue( $property->isReadOnly(), $property->getName() );
		}
	}
}
