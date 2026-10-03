<?php
/**
 * ValidationResult tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Unit\Media;

use PHPUnit\Framework\TestCase;
use Rameshwari\Core\Services\Media\Value\ValidationResult;

/**
 * ValidationResult invariants.
 */
final class ValidationResultTest extends TestCase {

	/**
	 * A pass has no code.
	 */
	public function test_passed_has_no_code(): void {
		$result = ValidationResult::passed();

		$this->assertTrue( $result->is_valid() );
		$this->assertSame( '', $result->code() );
	}

	/**
	 * A failure keeps its stable code.
	 */
	public function test_failed_keeps_its_code(): void {
		$result = ValidationResult::failed( 'file_too_large' );

		$this->assertFalse( $result->is_valid() );
		$this->assertSame( 'file_too_large', $result->code() );
	}

	/**
	 * A failure must carry a non-empty code; the code's syntax is not frozen.
	 */
	public function test_failed_requires_a_non_empty_code(): void {
		$this->expectException( \InvalidArgumentException::class );

		ValidationResult::failed( '' );
	}

	/**
	 * Any non-empty code is kept exactly as given.
	 */
	public function test_failed_keeps_any_non_empty_code_verbatim(): void {
		foreach ( array( 'file_too_large', 'File.Too-Large', '413', 'svg rejected' ) as $code ) {
			$this->assertSame( $code, ValidationResult::failed( $code )->code() );
		}
	}

	/**
	 * Equality is by value.
	 */
	public function test_equality_is_by_value(): void {
		$this->assertTrue( ValidationResult::passed()->equals( ValidationResult::passed() ) );
		$this->assertTrue( ValidationResult::failed( 'svg_rejected' )->equals( ValidationResult::failed( 'svg_rejected' ) ) );
		$this->assertFalse( ValidationResult::failed( 'svg_rejected' )->equals( ValidationResult::failed( 'file_missing' ) ) );
		$this->assertFalse( ValidationResult::passed()->equals( ValidationResult::failed( 'file_missing' ) ) );
	}

	/**
	 * The class is final and every property is read-only.
	 */
	public function test_is_immutable(): void {
		$class = new \ReflectionClass( ValidationResult::class );

		$this->assertTrue( $class->isFinal() );

		foreach ( $class->getProperties() as $property ) {
			$this->assertTrue( $property->isReadOnly(), $property->getName() );
		}
	}
}
