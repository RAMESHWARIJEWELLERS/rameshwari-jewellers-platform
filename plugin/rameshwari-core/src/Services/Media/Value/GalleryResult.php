<?php
/**
 * Gallery normalisation result.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Services\Media\Value;

/**
 * A normalised gallery: the kept IDs in order, and what was dropped and why.
 * Gallery::normalize() builds it; it never writes anything.
 *
 * @phpstan-type Dropped array{id:int,reason:string}
 */
final class GalleryResult {

	private const REASONS = array( 'invalid', 'not_attachment', 'duplicate', 'over_limit' );

	/**
	 * Builds a result.
	 *
	 * @param array<int,int>     $ids     Kept attachment IDs, in order.
	 * @param array<int,Dropped> $dropped Dropped entries.
	 * @throws \InvalidArgumentException When an ID is not a unique positive integer or a reason is unknown.
	 */
	public function __construct( private readonly array $ids, private readonly array $dropped ) {
		$seen = array();

		foreach ( $ids as $id ) {
			if ( ! is_int( $id ) || $id < 1 || isset( $seen[ $id ] ) ) {
				throw new \InvalidArgumentException( 'Kept IDs must be unique positive integers.' );
			}

			$seen[ $id ] = true;
		}

		foreach ( $dropped as $entry ) {
			if ( ! is_int( $entry['id'] ?? null ) || ! in_array( $entry['reason'] ?? null, self::REASONS, true ) ) {
				throw new \InvalidArgumentException( 'A dropped entry needs an integer id and a known reason.' );
			}
		}
	}

	/**
	 * Kept IDs, in order.
	 *
	 * @return array<int,int>
	 */
	public function ids(): array {
		return array_values( $this->ids );
	}

	/**
	 * Dropped entries.
	 *
	 * @return array<int,Dropped>
	 */
	public function dropped(): array {
		return array_values( $this->dropped );
	}

	/**
	 * Whether anything was dropped.
	 *
	 * @return bool
	 */
	public function changed(): bool {
		return array() !== $this->dropped;
	}
}
