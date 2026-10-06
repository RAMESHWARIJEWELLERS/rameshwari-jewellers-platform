<?php
/**
 * Testimonial persistence.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Domain\Testimonial;

/**
 * Persistence only. Reads a testimonial from its post and registered meta and
 * writes the meta back.
 */
final class TestimonialRepository {

	public const TYPE = 'rj_testimonial';

	/**
	 * The testimonial, or null when the ID is not a testimonial.
	 *
	 * @param int $id Post ID.
	 * @return Testimonial|null
	 * @throws \InvalidArgumentException When stored values break the contract.
	 */
	public function find( int $id ): ?Testimonial {
		$post = get_post( $id );

		if ( ! $post instanceof \WP_Post || self::TYPE !== $post->post_type ) {
			return null;
		}

		return new Testimonial(
			$id,
			$post->post_status,
			(string) get_post_meta( $id, '_rj_author', true ),
			(int) get_post_meta( $id, '_rj_rating', true ),
			(int) get_post_meta( $id, '_rj_photo_id', true ),
			(string) get_post_meta( $id, '_rj_context', true ),
			(string) get_post_meta( $id, '_rj_source', true ),
			(int) get_post_meta( $id, '_rj_order', true )
		);
	}

	/**
	 * Writes the testimonial's meta. The post itself is not changed.
	 *
	 * @param Testimonial $testimonial Testimonial.
	 * @return bool False when the ID is no longer a testimonial.
	 */
	public function save( Testimonial $testimonial ): bool {
		if ( self::TYPE !== get_post_type( $testimonial->id ) ) {
			return false;
		}

		$values = array(
			'_rj_author'   => $testimonial->author,
			'_rj_rating'   => $testimonial->rating,
			'_rj_photo_id' => $testimonial->photo_id,
			'_rj_context'  => $testimonial->context,
			'_rj_source'   => $testimonial->source,
			'_rj_order'    => $testimonial->order,
		);

		foreach ( $values as $key => $value ) {
			update_post_meta( $testimonial->id, $key, $value );
		}

		return true;
	}
}
