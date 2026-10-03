<?php
/**
 * LoadingHint tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Unit\Media;

use PHPUnit\Framework\TestCase;
use Rameshwari\Core\Services\Media\Value\LoadingHint;

/**
 * LoadingHint invariants.
 */
final class LoadingHintTest extends TestCase {

	/**
	 * The three attributes are kept.
	 */
	public function test_keeps_its_attributes(): void {
		$hint = new LoadingHint( 'eager', 'high', 'async' );

		$this->assertSame( 'eager', $hint->loading() );
		$this->assertSame( 'high', $hint->fetchpriority() );
		$this->assertSame( 'async', $hint->decoding() );
	}

	/**
	 * Every allowed value is accepted.
	 */
	public function test_accepts_every_allowed_value(): void {
		foreach ( array( 'eager', 'lazy' ) as $loading ) {
			foreach ( array( 'high', 'low', 'auto' ) as $priority ) {
				foreach ( array( 'async', 'sync', 'auto' ) as $decoding ) {
					$hint = new LoadingHint( $loading, $priority, $decoding );

					$this->assertSame( $loading, $hint->loading() );
				}
			}
		}
	}

	/**
	 * Any other value is rejected.
	 */
	public function test_rejects_values_that_are_not_html_attribute_values(): void {
		foreach (
			array(
				array( 'Eager', 'high', 'async' ),
				array( 'lazy', 'highest', 'async' ),
				array( 'lazy', 'low', 'later' ),
				array( '', 'low', 'async' ),
			) as $args
		) {
			try {
				new LoadingHint( ...$args );
				$this->fail( 'Accepted: ' . implode( '/', $args ) );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
	}

	/**
	 * Equality is by value.
	 */
	public function test_equality_is_by_value(): void {
		$this->assertTrue( ( new LoadingHint( 'lazy', 'low', 'async' ) )->equals( new LoadingHint( 'lazy', 'low', 'async' ) ) );
		$this->assertFalse( ( new LoadingHint( 'lazy', 'low', 'async' ) )->equals( new LoadingHint( 'eager', 'low', 'async' ) ) );
	}

	/**
	 * The class is final and every property is read-only.
	 */
	public function test_is_immutable(): void {
		$class = new \ReflectionClass( LoadingHint::class );

		$this->assertTrue( $class->isFinal() );

		foreach ( $class->getProperties() as $property ) {
			$this->assertTrue( $property->isReadOnly(), $property->getName() );
		}
	}
}
