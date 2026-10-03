<?php
/**
 * Source quality result tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Unit\Media;

use PHPUnit\Framework\TestCase;
use Rameshwari\Core\Services\Media\Value\SourceQualityResult;

/**
 * The advisory result: adequate, or a warning that needs confirmation.
 */
final class SourceQualityResultTest extends TestCase {

	/**
	 * An adequate source needs no confirmation and names no size.
	 */
	public function test_adequate_result(): void {
		$result = SourceQualityResult::adequate();

		$this->assertTrue( $result->is_adequate() );
		$this->assertFalse( $result->requires_confirmation() );
		$this->assertSame( array(), $result->sizes() );
	}

	/**
	 * A warning requires confirmation and is not adequate.
	 */
	public function test_warning_requires_confirmation(): void {
		$result = SourceQualityResult::warning( array( 'hero' ) );

		$this->assertFalse( $result->is_adequate() );
		$this->assertTrue( $result->requires_confirmation() );
	}

	/**
	 * The affected contract names are kept, in order, without repeats.
	 */
	public function test_affected_sizes_are_preserved(): void {
		$result = SourceQualityResult::warning( array( 'hero', 'card', 'hero' ) );

		$this->assertSame( array( 'hero', 'card' ), $result->sizes() );
	}

	/**
	 * A warning with no size would be meaningless.
	 */
	public function test_warning_without_sizes_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		SourceQualityResult::warning( array() );
	}

	/**
	 * A blank size name is refused.
	 */
	public function test_blank_size_name_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		SourceQualityResult::warning( array( 'card', '' ) );
	}

	/**
	 * Results compare by value.
	 */
	public function test_value_equality(): void {
		$this->assertTrue( SourceQualityResult::adequate()->equals( SourceQualityResult::adequate() ) );
		$this->assertTrue( SourceQualityResult::warning( array( 'card' ) )->equals( SourceQualityResult::warning( array( 'card' ) ) ) );
		$this->assertFalse( SourceQualityResult::warning( array( 'card' ) )->equals( SourceQualityResult::warning( array( 'hero' ) ) ) );
		$this->assertFalse( SourceQualityResult::adequate()->equals( SourceQualityResult::warning( array( 'card' ) ) ) );
	}

	/**
	 * The object cannot be changed after construction.
	 */
	public function test_result_is_immutable(): void {
		$result  = SourceQualityResult::warning( array( 'card' ) );
		$sizes   = $result->sizes();
		$sizes[] = 'hero';

		$this->assertSame( array( 'card' ), $result->sizes() );

		foreach ( ( new \ReflectionClass( $result ) )->getProperties() as $property ) {
			$this->assertTrue( $property->isReadOnly(), $property->getName() );
		}
	}
}
