<?php
/**
 * Outcome of one regeneration batch.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Services\Media\Value;

/**
 * Counts, the cursor to send next and the attachments that failed.
 *
 * Nothing about the batch is stored on the server: the caller keeps the cursor.
 */
final class RegenerationResult {

	/**
	 * Failed attachments, keyed by attachment ID, each with a failure code.
	 *
	 * @var array<int,string>
	 */
	private readonly array $failures;

	/**
	 * Builds the result.
	 *
	 * @param int               $cursor      ID of the last attachment examined, or the cursor sent when none was.
	 * @param int               $regenerated Attachments that were rebuilt.
	 * @param int               $skipped     Attachments already current.
	 * @param array<int,string> $failures    Failure code by attachment ID.
	 * @param bool              $done        Whether the batch reached the end of the attachments.
	 * @throws \InvalidArgumentException When a number is negative or a failure is malformed.
	 */
	public function __construct(
		private readonly int $cursor,
		private readonly int $regenerated,
		private readonly int $skipped,
		array $failures,
		private readonly bool $done
	) {
		if ( $cursor < 0 || $regenerated < 0 || $skipped < 0 ) {
			throw new \InvalidArgumentException( 'A cursor and counts cannot be negative.' );
		}

		foreach ( $failures as $id => $code ) {
			if ( $id < 1 || '' === trim( $code ) ) {
				throw new \InvalidArgumentException( 'A failure needs a positive attachment ID and a code.' );
			}
		}

		$this->failures = $failures;
	}

	/**
	 * The cursor to send with the next call.
	 *
	 * @return int
	 */
	public function cursor(): int {
		return $this->cursor;
	}

	/**
	 * Attachments rebuilt.
	 *
	 * @return int
	 */
	public function regenerated(): int {
		return $this->regenerated;
	}

	/**
	 * Attachments skipped because they were already current.
	 *
	 * @return int
	 */
	public function skipped(): int {
		return $this->skipped;
	}

	/**
	 * Failure codes keyed by attachment ID.
	 *
	 * @return array<int,string>
	 */
	public function failures(): array {
		return $this->failures;
	}

	/**
	 * How many attachments failed.
	 *
	 * @return int
	 */
	public function failed(): int {
		return count( $this->failures );
	}

	/**
	 * How many attachments were examined.
	 *
	 * @return int
	 */
	public function processed(): int {
		return $this->regenerated + $this->skipped + count( $this->failures );
	}

	/**
	 * Whether the batch reached the end of the attachments.
	 *
	 * @return bool
	 */
	public function is_done(): bool {
		return $this->done;
	}
}
