<?php
/**
 * ReferenceReport tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Unit\Media;

use PHPUnit\Framework\TestCase;
use Rameshwari\Core\Services\Media\Value\ReferenceReport;

/**
 * ReferenceReport invariants.
 */
final class ReferenceReportTest extends TestCase {

	/**
	 * No references means not referenced.
	 */
	public function test_empty_report_is_not_referenced(): void {
		$report = new ReferenceReport( 12, array() );

		$this->assertSame( 12, $report->attachment_id() );
		$this->assertFalse( $report->is_referenced() );
		$this->assertSame( 0, $report->count() );
	}

	/**
	 * References are kept with owner, field and edit link.
	 */
	public function test_keeps_references(): void {
		$references = array(
			array(
				'owner_type' => 'post',
				'owner_id'   => 7,
				'field'      => '_thumbnail_id',
				'edit_link'  => 'post.php?post=7&action=edit',
			),
			array(
				'owner_type' => 'option',
				'owner_id'   => 0,
				'field'      => 'default_image_id',
				'edit_link'  => '',
			),
		);
		$report     = new ReferenceReport( 12, $references );

		$this->assertTrue( $report->is_referenced() );
		$this->assertSame( 2, $report->count() );
		$this->assertSame( $references, $report->references() );
	}

	/**
	 * The attachment ID must be positive.
	 */
	public function test_rejects_a_non_positive_attachment_id(): void {
		$this->expectException( \InvalidArgumentException::class );

		new ReferenceReport( 0, array() );
	}

	/**
	 * Malformed references are rejected.
	 */
	public function test_rejects_malformed_references(): void {
		$good = array(
			'owner_type' => 'term',
			'owner_id'   => 3,
			'field'      => '_rj_image_id',
			'edit_link'  => '',
		);

		foreach (
			array(
				array_merge( $good, array( 'owner_type' => 'user' ) ),
				array_merge( $good, array( 'owner_id' => -1 ) ),
				array_merge( $good, array( 'field' => '' ) ),
				array( 'owner_type' => 'post' ),
			) as $bad
		) {
			try {
				new ReferenceReport( 5, $this->loose( array( $bad ) ) );
				$this->fail( 'Accepted a malformed reference.' );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
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
		$class = new \ReflectionClass( ReferenceReport::class );

		$this->assertTrue( $class->isFinal() );

		foreach ( $class->getProperties() as $property ) {
			$this->assertTrue( $property->isReadOnly(), $property->getName() );
		}
	}
}
