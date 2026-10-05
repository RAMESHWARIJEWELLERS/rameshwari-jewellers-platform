<?php
/**
 * Media regenerator.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Services\Media;

use Rameshwari\Core\Services\Media\Value\RegenerationResult;

/**
 * Rebuilds the registered sub-sizes and modern siblings of existing images (blueprint §11.7).
 *
 * The caller sends the last attachment ID it saw and a batch size; the batch
 * is the next image attachments in ascending ID order. The server keeps no
 * cursor, job or status: the result carries the cursor to send next. An
 * attachment is skipped when every registered size that applies to it exists
 * with the current dimensions. Only the registered sizes are touched, existing
 * metadata is kept, and originals are never deleted or replaced. One
 * attachment failing is reported and never stops the batch.
 */
final class Regenerator {

	/**
	 * Largest batch accepted. A conservative bound, not a documented limit.
	 */
	public const MAX_BATCH = 100;

	/**
	 * Builds the service.
	 *
	 * @param Sizes   $sizes   The registered size definitions.
	 * @param Formats $formats Modern-format sibling writer.
	 */
	public function __construct( private readonly Sizes $sizes, private readonly Formats $formats ) {
	}

	/**
	 * Processes the next batch of image attachments after a cursor.
	 *
	 * @param int $cursor     ID of the last attachment already handled; 0 starts from the beginning.
	 * @param int $batch_size How many attachments to examine, from 1 to MAX_BATCH.
	 * @return RegenerationResult
	 * @throws \InvalidArgumentException When the cursor or batch size is out of range.
	 */
	public function regenerate( int $cursor, int $batch_size ): RegenerationResult {
		if ( $cursor < 0 ) {
			throw new \InvalidArgumentException( 'The cursor cannot be negative.' );
		}

		if ( $batch_size < 1 || $batch_size > self::MAX_BATCH ) {
			throw new \InvalidArgumentException( esc_html( 'The batch size must be from 1 to ' . self::MAX_BATCH . '.' ) );
		}

		$this->sizes->register();

		$ids         = $this->next_ids( $cursor, $batch_size );
		$regenerated = 0;
		$skipped     = 0;
		$failures    = array();
		$last        = $cursor;

		foreach ( $ids as $id ) {
			$last = $id;

			try {
				$outcome = $this->handle( $id );
			} catch ( \Throwable $failure ) {
				$outcome = array( 'failed', 'regenerate_failed' );
			}

			if ( 'regenerated' === $outcome[0] ) {
				++$regenerated;
			} elseif ( 'skipped' === $outcome[0] ) {
				++$skipped;
			} else {
				$failures[ $id ] = $outcome[1];
			}
		}

		return new RegenerationResult( $last, $regenerated, $skipped, $failures, count( $ids ) < $batch_size );
	}

	/**
	 * The next image attachment IDs above the cursor, ascending.
	 *
	 * @param int $cursor Last ID handled.
	 * @param int $limit  Maximum IDs.
	 * @return array<int,int>
	 */
	private function next_ids( int $cursor, int $limit ): array {
		global $wpdb;

		$above = static fn ( string $where ): string => $where . ' AND ' . $wpdb->posts . '.ID > ' . $cursor;

		add_filter( 'posts_where', $above );

		try {
			$ids = get_posts(
				array(
					'post_type'              => 'attachment',
					'post_status'            => array( 'inherit', 'private' ),
					'post_mime_type'         => 'image',
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'posts_per_page'         => $limit,
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
					'suppress_filters'       => false,
				)
			);
		} finally {
			remove_filter( 'posts_where', $above );
		}

		return array_map( 'intval', $ids );
	}

	/**
	 * Rebuilds one attachment if it needs it.
	 *
	 * @param int $id Attachment ID.
	 * @return array{0:string,1:string} Status (regenerated, skipped, failed) and failure code.
	 */
	private function handle( int $id ): array {
		$file     = get_attached_file( $id );
		$original = wp_get_original_image_path( $id );
		$meta     = wp_get_attachment_metadata( $id );

		if ( ! is_string( $file ) || ! is_file( $file ) || ! is_string( $original ) || ! is_file( $original ) ) {
			return array( 'failed', 'source_missing' );
		}

		if ( ! is_array( $meta ) || self::number( $meta['width'] ?? 0 ) < 1 || self::number( $meta['height'] ?? 0 ) < 1 ) {
			return array( 'failed', 'metadata_missing' );
		}

		$missing = $this->stale_keys( $meta, $file );
		$before  = $this->sibling_count( $meta, $file );
		$prior   = self::sizes_of( $meta );

		if ( array() !== $missing ) {
			$meta = $this->rebuild( $id, $meta, $missing );

			if ( null === $meta ) {
				return array( 'failed', 'regenerate_failed' );
			}
		}

		$meta = $this->formats->convert( $meta, $id );
		$left = array_values( array_intersect( $missing, $this->stale_keys( $meta, $file ) ) );

		if ( array() !== $left ) {
			$this->restore_entries( $id, $meta, $prior, $left );

			return array( 'failed', 'size_not_generated' );
		}

		$changed = array() !== $missing || $this->sibling_count( $meta, $file ) > $before;

		return array( $changed ? 'regenerated' : 'skipped', '' );
	}

	/**
	 * Drops the stale entries, asks WordPress to rebuild the missing sizes and returns the new metadata.
	 *
	 * Only the registered Stage 7 sizes are built: WordPress asks for its missing
	 * sizes through the wp_get_missing_image_subsizes filter, which is narrowed to them. On failure the metadata is put back.
	 *
	 * @param int                 $id      Attachment ID.
	 * @param array<string,mixed> $meta    Current metadata.
	 * @param array<int,string>   $missing Size keys to rebuild.
	 * @return array<string,mixed>|null New metadata, or null on failure.
	 */
	private function rebuild( int $id, array $meta, array $missing ): ?array {
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$trimmed = $meta;
		$sizes   = self::sizes_of( $meta );

		foreach ( $missing as $key ) {
			unset( $sizes[ $key ] );
		}

		$trimmed['sizes'] = $sizes;

		if ( self::sizes_of( $meta ) !== $sizes ) {
			wp_update_attachment_metadata( $id, $trimmed );
		}

		$only = array_flip( array_column( $this->sizes->all(), 'key' ) );
		$keep = static fn ( array $missing ): array => array_intersect_key( $missing, $only );

		add_filter( 'wp_get_missing_image_subsizes', $keep );

		try {
			$updated = wp_update_image_subsizes( $id );
		} finally {
			remove_filter( 'wp_get_missing_image_subsizes', $keep );
		}

		if ( is_wp_error( $updated ) || ! is_array( $updated ) ) {
			wp_update_attachment_metadata( $id, $meta );

			return null;
		}

		return $updated;
	}

	/**
	 * Puts back the earlier entries of sizes that still could not be rebuilt.
	 *
	 * @param int                 $id    Attachment ID.
	 * @param array<string,mixed> $meta  Current metadata.
	 * @param array<string,mixed> $prior Sizes before the attempt.
	 * @param array<int,string>   $left  Keys still missing.
	 * @return void
	 */
	private function restore_entries( int $id, array $meta, array $prior, array $left ): void {
		$sizes = self::sizes_of( $meta );

		foreach ( $left as $key ) {
			if ( isset( $prior[ $key ] ) ) {
				$sizes[ $key ] = $prior[ $key ];
			}
		}

		$meta['sizes'] = $sizes;

		wp_update_attachment_metadata( $id, $meta );
	}

	/**
	 * The registered size keys that are absent, outdated or missing their file.
	 *
	 * A size is judged against the dimensions WordPress would produce from the
	 * full image today. A size that cannot be produced, or would equal the full
	 * image, does not apply and is never reported.
	 *
	 * @param array<string,mixed> $meta Attachment metadata.
	 * @param string              $file Full-size file path.
	 * @return array<int,string>
	 */
	private function stale_keys( array $meta, string $file ): array {
		$width  = self::number( $meta['width'] ?? 0 );
		$height = self::number( $meta['height'] ?? 0 );
		$sizes  = self::sizes_of( $meta );
		$stale  = array();

		foreach ( $this->sizes->all() as $definition ) {
			$box = image_resize_dimensions(
				$width,
				$height,
				$definition['width'],
				$definition['height'],
				'hard' === $definition['crop'] ? true : array( 'center', 'center' )
			);

			if ( ! is_array( $box ) ) {
				continue;
			}

			$expected_width  = self::number( $box[4] );
			$expected_height = self::number( $box[5] );

			if ( $expected_width === $width && $expected_height === $height ) {
				continue;
			}

			$entry = $sizes[ $definition['key'] ] ?? null;
			$name  = is_array( $entry ) && is_string( $entry['file'] ?? null ) ? basename( $entry['file'] ) : '';

			if (
				! is_array( $entry )
				|| self::number( $entry['width'] ?? 0 ) !== $expected_width
				|| self::number( $entry['height'] ?? 0 ) !== $expected_height
				|| '' === $name
				|| ! is_file( dirname( $file ) . '/' . $name )
			) {
				$stale[] = $definition['key'];
			}
		}

		return $stale;
	}

	/**
	 * How many modern siblings exist beside the generated sizes.
	 *
	 * @param array<string,mixed> $meta Attachment metadata.
	 * @param string              $file Full-size file path.
	 * @return int
	 */
	private function sibling_count( array $meta, string $file ): int {
		$count = 0;

		foreach ( self::sizes_of( $meta ) as $entry ) {
			if ( is_array( $entry ) && is_string( $entry['file'] ?? null ) ) {
				$count += count( $this->formats->siblings( dirname( $file ) . '/' . basename( $entry['file'] ) ) );
			}
		}

		return $count;
	}

	/**
	 * The sizes array of the metadata, or an empty array.
	 *
	 * @param array<string,mixed> $meta Attachment metadata.
	 * @return array<string,mixed>
	 */
	private static function sizes_of( array $meta ): array {
		$sizes = $meta['sizes'] ?? null;

		return is_array( $sizes ) ? $sizes : array();
	}

	/**
	 * An integer from a value that should be numeric, otherwise 0.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	private static function number( mixed $value ): int {
		return is_numeric( $value ) ? (int) $value : 0;
	}
}
