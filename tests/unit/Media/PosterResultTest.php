<?php
/**
 * Poster result tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Unit\Media;

use PHPUnit\Framework\TestCase;
use Rameshwari\Core\Services\Media\Value\PosterResult;

/**
 * Success carries an attachment; failure carries a code and never blocks anything.
 */
final class PosterResultTest extends TestCase {

	/**
	 * A received poster.
	 */
	public function test_success_carries_the_attachment(): void {
		$result = PosterResult::succeeded( 42 );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( 42, $result->attachment_id() );
		$this->assertSame( '', $result->code() );
	}

	/**
	 * A poster that was not received.
	 */
	public function test_failure_carries_a_code_and_no_attachment(): void {
		$result = PosterResult::failed( 'no_poster' );

		$this->assertFalse( $result->is_success() );
		$this->assertSame( 0, $result->attachment_id() );
		$this->assertSame( 'no_poster', $result->code() );
	}

	/**
	 * Bad construction is refused.
	 */
	public function test_invalid_construction_is_rejected(): void {
		foreach ( array( 0, -3 ) as $id ) {
			try {
				PosterResult::succeeded( $id );

				$this->fail( 'A non-positive ID was accepted.' );
			} catch ( \InvalidArgumentException $expected ) {
				$this->assertNotSame( '', $expected->getMessage() );
			}
		}

		$this->expectException( \InvalidArgumentException::class );

		PosterResult::failed( '' );
	}

	/**
	 * Nothing can be changed after construction.
	 */
	public function test_result_is_immutable(): void {
		foreach ( ( new \ReflectionClass( PosterResult::class ) )->getProperties() as $property ) {
			$this->assertTrue( $property->isReadOnly(), $property->getName() );
		}
	}
}
