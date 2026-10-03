<?php
/**
 * Attachment reference guard.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Services\Media;

use Rameshwari\Core\Services\Media\Value\ReferenceReport;
use Rameshwari\Core\Services\Media\Value\ReferenceSource;
use Rameshwari\Core\Support\Logger;

/**
 * Stops a hard delete of an attachment that something still uses (blueprint §11.5).
 *
 * Single IDs are matched exactly; lists are found with a bounded, paged
 * candidate query and then verified in PHP by exact membership. A scan error
 * blocks the delete and is logged. Nothing is written or changed by a scan.
 *
 * Residual race: an owner can add a reference between the check and the
 * delete, because assignments do not take a lock. This is accepted and stated,
 * not closed. Retention cleanup on delete belongs to the later RetentionStore.
 */
final class ReferenceGuard {

	private const PAGE_SIZE = 100;

	private const MAX_PAGES = 50;

	/**
	 * Reports of attachments this guard blocked, by attachment ID.
	 *
	 * @var array<int,ReferenceReport>
	 */
	private array $blocked = array();

	/**
	 * Builds the guard.
	 *
	 * @param ReferenceSources $sources Where references can live.
	 * @param Logger           $logger  Failure log.
	 */
	public function __construct( private readonly ReferenceSources $sources, private readonly Logger $logger ) {
	}

	/**
	 * Attaches the guard to attachment deletion. Not wired to the plugin yet.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'pre_delete_attachment', array( $this, 'filter' ), 10, 3 );
	}

	/**
	 * Detaches the guard.
	 *
	 * @return void
	 */
	public function unregister(): void {
		remove_filter( 'pre_delete_attachment', array( $this, 'filter' ), 10 );
	}

	/**
	 * The pre_delete_attachment callback. Null lets WordPress continue; false blocks.
	 *
	 * @param mixed $check        Earlier filter result.
	 * @param mixed $post         The attachment post.
	 * @param mixed $force_delete Whether this is a permanent delete.
	 * @return mixed
	 */
	public function filter( mixed $check, mixed $post, mixed $force_delete ): mixed {
		if ( null !== $check || ! $post instanceof \WP_Post ) {
			return $check;
		}

		$hard = (bool) $force_delete || 'trash' === $post->post_status || ! ( defined( 'MEDIA_TRASH' ) && MEDIA_TRASH && defined( 'EMPTY_TRASH_DAYS' ) && EMPTY_TRASH_DAYS );

		if ( ! $hard ) {
			return $check;
		}

		try {
			$report = $this->find( $post->ID );
		} catch ( \RuntimeException $failure ) {
			$this->logger->error( 'Reference scan failed; attachment delete blocked.', array( 'attachment' => $post->ID ) );

			return false;
		}

		if ( $report->is_referenced() ) {
			$this->blocked[ $post->ID ] = $report;

			return false;
		}

		return $check;
	}

	/**
	 * The report of the last block for an attachment, if this guard blocked it.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return ReferenceReport|null
	 */
	public function last_report( int $attachment_id ): ?ReferenceReport {
		return $this->blocked[ $attachment_id ] ?? null;
	}

	/**
	 * Every reference to an attachment, in a stable order.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return ReferenceReport
	 * @throws \InvalidArgumentException When the ID is not positive.
	 * @throws \RuntimeException When a scan fails or exceeds its bound.
	 */
	public function find( int $attachment_id ): ReferenceReport {
		$references = array();

		foreach ( $this->sources->all() as $source ) {
			$references = array_merge( $references, $this->scan( $source, $attachment_id ) );
		}

		usort(
			$references,
			static fn ( array $a, array $b ): int => array( $a['owner_type'], $a['owner_id'], $a['field'] ) <=> array( $b['owner_type'], $b['owner_id'], $b['field'] )
		);

		return new ReferenceReport( $attachment_id, $references );
	}

	/**
	 * References found in one source.
	 *
	 * @param ReferenceSource $source        Source.
	 * @param int             $attachment_id Attachment ID.
	 * @return array<int,array{owner_type:string,owner_id:int,field:string,edit_link:string}>
	 * @throws \RuntimeException When the scan fails.
	 */
	private function scan( ReferenceSource $source, int $attachment_id ): array {
		if ( ReferenceSource::OPTION === $source->kind() ) {
			return $this->scan_option( $source, $attachment_id );
		}

		$is_term = ReferenceSource::TERM_META === $source->kind();
		$found   = array();

		foreach ( $this->owner_ids( $source, $attachment_id, $is_term ) as $owner_id ) {
			if ( $is_term ) {
				$link = get_edit_term_link( $owner_id );

				if ( ! get_term( $owner_id ) instanceof \WP_Term ) {
					continue;
				}
			} else {
				$link = get_edit_post_link( $owner_id, 'raw' );

				if ( null === get_post( $owner_id ) ) {
					continue;
				}
			}

			$found[] = array(
				'owner_type' => $is_term ? 'term' : 'post',
				'owner_id'   => $owner_id,
				'field'      => $source->field(),
				'edit_link'  => is_string( $link ) ? $link : '',
			);
		}

		return $found;
	}

	/**
	 * Owner IDs whose meta holds the attachment, verified exactly, paged and bounded.
	 *
	 * @param ReferenceSource $source        Source.
	 * @param int             $attachment_id Attachment ID.
	 * @param bool            $is_term       Term meta rather than post meta.
	 * @return array<int,int>
	 * @throws \RuntimeException When the query fails or the candidate bound is exceeded.
	 */
	private function owner_ids( ReferenceSource $source, int $attachment_id, bool $is_term ): array {
		global $wpdb;

		$table  = $is_term ? $wpdb->termmeta : $wpdb->postmeta;
		$column = $is_term ? 'term_id' : 'post_id';
		$like   = '%' . $wpdb->esc_like( 'i:' . $attachment_id . ';' ) . '%';
		$value  = $source->is_list() ? $like : (string) $attachment_id;
		$match  = $source->is_list() ? 'LIKE' : '=';
		$owners = array();
		$offset = 0;

		for ( $page = 0; $page < self::MAX_PAGES; $page++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Bounded, paged, read-only candidate lookup; the table and column names are WordPress core constants, not input.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT {$column} AS owner, meta_value FROM {$table} WHERE meta_key = %s AND meta_value {$match} %s ORDER BY meta_id LIMIT %d OFFSET %d", $source->key(), $value, self::PAGE_SIZE, $offset ), ARRAY_A );

			if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) {
				throw new \RuntimeException( 'The reference query failed.' );
			}

			foreach ( $rows as $row ) {
				if ( $this->holds( (string) $row['meta_value'], $attachment_id, $source->is_list() ) ) {
					$owners[ (int) $row['owner'] ] = (int) $row['owner'];
				}
			}

			if ( count( $rows ) < self::PAGE_SIZE ) {
				sort( $owners );

				return $owners;
			}

			$offset += self::PAGE_SIZE;
		}

		throw new \RuntimeException( 'Too many candidate rows to scan safely.' );
	}

	/**
	 * Exact membership test. Never a substring match; malformed data is not a reference.
	 *
	 * @param string $stored        Stored meta value.
	 * @param int    $attachment_id Attachment ID.
	 * @param bool   $is_list       Whether the value should be a list.
	 * @return bool
	 */
	private function holds( string $stored, int $attachment_id, bool $is_list ): bool {
		if ( ! $is_list ) {
			return (string) $attachment_id === $stored;
		}

		$value = maybe_unserialize( $stored );

		if ( ! is_array( $value ) ) {
			return false;
		}

		foreach ( $value as $item ) {
			if ( ( is_int( $item ) || is_string( $item ) ) && (string) $attachment_id === (string) $item ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * References in one registered option field.
	 *
	 * @param ReferenceSource $source        Source.
	 * @param int             $attachment_id Attachment ID.
	 * @return array<int,array{owner_type:string,owner_id:int,field:string,edit_link:string}>
	 */
	private function scan_option( ReferenceSource $source, int $attachment_id ): array {
		$value = get_option( $source->key(), array() );

		foreach ( explode( '.', $source->path() ) as $part ) {
			if ( ! is_array( $value ) || ! array_key_exists( $part, $value ) ) {
				return array();
			}

			$value = $value[ $part ];
		}

		if ( ! ( is_int( $value ) || is_string( $value ) ) || (string) $attachment_id !== (string) $value ) {
			return array();
		}

		return array(
			array(
				'owner_type' => 'option',
				'owner_id'   => 0,
				'field'      => $source->field(),
				'edit_link'  => '',
			),
		);
	}
}
