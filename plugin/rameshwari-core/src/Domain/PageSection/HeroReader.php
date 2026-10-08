<?php
/**
 * Hero reader.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Domain\PageSection;

use Rameshwari\Core\Support\Cache;
use Rameshwari\Core\Support\Logger;

/**
 * Reads which hero campaigns are showing now.
 *
 * Eligibility is worked out from the stored fields and the clock on every
 * cache miss. The answer is cached through the shared Cache class with a
 * lifetime bounded by the next start or end among the hero campaigns, and
 * carries its own expiry time so a read after that moment is a miss even if
 * the backend expiry is approximate. Nothing here depends on scheduled jobs.
 * It reads no URLs and renders nothing.
 */
final class HeroReader {

	public const GROUP = 'page_sections';

	public const KEY = 'hero_v1';

	public const MAX_TTL = 3600;

	/**
	 * Current time in the site timezone.
	 *
	 * @var callable(): \DateTimeImmutable
	 */
	private $clock;

	/**
	 * Builds the reader.
	 *
	 * @param PageSectionRepository                 $repository Page section source.
	 * @param Cache                                 $cache      Cache of the page_sections group.
	 * @param Logger                                $logger     Diagnostics.
	 * @param (callable(): \DateTimeImmutable)|null $clock      Returns the current time. Defaults to now in the site timezone.
	 */
	public function __construct(
		private readonly PageSectionRepository $repository,
		private readonly Cache $cache,
		private readonly Logger $logger,
		?callable $clock = null
	) {
		$this->clock = $clock ?? static fn(): \DateTimeImmutable => new \DateTimeImmutable( 'now', wp_timezone() );
	}

	/**
	 * Cache lifetime for a boundary: at least one second, at most MAX_TTL.
	 *
	 * @param int|null $boundary Unix time of the next start or end, or null.
	 * @param int      $now      Unix time now.
	 * @return int
	 */
	public static function ttl_for( ?int $boundary, int $now ): int {
		return null === $boundary ? self::MAX_TTL : max( 1, min( self::MAX_TTL, $boundary - $now ) );
	}

	/**
	 * The eligible heroes, in order, from the cache or freshly computed.
	 *
	 * @return HeroSelection
	 */
	public function selection(): HeroSelection {
		$now   = ( $this->clock )();
		$stamp = $now->getTimestamp();
		$hit   = $this->from_payload( $this->cache->get( self::KEY ), $stamp );

		if ( null !== $hit ) {
			return $hit;
		}

		$selection = $this->compute( $now );

		if ( $selection->valid_until() > $stamp ) {
			$this->cache->set( self::KEY, $this->payload( $selection ), $selection->valid_until() - $stamp );
		}

		return $selection;
	}

	/**
	 * What a caller of the given kind shows. The caller decides which kind it is.
	 *
	 * @param bool $is_mobile Whether the caller is rendering for mobile.
	 * @return array<int,PageSection>
	 */
	public function for_context( bool $is_mobile ): array {
		return $this->selection()->for_context( $is_mobile );
	}

	/**
	 * Works the answer out from the stored sections.
	 *
	 * @param \DateTimeImmutable $now Current time.
	 * @return HeroSelection An already expired, uncached selection when the candidates cannot be listed.
	 */
	private function compute( \DateTimeImmutable $now ): HeroSelection {
		$stamp    = $now->getTimestamp();
		$eligible = array();
		$boundary = null;

		try {
			$all = $this->repository->candidates();
		} catch ( \OverflowException $overflow ) {
			return new HeroSelection( array(), $stamp );
		}

		foreach ( $all as $section ) {
			if ( ! $section->is_hero_candidate() ) {
				continue;
			}

			if ( ! $section->has_valid_window( $now->getTimezone() ) ) {
				$this->logger->warning(
					'Page section has an invalid schedule window.',
					array(
						'section' => $section->id,
					)
				);

				continue;
			}

			$next = $section->next_boundary( $now );

			if ( null !== $next ) {
				$boundary = null === $boundary ? $next : min( $boundary, $next );
			}

			if ( $section->is_eligible( $now ) ) {
				$eligible[] = $section;
			}
		}

		usort( $eligible, static fn( PageSection $a, PageSection $b ): int => $a->sort_key() <=> $b->sort_key() );

		return new HeroSelection( $eligible, $stamp + self::ttl_for( $boundary, $stamp ) );
	}

	/**
	 * The cacheable form of a selection.
	 *
	 * @param HeroSelection $selection Selection.
	 * @return array<string,mixed>
	 */
	private function payload( HeroSelection $selection ): array {
		return array(
			'valid_until' => $selection->valid_until(),
			'sections'    => array_map( static fn( PageSection $section ): array => $section->to_array(), $selection->desktop() ),
		);
	}

	/**
	 * A selection from cached data, or null when it is missing, stale or malformed.
	 *
	 * @param mixed $payload Cached value.
	 * @param int   $stamp   Unix time now.
	 * @return HeroSelection|null
	 */
	private function from_payload( mixed $payload, int $stamp ): ?HeroSelection {
		if ( ! is_array( $payload ) ) {
			return null;
		}

		$until = $payload['valid_until'] ?? null;
		$rows  = $payload['sections'] ?? null;

		if ( ! is_int( $until ) || ! is_array( $rows ) || $until <= $stamp || $until > $stamp + self::MAX_TTL ) {
			return null;
		}

		$sections = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				return null;
			}

			try {
				$sections[] = PageSection::from_array( $row );
			} catch ( \InvalidArgumentException $malformed ) {
				return null;
			}
		}

		return new HeroSelection( $sections, $until );
	}
}
