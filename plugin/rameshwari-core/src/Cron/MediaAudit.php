<?php
/**
 * Daily media audit.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Cron;

use Rameshwari\Core\Services\Media\ReferenceGuard;
use Rameshwari\Core\Services\Media\RetentionStore;
use Rameshwari\Core\Services\Media\Value\MediaAuditReport;
use Rameshwari\Core\Support\Logger;

/**
 * Checks media health and clears expired retention files (blueprint §14).
 *
 * Every check is an ordinary method call that returns a report, so the Health
 * screen can run it on demand and nothing depends on WP-Cron. The report is
 * computed each time and stored nowhere; a scheduled run only logs a one-line
 * count summary. Queries are paged by ID and each check stops at a page bound,
 * saying so in the report. One check failing is reported and the others still
 * run. Nothing here deletes an attachment; only RetentionStore removes files,
 * and only artifacts past the retention age. The scheduling itself belongs to
 * the module that wires this class.
 */
final class MediaAudit {

	public const UNREFERENCED_DAYS = 90;

	public const RETENTION_DAYS = 30;

	private const RETENTION_LIMIT = 200;

	private const DAY = 86400;

	/**
	 * Returns the current Unix time.
	 *
	 * @var callable(): int
	 */
	private $clock;

	/**
	 * Builds the audit.
	 *
	 * @param ReferenceGuard       $guard     Finds where an attachment is used.
	 * @param RetentionStore       $retention Owns the retained files.
	 * @param Logger               $logger    Summary log.
	 * @param callable(): int|null $clock     Current Unix time. Defaults to time().
	 * @param int                  $page_size IDs fetched per query.
	 * @param int                  $max_pages Most pages a check reads.
	 * @throws \InvalidArgumentException When a page setting is below 1.
	 */
	public function __construct(
		private readonly ReferenceGuard $guard,
		private readonly RetentionStore $retention,
		private readonly Logger $logger,
		?callable $clock = null,
		private readonly int $page_size = 50,
		private readonly int $max_pages = 20
	) {
		if ( $page_size < 1 || $max_pages < 1 ) {
			throw new \InvalidArgumentException( 'Paging settings must be at least 1.' );
		}

		$this->clock = $clock ?? static fn (): int => time();
	}

	/**
	 * Runs every check once and returns the report.
	 *
	 * @return MediaAuditReport
	 */
	public function run(): MediaAuditReport {
		$findings  = array();
		$failures  = array();
		$truncated = array();
		$purged    = 0;
		$checks    = array(
			'products_without_images'       => fn (): array => $this->products_without_images(),
			'categories_without_thumbnails' => fn (): array => $this->categories_without_thumbnails(),
			'reels_without_source'          => fn (): array => $this->reels_without_source(),
			'unreferenced_attachments'      => fn (): array => $this->unreferenced_attachments(),
		);

		foreach ( $checks as $name => $check ) {
			try {
				$outcome           = $check();
				$findings[ $name ] = $outcome['findings'];

				if ( $outcome['truncated'] ) {
					$truncated[] = $name;
				}

				if ( '' !== $outcome['failure'] ) {
					$failures[ $name ] = $outcome['failure'];
				}
			} catch ( \Throwable $failure ) {
				$failures[ $name ] = 'check_failed';
			}
		}

		try {
			$purged = $this->retention->purge_older_than( self::RETENTION_DAYS, self::RETENTION_LIMIT );
		} catch ( \Throwable $failure ) {
			$failures['retention_cleanup'] = 'check_failed';
		}

		return new MediaAuditReport( $findings, $failures, $truncated, $purged );
	}

	/**
	 * The scheduled entry point: runs the audit and logs a one-line summary.
	 *
	 * @return void
	 */
	public function cron(): void {
		$this->logger->log( Logger::INFO, $this->run()->summary() );
	}

	/**
	 * Published products with no featured image and no gallery image.
	 *
	 * @return array{findings:array<int,array{id:int,edit_link:string}>,truncated:bool,failure:string}
	 */
	private function products_without_images(): array {
		return $this->scan_posts(
			'rj_product',
			'publish',
			function ( array $ids ): array {
				update_meta_cache( 'post', $ids );

				$candidates = array();

				foreach ( $ids as $id ) {
					$candidates[ $id ] = array_merge(
						array( (int) get_post_meta( $id, '_thumbnail_id', true ) ),
						$this->ids_of( get_post_meta( $id, '_rj_gallery', true ) )
					);
				}

				$real = $this->existing_attachments( array_merge( ...array_values( $candidates ) ) );

				return array_values(
					array_filter(
						$ids,
						static fn ( int $id ): bool => array() === array_filter( $candidates[ $id ], static fn ( int $a ): bool => isset( $real[ $a ] ) )
					)
				);
			}
		);
	}

	/**
	 * Category terms with no category image.
	 *
	 * @return array{findings:array<int,array{id:int,edit_link:string}>,truncated:bool,failure:string}
	 */
	private function categories_without_thumbnails(): array {
		$findings  = array();
		$truncated = false;

		for ( $page = 0; $page < $this->max_pages; $page++ ) {
			$ids = get_terms(
				array(
					'taxonomy'   => 'rj_category',
					'hide_empty' => false,
					'fields'     => 'ids',
					'number'     => $this->page_size,
					'offset'     => $page * $this->page_size,
					'orderby'    => 'term_id',
					'order'      => 'ASC',
				)
			);

			if ( is_wp_error( $ids ) ) {
				return array(
					'findings'  => $findings,
					'truncated' => false,
					'failure'   => 'terms_unavailable',
				);
			}

			$ids = array_map( 'intval', $ids );

			update_meta_cache( 'term', $ids );

			$real = $this->existing_attachments( array_map( fn ( int $id ): int => (int) get_term_meta( $id, '_rj_image_id', true ), $ids ) );

			foreach ( $ids as $id ) {
				if ( ! isset( $real[ (int) get_term_meta( $id, '_rj_image_id', true ) ] ) ) {
					$link       = get_edit_term_link( $id, 'rj_category' );
					$findings[] = array(
						'id'        => $id,
						'edit_link' => is_string( $link ) ? $link : '',
					);
				}
			}

			if ( count( $ids ) < $this->page_size ) {
				return array(
					'findings'  => $findings,
					'truncated' => false,
					'failure'   => '',
				);
			}

			$truncated = $page === $this->max_pages - 1;
		}

		return array(
			'findings'  => $findings,
			'truncated' => $truncated,
			'failure'   => '',
		);
	}

	/**
	 * Reels whose own source cannot be resolved.
	 *
	 * An upload reel needs a video attachment; an Instagram or YouTube reel needs a
	 * provider video ID. A reel pointing at an external URL has no frozen key, so it
	 * is not judged here.
	 *
	 * @return array{findings:array<int,array{id:int,edit_link:string}>,truncated:bool,failure:string}
	 */
	private function reels_without_source(): array {
		return $this->scan_posts(
			'rj_reel',
			array( 'publish', 'draft', 'pending', 'private', 'future' ),
			function ( array $ids ): array {
				update_meta_cache( 'post', $ids );

				$videos = array();

				foreach ( $ids as $id ) {
					$videos[ $id ] = (int) get_post_meta( $id, '_rj_attachment_id', true );
				}

				$real = $this->existing_attachments( array_values( $videos ) );

				return array_values(
					array_filter(
						$ids,
						function ( int $id ) use ( $videos, $real ): bool {
							$type = (string) get_post_meta( $id, '_rj_source_type', true );
							$type = '' === $type ? 'upload' : $type;

							if ( 'upload' === $type ) {
								return ! isset( $real[ $videos[ $id ] ] );
							}

							if ( 'instagram' === $type || 'youtube' === $type ) {
								return '' === trim( (string) get_post_meta( $id, '_rj_video_id', true ) );
							}

							return false;
						}
					)
				);
			}
		);
	}

	/**
	 * Attachments older than the threshold that nothing references.
	 *
	 * Age is the attachment's post date. Exactly on the threshold is not "more than".
	 *
	 * @return array{findings:array<int,array{id:int,edit_link:string}>,truncated:bool,failure:string}
	 */
	private function unreferenced_attachments(): array {
		$cutoff  = gmdate( 'Y-m-d H:i:s', ( $this->clock )() - self::UNREFERENCED_DAYS * self::DAY );
		$failure = '';
		$outcome = $this->scan_posts(
			'attachment',
			'inherit',
			function ( array $ids ) use ( &$failure ): array {
				$loose = array();

				foreach ( $ids as $id ) {
					try {
						if ( ! $this->guard->find( $id )->is_referenced() ) {
							$loose[] = $id;
						}
					} catch ( \Throwable $problem ) {
						$failure = 'scan_failed';
					}
				}

				return $loose;
			},
			array(
				array(
					'column'    => 'post_date_gmt',
					'before'    => $cutoff,
					'inclusive' => false,
				),
			)
		);

		$outcome['failure'] = '' !== $outcome['failure'] ? $outcome['failure'] : $failure;

		return $outcome;
	}

	/**
	 * Reads posts of one type page by page and keeps the IDs a filter selects.
	 *
	 * @param string                         $type   Post type.
	 * @param string|array<int,string>       $status Post status.
	 * @param callable                       $select Receives the IDs of a page and returns those to report.
	 * @param array<int,array<string,mixed>> $dates  Optional date query.
	 * @return array{findings:array<int,array{id:int,edit_link:string}>,truncated:bool,failure:string}
	 */
	private function scan_posts( string $type, string|array $status, callable $select, array $dates = array() ): array {
		$findings  = array();
		$truncated = false;

		for ( $page = 1; $page <= $this->max_pages; $page++ ) {
			$args = array(
				'post_type'              => $type,
				'post_status'            => $status,
				'fields'                 => 'ids',
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'posts_per_page'         => $this->page_size,
				'paged'                  => $page,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'rj_internal'            => true,
			);

			if ( array() !== $dates ) {
				$args['date_query'] = $dates;
			}

			$ids = array_map( 'intval', get_posts( $args ) );

			foreach ( $select( $ids ) as $id ) {
				$link       = get_edit_post_link( $id, 'raw' );
				$findings[] = array(
					'id'        => $id,
					'edit_link' => is_string( $link ) ? $link : '',
				);
			}

			if ( count( $ids ) < $this->page_size ) {
				return array(
					'findings'  => $findings,
					'truncated' => false,
					'failure'   => '',
				);
			}

			$truncated = $page === $this->max_pages;
		}

		return array(
			'findings'  => $findings,
			'truncated' => $truncated,
			'failure'   => '',
		);
	}

	/**
	 * Which of the given IDs are real attachments, found with one query.
	 *
	 * @param array<int,int> $ids Candidate IDs.
	 * @return array<int,true> Existing attachment IDs as keys.
	 */
	private function existing_attachments( array $ids ): array {
		$ids = array_values( array_unique( array_filter( $ids, static fn ( int $id ): bool => $id > 0 ) ) );

		if ( array() === $ids ) {
			return array();
		}

		$found = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'any',
				'post__in'       => $ids,
				'fields'         => 'ids',
				'orderby'        => 'none',
				'posts_per_page' => count( $ids ),
				'no_found_rows'  => true,
			)
		);

		return array_fill_keys( array_map( 'intval', $found ), true );
	}

	/**
	 * Positive integers from a stored list value.
	 *
	 * @param mixed $value Stored value.
	 * @return array<int,int>
	 */
	private function ids_of( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'intval', $value ), static fn ( int $id ): bool => $id > 0 ) );
	}
}
