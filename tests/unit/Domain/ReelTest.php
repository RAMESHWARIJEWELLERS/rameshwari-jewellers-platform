<?php
/**
 * Reel model tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use Rameshwari\Core\Domain\Reel\Reel;

/**
 * The reel contract: source, poster, visibility and the derived lifecycle.
 */
final class ReelTest extends TestCase {

	/**
	 * A reel with named overrides.
	 *
	 * @param array<string,mixed> $over Field overrides.
	 * @return Reel
	 */
	private function reel( array $over = array() ): Reel {
		return new Reel(
			...array_merge(
				array(
					'id'              => 7,
					'status'          => 'publish',
					'source_type'     => 'upload',
					'source_url'      => '',
					'video_id'        => '',
					'attachment_id'   => 21,
					'poster_id'       => 22,
					'linked_products' => array( 3, 4 ),
					'caption'         => 'Bridal set',
					'visibility'      => 'public',
					'expires_at'      => '',
				),
				$over
			)
		);
	}

	/**
	 * A clock in the Kolkata timezone.
	 *
	 * @param string $time Y-m-d H:i:s.
	 * @return \DateTimeImmutable
	 */
	private function clockAt( string $time ): \DateTimeImmutable {
		return new \DateTimeImmutable( $time, new \DateTimeZone( 'Asia/Kolkata' ) );
	}

	/**
	 * Every field is readable as given.
	 */
	public function test_construction_keeps_every_field(): void {
		$reel = $this->reel();

		$this->assertSame( 7, $reel->id );
		$this->assertSame( 'upload', $reel->source_type );
		$this->assertSame( 21, $reel->attachment_id );
		$this->assertSame( 22, $reel->poster_id );
		$this->assertSame( array( 3, 4 ), $reel->linked_products );
		$this->assertSame( 'Bridal set', $reel->caption );
		$this->assertSame( 'public', $reel->visibility );
		$this->assertTrue( $reel->is_upload() );
		$this->assertTrue( $reel->has_poster() );
	}

	/**
	 * The four source types are accepted; anything else is refused.
	 */
	public function test_source_types(): void {
		foreach ( array( 'upload', 'instagram', 'youtube', 'url' ) as $type ) {
			$this->assertSame( $type, $this->reel( array( 'source_type' => $type ) )->source_type );
		}

		$this->expectException( \InvalidArgumentException::class );

		$this->reel( array( 'source_type' => 'tiktok' ) );
	}

	/**
	 * A provider reel carries its video ID and needs no attachment or poster.
	 */
	public function test_provider_reel_uses_the_video_id(): void {
		$reel = $this->reel(
			array(
				'source_type'   => 'youtube',
				'video_id'      => 'abc123',
				'attachment_id' => 0,
				'poster_id'     => 0,
			)
		);

		$this->assertFalse( $reel->is_upload() );
		$this->assertFalse( $reel->has_poster() );
		$this->assertSame( 'abc123', $reel->video_id );
		$this->assertSame( 0, $reel->attachment_id );
	}

	/**
	 * Invalid visibility, IDs, products and dates are refused.
	 */
	public function test_invalid_fields_are_rejected(): void {
		$bad = array(
			array( 'visibility' => 'secret' ),
			array( 'id' => 0 ),
			array( 'status' => '' ),
			array( 'attachment_id' => -1 ),
			array( 'poster_id' => -1 ),
			array( 'linked_products' => array( 0 ) ),
			array( 'expires_at' => 'tomorrow' ),
		);

		foreach ( $bad as $over ) {
			try {
				$this->reel( $over );

				$this->fail( 'Accepted a bad value for ' . implode( ',', array_keys( $over ) ) );
			} catch ( \InvalidArgumentException $expected ) {
				$this->assertNotSame( '', $expected->getMessage() );
			}
		}
	}

	/**
	 * Draft until published, then published until the expiry passes.
	 */
	public function test_lifecycle_is_derived(): void {
		$now = $this->clockAt( '2026-10-06 12:00:00' );

		$this->assertSame( Reel::STATE_DRAFT, $this->reel( array( 'status' => 'draft' ) )->state( $now ) );
		$this->assertSame( Reel::STATE_PUBLISHED, $this->reel()->state( $now ) );
		$this->assertSame( Reel::STATE_PUBLISHED, $this->reel( array( 'expires_at' => '2026-10-07 00:00:00' ) )->state( $now ) );
		$this->assertSame( Reel::STATE_EXPIRED, $this->reel( array( 'expires_at' => '2026-10-06 12:00:00' ) )->state( $now ) );
		$this->assertSame( Reel::STATE_EXPIRED, $this->reel( array( 'expires_at' => '2026-10-05 09:00:00' ) )->state( $now ) );
	}

	/**
	 * A draft past its expiry date is still a draft.
	 */
	public function test_draft_never_becomes_expired(): void {
		$reel = $this->reel(
			array(
				'status'     => 'draft',
				'expires_at' => '2020-01-01 00:00:00',
			)
		);

		$this->assertSame( Reel::STATE_DRAFT, $reel->state( $this->clockAt( '2026-10-06 12:00:00' ) ) );
	}

	/**
	 * Expiry is read in the timezone of the clock given.
	 */
	public function test_expiry_uses_the_clock_timezone(): void {
		$reel = $this->reel( array( 'expires_at' => '2026-10-06 12:00:00' ) );
		$utc  = new \DateTimeImmutable( '2026-10-06 08:00:00', new \DateTimeZone( 'UTC' ) );

		$this->assertFalse( $reel->is_expired( $utc ) );
		$this->assertTrue( $reel->is_expired( $this->clockAt( '2026-10-06 12:00:01' ) ) );
	}
}
