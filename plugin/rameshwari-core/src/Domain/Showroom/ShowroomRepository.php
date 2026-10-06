<?php
/**
 * Showroom persistence.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Domain\Showroom;

/**
 * Persistence only. Reads a showroom from its post and registered meta and
 * writes the meta back. Timings are read from and written to _rj_timings and
 * nowhere else.
 */
final class ShowroomRepository {

	public const TYPE = 'rj_showroom';

	/**
	 * The showroom, or null when the ID is not a showroom.
	 *
	 * @param int $id Post ID.
	 * @return Showroom|null
	 * @throws \InvalidArgumentException When stored values break the contract.
	 */
	public function find( int $id ): ?Showroom {
		$post = get_post( $id );

		if ( ! $post instanceof \WP_Post || self::TYPE !== $post->post_type ) {
			return null;
		}

		$timings = get_post_meta( $id, '_rj_timings', true );
		$gallery = get_post_meta( $id, '_rj_gallery', true );

		return new Showroom(
			$id,
			$post->post_status,
			(string) get_post_meta( $id, '_rj_address', true ),
			(string) get_post_meta( $id, '_rj_city', true ),
			(string) get_post_meta( $id, '_rj_state', true ),
			(string) get_post_meta( $id, '_rj_pincode', true ),
			(string) get_post_meta( $id, '_rj_phone', true ),
			(string) get_post_meta( $id, '_rj_whatsapp', true ),
			is_array( $timings ) ? $timings : array(),
			(string) get_post_meta( $id, '_rj_map_url', true ),
			(float) get_post_meta( $id, '_rj_lat', true ),
			(float) get_post_meta( $id, '_rj_lng', true ),
			is_array( $gallery ) ? array_values( array_map( 'intval', $gallery ) ) : array()
		);
	}

	/**
	 * Writes the showroom's meta. The post itself is not changed.
	 *
	 * @param Showroom $showroom Showroom.
	 * @return bool False when the ID is no longer a showroom.
	 */
	public function save( Showroom $showroom ): bool {
		if ( self::TYPE !== get_post_type( $showroom->id ) ) {
			return false;
		}

		$values = array(
			'_rj_address'  => $showroom->address,
			'_rj_city'     => $showroom->city,
			'_rj_state'    => $showroom->state,
			'_rj_pincode'  => $showroom->pincode,
			'_rj_phone'    => $showroom->phone,
			'_rj_whatsapp' => $showroom->whatsapp,
			'_rj_timings'  => $showroom->timings,
			'_rj_map_url'  => $showroom->map_url,
			'_rj_lat'      => $showroom->lat,
			'_rj_lng'      => $showroom->lng,
			'_rj_gallery'  => $showroom->gallery,
		);

		foreach ( $values as $key => $value ) {
			update_post_meta( $showroom->id, $key, $value );
		}

		return true;
	}
}
