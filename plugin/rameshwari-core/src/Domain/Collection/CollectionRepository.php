<?php
/**
 * Collection persistence.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Domain\Collection;

/**
 * Persistence only. Reads a collection from its post and registered meta and
 * writes the meta back. No membership table, no new key.
 */
final class CollectionRepository {

	public const TYPE = 'rj_collection';

	/**
	 * The collection, or null when the ID is not a collection.
	 *
	 * @param int $id Post ID.
	 * @return Collection|null
	 * @throws \InvalidArgumentException When stored values break the contract.
	 */
	public function find( int $id ): ?Collection {
		$post = get_post( $id );

		if ( ! $post instanceof \WP_Post || self::TYPE !== $post->post_type ) {
			return null;
		}

		$rule = get_post_meta( $id, '_rj_auto_rule', true );

		return new Collection(
			$id,
			$post->post_status,
			(int) get_post_meta( $id, '_rj_cover_id', true ),
			(string) get_post_meta( $id, '_rj_badge', true ),
			(int) get_post_meta( $id, '_rj_order', true ),
			is_array( $rule ) ? $rule : array()
		);
	}

	/**
	 * Writes the collection's meta. The post itself is not changed.
	 *
	 * @param Collection $collection Collection.
	 * @return bool False when the ID is no longer a collection.
	 */
	public function save( Collection $collection ): bool {
		if ( self::TYPE !== get_post_type( $collection->id ) ) {
			return false;
		}

		$values = array(
			'_rj_cover_id'  => $collection->cover_id,
			'_rj_badge'     => $collection->badge,
			'_rj_order'     => $collection->order,
			'_rj_auto_rule' => $collection->auto_rule,
		);

		foreach ( $values as $key => $value ) {
			update_post_meta( $collection->id, $key, $value );
		}

		return true;
	}
}
