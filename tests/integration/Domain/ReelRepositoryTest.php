<?php
/**
 * Reel repository tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Integration\Domain;

use Rameshwari\Core\Domain\Reel\Reel;
use Rameshwari\Core\Domain\Reel\ReelRepository;

/**
 * Reading and writing a real reel through its registered meta.
 */
final class ReelRepositoryTest extends \WP_UnitTestCase {

	/**
	 * Creates an attachment of a MIME type.
	 *
	 * @param string $mime MIME type.
	 * @return int
	 */
	private function attachment( string $mime ): int {
		$id = wp_insert_attachment(
			array(
				'post_mime_type' => $mime,
				'post_title'     => 'Fixture',
				'post_status'    => 'inherit',
			),
			'rjdomain-' . wp_generate_uuid4()
		);

		$this->assertIsInt( $id );
		$this->assertGreaterThan( 0, $id );

		return $id;
	}

	/**
	 * A new reel.
	 *
	 * @param string $status Post status.
	 * @return int
	 */
	private function post( string $status = 'publish' ): int {
		return self::factory()->post->create(
			array(
				'post_type'   => 'rj_reel',
				'post_status' => $status,
			)
		);
	}

	/**
	 * A reel with nothing stored reads back with the registered defaults.
	 */
	public function test_defaults_for_a_fresh_reel(): void {
		$reel = ( new ReelRepository() )->find( $this->post() );

		$this->assertInstanceOf( Reel::class, $reel );
		$this->assertSame( 'upload', $reel->source_type );
		$this->assertSame( 'public', $reel->visibility );
		$this->assertSame( array( 0, 0, '', array() ), array( $reel->attachment_id, $reel->poster_id, $reel->expires_at, $reel->linked_products ) );
	}

	/**
	 * An uploaded reel keeps its video, poster, products and expiry through a save.
	 */
	public function test_uploaded_reel_round_trips(): void {
		$id       = $this->post();
		$video    = $this->attachment( 'video/mp4' );
		$poster   = $this->attachment( 'image/png' );
		$products = array(
			self::factory()->post->create( array( 'post_type' => 'rj_product' ) ),
			self::factory()->post->create( array( 'post_type' => 'rj_product' ) ),
		);
		$repo     = new ReelRepository();

		$this->assertTrue( $repo->save( new Reel( $id, 'publish', 'upload', '', '', $video, $poster, $products, 'Bridal reel', 'hidden', '2030-01-02 03:04:05' ) ) );

		$back = $repo->find( $id );

		$this->assertInstanceOf( Reel::class, $back );
		$this->assertSame( array( $video, $poster, $products ), array( $back->attachment_id, $back->poster_id, $back->linked_products ) );
		$this->assertSame( array( 'Bridal reel', 'hidden', '2030-01-02 03:04:05' ), array( $back->caption, $back->visibility, $back->expires_at ) );
		$this->assertTrue( $back->is_upload() );
	}

	/**
	 * A provider reel stores its video ID and URL and no attachment.
	 */
	public function test_provider_reel_round_trips(): void {
		$id   = $this->post();
		$repo = new ReelRepository();

		$repo->save( new Reel( $id, 'publish', 'youtube', 'https://youtube.example/watch?v=abc123', 'abc123', 0, 0, array(), '', 'public', '' ) );

		$back = $repo->find( $id );

		$this->assertInstanceOf( Reel::class, $back );
		$this->assertSame( array( 'youtube', 'abc123', 0, 0 ), array( $back->source_type, $back->video_id, $back->attachment_id, $back->poster_id ) );
		$this->assertStringContainsString( 'abc123', $back->source_url );
	}

	/**
	 * A post of another type is not a reel and cannot be saved as one.
	 */
	public function test_other_post_types_are_not_reels(): void {
		$page = self::factory()->post->create();
		$repo = new ReelRepository();

		$this->assertNull( $repo->find( $page ) );
		$this->assertNull( $repo->find( 99999999 ) );
		$this->assertFalse( $repo->save( new Reel( $page, 'publish', 'upload', '', '', 0, 0, array(), '', 'public', '' ) ) );
	}

	/**
	 * The post status feeds the lifecycle.
	 */
	public function test_draft_status_is_read_from_the_post(): void {
		$reel = ( new ReelRepository() )->find( $this->post( 'draft' ) );

		$this->assertInstanceOf( Reel::class, $reel );
		$this->assertSame( Reel::STATE_DRAFT, $reel->state( new \DateTimeImmutable( 'now', wp_timezone() ) ) );
	}

	/**
	 * Saving adds no meta key outside the reel contract.
	 */
	public function test_save_writes_only_registered_reel_keys(): void {
		$id   = $this->post();
		$repo = new ReelRepository();

		$repo->save( new Reel( $id, 'publish', 'upload', '', '', 0, 0, array(), 'x', 'public', '' ) );

		$keys = array_filter( array_keys( get_post_meta( $id ) ), static fn( string $key ): bool => str_starts_with( $key, '_rj_' ) );
		$this->assertEqualsCanonicalizing(
			array( '_rj_source_type', '_rj_source_url', '_rj_video_id', '_rj_attachment_id', '_rj_poster_id', '_rj_linked_products', '_rj_caption', '_rj_visibility', '_rj_expires_at' ),
			array_values( $keys )
		);
	}
}
