<?php
/**
 * Gallery service.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Services\Media;

use Rameshwari\Core\Services\Media\Value\GalleryResult;

/**
 * Gallery array operations and read-time composition (blueprint §9).
 *
 * Order is array position; there is no sort field. Nothing here writes: the
 * owner of the gallery persists it (ProductService for products). The caller
 * supplies the maximum, because each owner type has its own contract.
 *
 * @phpstan-import-type Dropped from GalleryResult
 */
final class Gallery {

	/**
	 * Cleans a list of IDs: positive integers, real attachments, first occurrence kept, trimmed to the maximum.
	 *
	 * A maximum of 0 is a literal zero-item maximum: nothing is kept and every valid
	 * attachment is reported as over_limit. This class gives 0 no business meaning
	 * beyond that; each owner decides what its own limit means before calling.
	 *
	 * @param array<int|string,mixed> $ids Raw values from an owner.
	 * @param int                     $max Maximum to keep. Zero keeps nothing.
	 * @return GalleryResult
	 * @throws \InvalidArgumentException When the maximum is negative.
	 */
	public function normalize( array $ids, int $max ): GalleryResult {
		if ( $max < 0 ) {
			throw new \InvalidArgumentException( 'The maximum cannot be negative.' );
		}

		$kept    = array();
		$dropped = array();
		$checked = array();

		foreach ( $ids as $raw ) {
			$id = $this->to_int( $raw );

			if ( null === $id || $id < 1 ) {
				$dropped[] = array(
					'id'     => $id ?? 0,
					'reason' => 'invalid',
				);
				continue;
			}

			if ( in_array( $id, $kept, true ) ) {
				$dropped[] = array(
					'id'     => $id,
					'reason' => 'duplicate',
				);
				continue;
			}

			$checked[ $id ] ??= 'attachment' === get_post_type( $id );

			if ( ! $checked[ $id ] ) {
				$dropped[] = array(
					'id'     => $id,
					'reason' => 'not_attachment',
				);
				continue;
			}

			if ( count( $kept ) >= $max ) {
				$dropped[] = array(
					'id'     => $id,
					'reason' => 'over_limit',
				);
				continue;
			}

			$kept[] = $id;
		}

		return new GalleryResult( $kept, $dropped );
	}

	/**
	 * A new list with the attachment appended. Present or non-positive IDs change nothing.
	 *
	 * @param array<int,int> $ids           Current list.
	 * @param int            $attachment_id Attachment to add.
	 * @return array<int,int>
	 */
	public function add( array $ids, int $attachment_id ): array {
		$list = array_values( $ids );

		if ( $attachment_id < 1 || in_array( $attachment_id, $list, true ) ) {
			return $list;
		}

		$list[] = $attachment_id;

		return $list;
	}

	/**
	 * A new list without the attachment, in the same order.
	 *
	 * @param array<int,int> $ids           Current list.
	 * @param int            $attachment_id Attachment to remove.
	 * @return array<int,int>
	 */
	public function remove( array $ids, int $attachment_id ): array {
		return array_values(
			array_filter(
				$ids,
				static fn ( int $id ): bool => $id !== $attachment_id
			)
		);
	}

	/**
	 * A new list with the attachment at a zero-based position. A position outside the list is clamped.
	 *
	 * @param array<int,int> $ids           Current list.
	 * @param int            $attachment_id Attachment to move.
	 * @param int            $new_position  Target position.
	 * @return array<int,int>
	 */
	public function move( array $ids, int $attachment_id, int $new_position ): array {
		$list = array_values( $ids );

		if ( ! in_array( $attachment_id, $list, true ) ) {
			return $list;
		}

		$rest     = $this->remove( $list, $attachment_id );
		$position = max( 0, min( $new_position, count( $rest ) ) );

		array_splice( $rest, $position, 0, array( $attachment_id ) );

		return $rest;
	}

	/**
	 * Display order for a post: featured image first, then the stored gallery order.
	 *
	 * Entries that are not attachments (missing, deleted or another post type)
	 * are skipped, as are repeats. Stored data is not touched.
	 *
	 * @param int $post_id Owner post ID.
	 * @return array<int,int>
	 */
	public function ordered( int $post_id ): array {
		if ( $post_id < 1 || null === get_post( $post_id ) ) {
			return array();
		}

		$candidates = array();
		$featured   = $this->to_int( get_post_meta( $post_id, '_thumbnail_id', true ) );

		if ( null !== $featured ) {
			$candidates[] = $featured;
		}

		$stored = get_post_meta( $post_id, '_rj_gallery', true );

		if ( is_array( $stored ) ) {
			foreach ( $stored as $raw ) {
				$id = $this->to_int( $raw );

				if ( null !== $id ) {
					$candidates[] = $id;
				}
			}
		}

		$ordered = array();

		foreach ( $candidates as $id ) {
			if ( $id > 0 && ! in_array( $id, $ordered, true ) && 'attachment' === get_post_type( $id ) ) {
				$ordered[] = $id;
			}
		}

		return $ordered;
	}

	/**
	 * Whole-number value of an int or an integer string; null for anything else.
	 *
	 * @param mixed $value Raw value.
	 * @return int|null
	 */
	private function to_int( mixed $value ): ?int {
		if ( is_int( $value ) ) {
			return $value;
		}

		if ( is_string( $value ) && 1 === preg_match( '/^\s*-?\d+\s*$/', $value ) ) {
			return (int) trim( $value );
		}

		return null;
	}
}
