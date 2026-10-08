<?php
/**
 * Hero selection result.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Domain\PageSection;

/**
 * The eligible hero sections in display order, and how long that answer holds.
 *
 * Immutable and device-independent: desktop shows all of them, mobile shows the
 * first. It carries section data, never resolved URLs.
 */
final class HeroSelection {

	/**
	 * Eligible sections in display order.
	 *
	 * @var array<int,PageSection>
	 */
	private readonly array $desktop;

	/**
	 * Builds a selection.
	 *
	 * @param array<int,PageSection> $desktop     Eligible sections, already ordered.
	 * @param int                    $valid_until Unix time after which the answer must be recomputed.
	 */
	public function __construct( array $desktop, private readonly int $valid_until ) {
		$this->desktop = array_values( $desktop );
	}

	/**
	 * Every eligible hero, in order.
	 *
	 * @return array<int,PageSection>
	 */
	public function desktop(): array {
		return $this->desktop;
	}

	/**
	 * The single mobile hero: the first eligible one, or null.
	 *
	 * @return PageSection|null
	 */
	public function mobile(): ?PageSection {
		return $this->desktop[0] ?? null;
	}

	/**
	 * What a caller of the given kind shows.
	 *
	 * @param bool $is_mobile Whether the caller is rendering for mobile.
	 * @return array<int,PageSection>
	 */
	public function for_context( bool $is_mobile ): array {
		$first = $this->mobile();

		if ( ! $is_mobile ) {
			return $this->desktop;
		}

		return null === $first ? array() : array( $first );
	}

	/**
	 * Whether nothing is eligible.
	 *
	 * @return bool
	 */
	public function is_empty(): bool {
		return array() === $this->desktop;
	}

	/**
	 * Unix time after which the answer is stale.
	 *
	 * @return int
	 */
	public function valid_until(): int {
		return $this->valid_until;
	}
}
