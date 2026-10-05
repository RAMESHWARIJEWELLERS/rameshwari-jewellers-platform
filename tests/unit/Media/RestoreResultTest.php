<?php
/**
 * Restore result tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Unit\Media;

use PHPUnit\Framework\TestCase;
use Rameshwari\Core\Services\Media\Value\RestoreResult;

/**
 * A restore result is either a success with its details or a failure with a code.
 */
final class RestoreResultTest extends TestCase {

	/**
	 * A success carries dimensions and both artifact names.
	 */
	public function test_success_carries_its_details(): void {
		$result = RestoreResult::succeeded( 300, 200, 'a.png', 'b.png' );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( '', $result->code() );
		$this->assertSame( array( 300, 200 ), array( $result->width(), $result->height() ) );
		$this->assertSame( 'a.png', $result->restored() );
		$this->assertSame( 'b.png', $result->undo_artifact() );
	}

	/**
	 * A failure carries a code and nothing else.
	 */
	public function test_failure_carries_only_a_code(): void {
		$result = RestoreResult::failed( 'artifact_not_found' );

		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'artifact_not_found', $result->code() );
		$this->assertSame( array( 0, 0 ), array( $result->width(), $result->height() ) );
		$this->assertSame( '', $result->restored() );
		$this->assertSame( '', $result->undo_artifact() );
	}

	/**
	 * Impossible results cannot be built.
	 */
	public function test_invalid_results_are_rejected(): void {
		$builders = array(
			static fn() => RestoreResult::failed( '  ' ),
			static fn() => RestoreResult::succeeded( 0, 10, 'a', 'b' ),
			static fn() => RestoreResult::succeeded( 10, 0, 'a', 'b' ),
			static fn() => RestoreResult::succeeded( 10, 10, '', 'b' ),
			static fn() => RestoreResult::succeeded( 10, 10, 'a', ' ' ),
		);

		foreach ( $builders as $build ) {
			try {
				$build();

				$this->fail( 'An invalid result was accepted.' );
			} catch ( \InvalidArgumentException $expected ) {
				$this->assertNotSame( '', $expected->getMessage() );
			}
		}
	}

	/**
	 * The constructor is private, so only the two named builders exist.
	 */
	public function test_only_named_builders_exist(): void {
		$this->assertTrue( ( new \ReflectionClass( RestoreResult::class ) )->getConstructor()->isPrivate() );
	}
}
