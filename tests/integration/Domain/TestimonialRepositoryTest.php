<?php
/**
 * Testimonial repository tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Integration\Domain;

use Rameshwari\Core\Domain\Testimonial\Testimonial;
use Rameshwari\Core\Domain\Testimonial\TestimonialRepository;

/**
 * Reading and writing a real testimonial through its registered meta.
 */
final class TestimonialRepositoryTest extends \WP_UnitTestCase {

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
	 * A new testimonial.
	 *
	 * @param string $status Post status.
	 * @return int
	 */
	private function post( string $status = 'publish' ): int {
		return self::factory()->post->create(
			array(
				'post_type'   => 'rj_testimonial',
				'post_status' => $status,
			)
		);
	}

	/**
	 * Nothing stored reads back with the registered defaults.
	 */
	public function test_defaults_for_a_fresh_testimonial(): void {
		$item = ( new TestimonialRepository() )->find( $this->post() );

		$this->assertInstanceOf( Testimonial::class, $item );
		$this->assertSame( array( '', 0, 0, '', '', 0 ), array( $item->author, $item->rating, $item->photo_id, $item->context, $item->source, $item->order ) );
		$this->assertFalse( $item->is_rated() );
	}

	/**
	 * Every field survives a save, Hindi text included.
	 */
	public function test_round_trip(): void {
		$id    = $this->post();
		$photo = $this->attachment( 'image/png' );
		$repo  = new TestimonialRepository();

		$this->assertTrue( $repo->save( new Testimonial( $id, 'publish', 'मीना शर्मा', 4, $photo, 'Wedding purchase', 'In store', 7 ) ) );

		$back = $repo->find( $id );

		$this->assertInstanceOf( Testimonial::class, $back );
		$this->assertSame( array( 'मीना शर्मा', 4, $photo ), array( $back->author, $back->rating, $back->photo_id ) );
		$this->assertSame( array( 'Wedding purchase', 'In store', 7 ), array( $back->context, $back->source, $back->order ) );
	}

	/**
	 * A draft testimonial is read as a draft.
	 */
	public function test_status_comes_from_the_post(): void {
		$item = ( new TestimonialRepository() )->find( $this->post( 'draft' ) );

		$this->assertInstanceOf( Testimonial::class, $item );
		$this->assertSame( 'draft', $item->status );
	}

	/**
	 * A post of another type is not a testimonial and cannot be saved as one.
	 */
	public function test_other_post_types_are_not_testimonials(): void {
		$page = self::factory()->post->create();
		$repo = new TestimonialRepository();

		$this->assertNull( $repo->find( $page ) );
		$this->assertFalse( $repo->save( new Testimonial( $page, 'publish', '', 0, 0, '', '', 0 ) ) );
	}

	/**
	 * Saving writes only the six registered keys.
	 */
	public function test_save_writes_only_registered_testimonial_keys(): void {
		$id = $this->post();

		( new TestimonialRepository() )->save( new Testimonial( $id, 'publish', 'A', 3, 0, '', '', 1 ) );

		$keys = array_filter( array_keys( get_post_meta( $id ) ), static fn( string $key ): bool => str_starts_with( $key, '_rj_' ) );

		$this->assertEqualsCanonicalizing( array( '_rj_author', '_rj_rating', '_rj_photo_id', '_rj_context', '_rj_source', '_rj_order' ), array_values( $keys ) );
	}
}
