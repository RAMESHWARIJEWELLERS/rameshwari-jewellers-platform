<?php
/**
 * Candidate product codes found in a file name.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Services\Media\Value;

/**
 * Zero, one or several candidate codes. It is a suggestion and nothing more.
 *
 * Nothing here attaches an image to a product: an unambiguous match is still
 * only offered to the user, who confirms it through the product service.
 */
final class MatchResult {

	/**
	 * Candidate codes, normalised by the caller, in the order found, without repeats.
	 *
	 * @var array<int,string>
	 */
	private readonly array $candidates;

	/**
	 * Builds the result.
	 *
	 * @param array<int,string> $candidates Candidate codes.
	 * @throws \InvalidArgumentException When a code is blank.
	 */
	public function __construct( array $candidates ) {
		$unique = array();

		foreach ( $candidates as $code ) {
			if ( '' === trim( $code ) ) {
				throw new \InvalidArgumentException( 'A candidate code cannot be blank.' );
			}

			if ( ! in_array( $code, $unique, true ) ) {
				$unique[] = $code;
			}
		}

		$this->candidates = $unique;
	}

	/**
	 * A result with no candidate.
	 *
	 * @return self
	 */
	public static function none(): self {
		return new self( array() );
	}

	/**
	 * The candidate codes.
	 *
	 * @return array<int,string>
	 */
	public function candidates(): array {
		return $this->candidates;
	}

	/**
	 * How many candidates were found.
	 *
	 * @return int
	 */
	public function count(): int {
		return count( $this->candidates );
	}

	/**
	 * Whether no candidate was found.
	 *
	 * @return bool
	 */
	public function is_empty(): bool {
		return array() === $this->candidates;
	}

	/**
	 * Whether more than one candidate was found.
	 *
	 * @return bool
	 */
	public function is_ambiguous(): bool {
		return count( $this->candidates ) > 1;
	}

	/**
	 * The one suggested code, or null when there are none or several.
	 *
	 * @return string|null
	 */
	public function suggestion(): ?string {
		return 1 === count( $this->candidates ) ? $this->candidates[0] : null;
	}

	/**
	 * Value equality.
	 *
	 * @param MatchResult $other Other result.
	 * @return bool
	 */
	public function equals( MatchResult $other ): bool {
		return $this->candidates === $other->candidates;
	}
}
