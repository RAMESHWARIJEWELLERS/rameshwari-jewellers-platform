<?php
/**
 * Result of one media audit run.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Services\Media\Value;

/**
 * Findings per check, the checks that failed or stopped at their bound, and the retention count.
 *
 * It is computed on each run and stored nowhere.
 */
final class MediaAuditReport {

	public const CHECKS = array(
		'products_without_images',
		'categories_without_thumbnails',
		'reels_without_source',
		'unreferenced_attachments',
		'retention_cleanup',
	);

	/**
	 * Findings by check name: each is an object ID with its edit link.
	 *
	 * @var array<string,array<int,array{id:int,edit_link:string}>>
	 */
	private readonly array $findings;

	/**
	 * Builds the report.
	 *
	 * @param array<string,array<int,array{id:int,edit_link:string}>> $findings  Findings by check.
	 * @param array<string,string>                                    $failures  Failure code by check.
	 * @param array<int,string>                                       $truncated Checks that stopped at their bound.
	 * @param int                                                     $purged    Retention artifacts removed.
	 * @throws \InvalidArgumentException When a check name, finding or count is invalid.
	 */
	public function __construct( array $findings, private readonly array $failures, private readonly array $truncated, private readonly int $purged ) {
		if ( $purged < 0 ) {
			throw new \InvalidArgumentException( 'The purged count cannot be negative.' );
		}

		foreach ( array_merge( array_keys( $findings ), array_keys( $failures ), $truncated ) as $check ) {
			if ( ! in_array( $check, self::CHECKS, true ) ) {
				throw new \InvalidArgumentException( 'Unknown audit check.' );
			}
		}

		foreach ( $findings as $list ) {
			foreach ( $list as $finding ) {
				if ( $finding['id'] < 1 ) {
					throw new \InvalidArgumentException( 'A finding needs a positive ID.' );
				}
			}
		}

		foreach ( $failures as $code ) {
			if ( '' === trim( $code ) ) {
				throw new \InvalidArgumentException( 'A failure needs a code.' );
			}
		}

		$this->findings = $findings;
	}

	/**
	 * Findings by check name; a check with none is an empty list.
	 *
	 * @return array<string,array<int,array{id:int,edit_link:string}>>
	 */
	public function findings(): array {
		$all = array();

		foreach ( self::CHECKS as $check ) {
			$all[ $check ] = $this->findings[ $check ] ?? array();
		}

		return $all;
	}

	/**
	 * How many findings in total.
	 *
	 * @return int
	 */
	public function count(): int {
		return array_sum( array_map( 'count', $this->findings ) );
	}

	/**
	 * Failure code by check, for checks that could not finish.
	 *
	 * @return array<string,string>
	 */
	public function failures(): array {
		return $this->failures;
	}

	/**
	 * Checks that stopped at their bound, so their findings may be incomplete.
	 *
	 * @return array<int,string>
	 */
	public function truncated(): array {
		return $this->truncated;
	}

	/**
	 * Retention artifacts removed by the run.
	 *
	 * @return int
	 */
	public function retention_purged(): int {
		return $this->purged;
	}

	/**
	 * Whether every check ran to the end and none was cut short.
	 *
	 * @return bool
	 */
	public function is_complete(): bool {
		return array() === $this->failures && array() === $this->truncated;
	}

	/**
	 * A one-line summary with counts only: no IDs and no paths.
	 *
	 * @return string
	 */
	public function summary(): string {
		$parts = array();

		foreach ( $this->findings() as $check => $list ) {
			$parts[] = $check . '=' . count( $list );
		}

		$parts[] = 'purged=' . $this->purged;
		$parts[] = 'failed=' . count( $this->failures );
		$parts[] = 'truncated=' . count( $this->truncated );

		return 'Media audit: ' . implode( ' ', $parts );
	}
}
