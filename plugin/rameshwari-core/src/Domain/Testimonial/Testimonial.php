<?php
/**
 * Testimonial model.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Domain\Testimonial;

/**
 * One testimonial, read from its post and registered meta. Immutable.
 */
final class Testimonial {

	/**
	 * Builds a testimonial.
	 *
	 * @param int    $id       Post ID.
	 * @param string $status   WordPress post status.
	 * @param string $author   Author name, up to 120 characters.
	 * @param int    $rating   Rating, 0 to 5, where 0 means none.
	 * @param int    $photo_id Photo attachment, or 0.
	 * @param string $context  Context line.
	 * @param string $source   Where the testimonial came from.
	 * @param int    $order    Display order, 0 to 9999.
	 * @throws \InvalidArgumentException When a field is outside its contract.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $status,
		public readonly string $author,
		public readonly int $rating,
		public readonly int $photo_id,
		public readonly string $context,
		public readonly string $source,
		public readonly int $order
	) {
		if ( $id < 1 || '' === $status ) {
			throw new \InvalidArgumentException( 'A testimonial needs a positive ID and a status.' );
		}

		if ( mb_strlen( $author ) > 120 ) {
			throw new \InvalidArgumentException( 'An author name holds at most 120 characters.' );
		}

		if ( $rating < 0 || $rating > 5 ) {
			throw new \InvalidArgumentException( 'A rating is from 0 to 5.' );
		}

		if ( $photo_id < 0 ) {
			throw new \InvalidArgumentException( 'The photo cannot be a negative ID.' );
		}

		if ( $order < 0 || $order > 9999 ) {
			throw new \InvalidArgumentException( 'Order must be from 0 to 9999.' );
		}
	}

	/**
	 * Whether the testimonial carries a rating.
	 *
	 * @return bool
	 */
	public function is_rated(): bool {
		return $this->rating > 0;
	}

	/**
	 * Whether the testimonial has a photo.
	 *
	 * @return bool
	 */
	public function has_photo(): bool {
		return $this->photo_id > 0;
	}
}
