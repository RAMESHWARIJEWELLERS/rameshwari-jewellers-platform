<?php
/**
 * GalleryResult tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Unit\Media;

use PHPUnit\Framework\TestCase;
use Rameshwari\Core\Services\Media\Value\GalleryResult;

/**
 * GalleryResult invariants.
 */
final class GalleryResultTest extends TestCase {

	/**
	 * IDs keep their order and nothing dropped means unchanged.
	 */
	public function test_keeps_order_and_reports_unchanged(): void {
		$result = new GalleryResult( array( 9, 3, 5 ), array() );

		$this->assertSame( array( 9, 3, 5 ), $result->ids() );
		$this->assertSame( array(), $result->dropped() );
		$this->assertFalse( $result->changed() );
	}

	/**
	 * Dropped entries are reported with their reason.
	 */
	public function test_reports_what_was_dropped(): void {
		$dropped = array(
			array(
				'id'     => 4,
				'reason' => 'duplicate',
			),
			array(
				'id'     => 8,
				'reason' => 'not_attachment',
			),
		);
		$result  = new GalleryResult( array( 1 ), $dropped );

		$this->assertTrue( $result->changed() );
		$this->assertSame( $dropped, $result->dropped() );
	}

	/**
	 * Kept IDs must be unique positive integers.
	 */
	public function test_rejects_bad_kept_ids(): void {
		foreach ( array( array( 0 ), array( -3 ), array( 2, 2 ), array( '5' ) ) as $ids ) {
			try {
				new GalleryResult( $this->loose( $ids ), array() );
				$this->fail( 'Accepted: ' . implode( ',', array_map( 'strval', $ids ) ) );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
	}

	/**
	 * A dropped entry needs a known reason.
	 */
	public function test_rejects_unknown_drop_reasons(): void {
		$this->expectException( \InvalidArgumentException::class );

		new GalleryResult(
			array(),
			$this->loose(
				array(
					array(
						'id'     => 1,
						'reason' => 'because',
					),
				)
			)
		);
	}

	/**
	 * Showroom galleries are not limited by this type.
	 */
	public function test_has_no_size_limit(): void {
		$ids = range( 1, 40 );

		$this->assertCount( 40, ( new GalleryResult( $ids, array() ) )->ids() );
	}

	/**
	 * Returns a value untyped, so a test can pass deliberately wrong input.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private function loose( mixed $value ): mixed {
		return $value;
	}

	/**
	 * The class is final and every property is read-only.
	 */
	public function test_is_immutable(): void {
		$class = new \ReflectionClass( GalleryResult::class );

		$this->assertTrue( $class->isFinal() );

		foreach ( $class->getProperties() as $property ) {
			$this->assertTrue( $property->isReadOnly(), $property->getName() );
		}
	}
}
