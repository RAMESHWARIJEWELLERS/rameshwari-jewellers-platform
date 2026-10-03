<?php
/**
 * ReplaceResult tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Unit\Media;

use PHPUnit\Framework\TestCase;
use Rameshwari\Core\Services\Media\Value\ReplaceResult;

/**
 * ReplaceResult invariants.
 */
final class ReplaceResultTest extends TestCase {

	/**
	 * Success carries dimensions and the artifact name.
	 */
	public function test_success_carries_dimensions_and_artifact(): void {
		$result = ReplaceResult::succeeded( 1200, 1500, '42-1700000000.jpg' );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( 1200, $result->width() );
		$this->assertSame( 1500, $result->height() );
		$this->assertSame( '42-1700000000.jpg', $result->artifact() );
		$this->assertSame( '', $result->code() );
	}

	/**
	 * Failure carries only a code.
	 */
	public function test_failure_carries_only_a_code(): void {
		$result = ReplaceResult::failed( 'locked' );

		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'locked', $result->code() );
		$this->assertSame( 0, $result->width() );
		$this->assertSame( 0, $result->height() );
		$this->assertSame( '', $result->artifact() );
	}

	/**
	 * Bad dimensions are rejected.
	 */
	public function test_rejects_non_positive_dimensions(): void {
		foreach ( array( array( 0, 10 ), array( 10, 0 ), array( -1, 10 ) ) as $dims ) {
			try {
				ReplaceResult::succeeded( $dims[0], $dims[1], 'a.jpg' );
				$this->fail( 'Accepted: ' . $dims[0] . 'x' . $dims[1] );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
	}

	/**
	 * The artifact must be a plain file name, never a path.
	 */
	public function test_rejects_artifacts_that_are_paths(): void {
		foreach ( array( '', '../a.jpg', 'dir/a.jpg', 'dir\\a.jpg', '..' ) as $bad ) {
			try {
				ReplaceResult::succeeded( 10, 10, $bad );
				$this->fail( 'Accepted: ' . $bad );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
	}

	/**
	 * A failure must carry a non-empty code; the code's syntax is not frozen.
	 */
	public function test_failure_requires_a_non_empty_code(): void {
		$this->expectException( \InvalidArgumentException::class );

		ReplaceResult::failed( '' );
	}

	/**
	 * Any non-empty code is kept exactly as given.
	 */
	public function test_failure_keeps_any_non_empty_code_verbatim(): void {
		foreach ( array( 'locked', 'Not-Found.1', '500' ) as $code ) {
			$this->assertSame( $code, ReplaceResult::failed( $code )->code() );
		}
	}

	/**
	 * The class is final and every property is read-only.
	 */
	public function test_is_immutable(): void {
		$class = new \ReflectionClass( ReplaceResult::class );

		$this->assertTrue( $class->isFinal() );

		foreach ( $class->getProperties() as $property ) {
			$this->assertTrue( $property->isReadOnly(), $property->getName() );
		}
	}
}
