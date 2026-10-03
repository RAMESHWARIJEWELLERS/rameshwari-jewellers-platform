<?php
/**
 * Attachment reference report.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Services\Media\Value;

/**
 * Who uses an attachment: owner type, owner ID, field name and edit link per
 * reference. The deletion guard blocks when any exist.
 *
 * @phpstan-type Reference array{owner_type:string,owner_id:int,field:string,edit_link:string}
 */
final class ReferenceReport {

	private const OWNER_TYPES = array( 'post', 'term', 'option' );

	/**
	 * Builds a report.
	 *
	 * @param int                  $attachment_id Attachment checked.
	 * @param array<int,Reference> $references    References found.
	 * @throws \InvalidArgumentException When the attachment ID or a reference is malformed.
	 */
	public function __construct( private readonly int $attachment_id, private readonly array $references ) {
		if ( $attachment_id < 1 ) {
			throw new \InvalidArgumentException( 'The attachment ID must be positive.' );
		}

		foreach ( $references as $reference ) {
			if (
				! in_array( $reference['owner_type'] ?? null, self::OWNER_TYPES, true )
				|| ! is_int( $reference['owner_id'] ?? null ) || $reference['owner_id'] < 0
				|| ! is_string( $reference['field'] ?? null ) || '' === $reference['field']
				|| ! is_string( $reference['edit_link'] ?? null )
			) {
				throw new \InvalidArgumentException( 'A reference needs owner_type, owner_id, field and edit_link.' );
			}
		}
	}

	/**
	 * The attachment checked.
	 *
	 * @return int
	 */
	public function attachment_id(): int {
		return $this->attachment_id;
	}

	/**
	 * The references found.
	 *
	 * @return array<int,Reference>
	 */
	public function references(): array {
		return array_values( $this->references );
	}

	/**
	 * Number of references.
	 *
	 * @return int
	 */
	public function count(): int {
		return count( $this->references );
	}

	/**
	 * Whether anything uses the attachment.
	 *
	 * @return bool
	 */
	public function is_referenced(): bool {
		return array() !== $this->references;
	}
}
