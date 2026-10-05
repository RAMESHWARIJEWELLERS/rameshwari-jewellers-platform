<?php
/**
 * Retained versions of one attachment.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Services\Media\Value;

/**
 * The artifact names that can be restored for an attachment, newest first.
 *
 * It only carries the names RetentionStore returned; it adds no meaning to them.
 */
final class RestoreCandidates {

	/**
	 * Artifact names, newest first.
	 *
	 * @var array<int,string>
	 */
	private readonly array $names;

	/**
	 * Builds the list.
	 *
	 * @param int               $attachment_id Attachment ID.
	 * @param array<int,string> $names         Artifact names, newest first.
	 * @throws \InvalidArgumentException When the ID is not positive or a name is blank or repeated.
	 */
	public function __construct( private readonly int $attachment_id, array $names ) {
		if ( $attachment_id < 1 ) {
			throw new \InvalidArgumentException( 'An attachment ID must be positive.' );
		}

		foreach ( $names as $name ) {
			if ( '' === trim( $name ) ) {
				throw new \InvalidArgumentException( 'An artifact name cannot be blank.' );
			}
		}

		if ( count( array_unique( $names ) ) !== count( $names ) ) {
			throw new \InvalidArgumentException( 'An artifact name cannot repeat.' );
		}

		$this->names = array_values( $names );
	}

	/**
	 * The attachment these versions belong to.
	 *
	 * @return int
	 */
	public function attachment_id(): int {
		return $this->attachment_id;
	}

	/**
	 * Artifact names, newest first.
	 *
	 * @return array<int,string>
	 */
	public function names(): array {
		return $this->names;
	}

	/**
	 * How many versions can be restored.
	 *
	 * @return int
	 */
	public function count(): int {
		return count( $this->names );
	}

	/**
	 * The newest version, or null when there is none.
	 *
	 * @return string|null
	 */
	public function newest(): ?string {
		return $this->names[0] ?? null;
	}

	/**
	 * Whether a name is among the restorable versions.
	 *
	 * @param string $name Artifact name.
	 * @return bool
	 */
	public function has( string $name ): bool {
		return in_array( $name, $this->names, true );
	}
}
