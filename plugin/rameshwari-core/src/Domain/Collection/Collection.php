<?php
/**
 * Collection model.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Domain\Collection;

/**
 * One collection, read from its post and registered meta. Immutable.
 *
 * Membership is not stored here: the architecture gives collections no
 * membership table and no membership meta in this stage. The lifecycle
 * states beyond draft and published are not backed by any stored field in the
 * approved meta, so this model does not derive them.
 */
final class Collection {

	/**
	 * Builds a collection.
	 *
	 * @param int          $id        Post ID.
	 * @param string       $status    WordPress post status.
	 * @param int          $cover_id  Cover attachment, or 0.
	 * @param string       $badge     Badge text, up to 24 characters.
	 * @param int          $order     Display order, 0 to 9999.
	 * @param array<mixed> $auto_rule Automatic membership rule, empty when manual.
	 * @throws \InvalidArgumentException When a field is outside its contract.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $status,
		public readonly int $cover_id,
		public readonly string $badge,
		public readonly int $order,
		public readonly array $auto_rule
	) {
		if ( $id < 1 || '' === $status ) {
			throw new \InvalidArgumentException( 'A collection needs a positive ID and a status.' );
		}

		if ( $cover_id < 0 ) {
			throw new \InvalidArgumentException( 'The cover cannot be a negative ID.' );
		}

		if ( mb_strlen( $badge ) > 24 ) {
			throw new \InvalidArgumentException( 'A badge holds at most 24 characters.' );
		}

		if ( $order < 0 || $order > 9999 ) {
			throw new \InvalidArgumentException( 'Order must be from 0 to 9999.' );
		}
	}

	/**
	 * Whether the collection has a cover.
	 *
	 * @return bool
	 */
	public function has_cover(): bool {
		return $this->cover_id > 0;
	}

	/**
	 * Whether membership comes from a rule rather than by hand.
	 *
	 * @return bool
	 */
	public function is_automatic(): bool {
		return array() !== $this->auto_rule;
	}
}
