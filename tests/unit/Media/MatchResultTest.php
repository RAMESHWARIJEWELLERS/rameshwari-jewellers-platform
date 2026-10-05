<?php
/**
 * Match result tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Unit\Media;

use PHPUnit\Framework\TestCase;
use Rameshwari\Core\Services\Media\Value\MatchResult;

/**
 * The value type holds zero, one or several candidates and decides nothing.
 */
final class MatchResultTest extends TestCase {

	/**
	 * No candidate: empty, not ambiguous, no suggestion.
	 */
	public function test_none_has_no_suggestion(): void {
		$result = MatchResult::none();

		$this->assertTrue( $result->is_empty() );
		$this->assertFalse( $result->is_ambiguous() );
		$this->assertNull( $result->suggestion() );
		$this->assertSame( 0, $result->count() );
	}

	/**
	 * One candidate is a suggestion.
	 */
	public function test_single_candidate_is_the_suggestion(): void {
		$result = new MatchResult( array( 'RJ-1' ) );

		$this->assertSame( 'RJ-1', $result->suggestion() );
		$this->assertFalse( $result->is_ambiguous() );
		$this->assertFalse( $result->is_empty() );
	}

	/**
	 * Several candidates are ambiguous and give no single suggestion.
	 */
	public function test_several_candidates_are_ambiguous(): void {
		$result = new MatchResult( array( 'RJ-1', 'RJ-2' ) );

		$this->assertTrue( $result->is_ambiguous() );
		$this->assertNull( $result->suggestion() );
		$this->assertSame( array( 'RJ-1', 'RJ-2' ), $result->candidates() );
	}

	/**
	 * Repeats are dropped keeping the first, and order is kept.
	 */
	public function test_repeats_are_dropped_in_order(): void {
		$this->assertSame( array( 'B', 'A' ), ( new MatchResult( array( 'B', 'A', 'B' ) ) )->candidates() );
	}

	/**
	 * A blank code is refused.
	 */
	public function test_blank_code_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );

		new MatchResult( array( 'RJ-1', '  ' ) );
	}

	/**
	 * Equality compares the candidates.
	 */
	public function test_equality_is_by_value(): void {
		$this->assertTrue( ( new MatchResult( array( 'A', 'B' ) ) )->equals( new MatchResult( array( 'A', 'B', 'A' ) ) ) );
		$this->assertFalse( ( new MatchResult( array( 'A' ) ) )->equals( new MatchResult( array( 'B' ) ) ) );
	}

	/**
	 * The type cannot be changed after construction.
	 */
	public function test_result_is_immutable(): void {
		$property = ( new \ReflectionClass( MatchResult::class ) )->getProperty( 'candidates' );

		$this->assertTrue( $property->isReadOnly() );
	}
}
