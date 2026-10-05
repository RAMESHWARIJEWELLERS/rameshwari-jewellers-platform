<?php
/**
 * Media audit report tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Unit\Media;

use PHPUnit\Framework\TestCase;
use Rameshwari\Core\Services\Media\Value\MediaAuditReport;

/**
 * The report carries findings, failures and bounds, and decides nothing.
 */
final class MediaAuditReportTest extends TestCase {

	/**
	 * Every check appears in findings(), empty when it found nothing.
	 */
	public function test_findings_always_list_every_check(): void {
		$report = new MediaAuditReport( array(), array(), array(), 0 );

		$this->assertSame( MediaAuditReport::CHECKS, array_keys( $report->findings() ) );
		$this->assertSame( 0, $report->count() );
		$this->assertTrue( $report->is_complete() );
	}

	/**
	 * Findings, failures and truncation are exposed and affect completeness.
	 */
	public function test_failures_and_truncation_make_it_incomplete(): void {
		$finding = array(
			'id'        => 7,
			'edit_link' => 'x',
		);
		$report  = new MediaAuditReport(
			array( 'products_without_images' => array( $finding ) ),
			array( 'reels_without_source' => 'check_failed' ),
			array( 'unreferenced_attachments' ),
			3
		);

		$this->assertSame( 1, $report->count() );
		$this->assertSame( array( $finding ), $report->findings()['products_without_images'] );
		$this->assertSame( array( 'reels_without_source' => 'check_failed' ), $report->failures() );
		$this->assertSame( array( 'unreferenced_attachments' ), $report->truncated() );
		$this->assertSame( 3, $report->retention_purged() );
		$this->assertFalse( $report->is_complete() );
	}

	/**
	 * The summary is one line of counts with no IDs and no paths.
	 */
	public function test_summary_is_counts_only(): void {
		$report = new MediaAuditReport(
			array(
				'products_without_images' => array(
					array(
						'id'        => 912,
						'edit_link' => '/wp-admin/post.php?post=912',
					),
				),
			),
			array(),
			array(),
			2
		);

		$this->assertStringStartsWith( 'Media audit: ', $report->summary() );
		$this->assertStringContainsString( 'products_without_images=1', $report->summary() );
		$this->assertStringContainsString( 'purged=2', $report->summary() );
		$this->assertStringNotContainsString( '912', $report->summary() );
		$this->assertStringNotContainsString( '/', $report->summary() );
		$this->assertStringNotContainsString( "\n", $report->summary() );
	}

	/**
	 * Unknown checks, bad IDs, blank failure codes and negative counts are refused.
	 */
	public function test_invalid_construction_is_rejected(): void {
		$bad = array(
			array( array( 'nope' => array() ), array(), array(), 0 ),
			array(
				array(
					'products_without_images' => array(
						array(
							'id'        => 0,
							'edit_link' => '',
						),
					),
				),
				array(),
				array(),
				0,
			),
			array( array(), array( 'reels_without_source' => '  ' ), array(), 0 ),
			array( array(), array( 'nope' => 'x' ), array(), 0 ),
			array( array(), array(), array( 'nope' ), 0 ),
			array( array(), array(), array(), -1 ),
		);

		foreach ( $bad as $args ) {
			try {
				new MediaAuditReport( $args[0], $args[1], $args[2], $args[3] );

				$this->fail( 'An invalid report was accepted.' );
			} catch ( \InvalidArgumentException $expected ) {
				$this->assertNotSame( '', $expected->getMessage() );
			}
		}
	}

	/**
	 * Nothing can be changed after construction.
	 */
	public function test_report_is_immutable(): void {
		$property = ( new \ReflectionClass( MediaAuditReport::class ) )->getProperty( 'findings' );

		$this->assertTrue( $property->isReadOnly() );
	}
}
