<?php
/**
 * Reel persistence.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Domain\Reel;

/**
 * Persistence only. Reads a reel from its post and registered meta and writes
 * the meta back. It adds no key, post type or table, and it holds no
 * rendering, media or provider logic.
 */
final class ReelRepository {

	public const TYPE = 'rj_reel';

	/**
	 * The reel, or null when the ID is not a reel.
	 *
	 * @param int $id Post ID.
	 * @return Reel|null
	 * @throws \InvalidArgumentException When stored values break the reel contract.
	 */
	public function find( int $id ): ?Reel {
		$post = get_post( $id );

		if ( ! $post instanceof \WP_Post || self::TYPE !== $post->post_type ) {
			return null;
		}

		$linked = get_post_meta( $id, '_rj_linked_products', true );
		$type   = (string) get_post_meta( $id, '_rj_source_type', true );
		$show   = (string) get_post_meta( $id, '_rj_visibility', true );

		return new Reel(
			$id,
			$post->post_status,
			'' === $type ? 'upload' : $type,
			(string) get_post_meta( $id, '_rj_source_url', true ),
			(string) get_post_meta( $id, '_rj_video_id', true ),
			(int) get_post_meta( $id, '_rj_attachment_id', true ),
			(int) get_post_meta( $id, '_rj_poster_id', true ),
			is_array( $linked ) ? array_values( array_map( 'intval', $linked ) ) : array(),
			(string) get_post_meta( $id, '_rj_caption', true ),
			'' === $show ? 'public' : $show,
			(string) get_post_meta( $id, '_rj_expires_at', true )
		);
	}

	/**
	 * Writes the reel's meta. The post itself is not changed.
	 *
	 * @param Reel $reel Reel.
	 * @return bool False when the ID is no longer a reel.
	 */
	public function save( Reel $reel ): bool {
		if ( self::TYPE !== get_post_type( $reel->id ) ) {
			return false;
		}

		$values = array(
			'_rj_source_type'     => $reel->source_type,
			'_rj_source_url'      => $reel->source_url,
			'_rj_video_id'        => $reel->video_id,
			'_rj_attachment_id'   => $reel->attachment_id,
			'_rj_poster_id'       => $reel->poster_id,
			'_rj_linked_products' => $reel->linked_products,
			'_rj_caption'         => $reel->caption,
			'_rj_visibility'      => $reel->visibility,
			'_rj_expires_at'      => $reel->expires_at,
		);

		foreach ( $values as $key => $value ) {
			update_post_meta( $reel->id, $key, $value );
		}

		return true;
	}
}
