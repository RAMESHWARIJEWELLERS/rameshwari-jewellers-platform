<?php
/**
 * Media replacer.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Services\Media;

use Rameshwari\Core\Services\Media\Value\ReplaceResult;
use Rameshwari\Core\Support\Lock;
use Rameshwari\Core\Support\Logger;

/**
 * Replaces the file behind an existing attachment and keeps its ID (blueprint §11.3).
 *
 * Three things stay distinct: the canonical attachment (the ID), its current file,
 * and the retained historical artifact. References point at the ID, so none of
 * them change. RetentionStore keeps the previous file before the swap. If anything
 * fails afterwards, the file is restored from that artifact, its sizes and
 * metadata are rebuilt, and the lock is released. The new file gets its own
 * sanitised, collision-safe name in the same uploads folder, so its extension
 * may differ from the old one. Nothing is stored beyond WordPress's own
 * attachment file and metadata.
 */
final class Replacer {

	/**
	 * Prefix of the per-attachment lock name.
	 */
	public const LOCK_PREFIX = 'media_replace_';

	private const LOCK_TTL = 300;

	/**
	 * Builds the replacer.
	 *
	 * @param UploadValidator $validator Hard validation of the incoming file.
	 * @param RetentionStore  $retention Keeps the previous file.
	 * @param Formats         $formats   Modern-format siblings.
	 * @param Lock            $lock      Per-attachment lock.
	 * @param Logger          $logger    Failure log.
	 */
	public function __construct(
		private readonly UploadValidator $validator,
		private readonly RetentionStore $retention,
		private readonly Formats $formats,
		private readonly Lock $lock,
		private readonly Logger $logger
	) {
	}

	/**
	 * Replaces an attachment's file. The caller has already checked who may do this.
	 *
	 * @param int    $id          Attachment ID.
	 * @param string $tmp_path    Incoming temporary file.
	 * @param string $client_name Name the file arrived with.
	 * @param string $slot        Upload slot, passed to UploadValidator unchanged.
	 * @return ReplaceResult Success with dimensions and artifact name, or a typed failure.
	 */
	public function replace( int $id, string $tmp_path, string $client_name, string $slot ): ReplaceResult {
		if ( '' === $slot ) {
			return ReplaceResult::failed( 'slot_required' );
		}

		$validation = $this->validator->validate( $tmp_path, $client_name, $slot );

		if ( ! $validation->is_valid() ) {
			return ReplaceResult::failed( $validation->code() );
		}

		if ( $id < 1 || 'attachment' !== get_post_type( $id ) ) {
			return ReplaceResult::failed( 'attachment_not_found' );
		}

		if ( ! wp_attachment_is_image( $id ) ) {
			return ReplaceResult::failed( 'not_an_image_attachment' );
		}

		$path = get_attached_file( $id );

		if ( ! is_string( $path ) || ! is_file( $path ) || ! is_readable( $path ) ) {
			return ReplaceResult::failed( 'current_file_missing' );
		}

		$token = $this->lock->acquire( self::LOCK_PREFIX . $id, self::LOCK_TTL );

		if ( null === $token ) {
			return ReplaceResult::failed( 'replace_in_progress' );
		}

		try {
			return $this->swap( $id, $tmp_path, $client_name, $path );
		} catch ( \Throwable $failure ) {
			$this->logger->error( 'Media replace failed unexpectedly.', array( 'attachment' => $id ) );

			return ReplaceResult::failed( 'replace_failed' );
		} finally {
			$this->lock->release( self::LOCK_PREFIX . $id, $token );
		}
	}

	/**
	 * Keeps the old file, stages the new one under a new name and points the attachment at it.
	 *
	 * @param int    $id          Attachment ID.
	 * @param string $tmp_path    Incoming file.
	 * @param string $client_name Incoming name.
	 * @param string $old_path    Current file.
	 * @return ReplaceResult
	 * @throws \RuntimeException When the attachment path or generated metadata cannot be updated.
	 */
	private function swap( int $id, string $tmp_path, string $client_name, string $old_path ): ReplaceResult {
		$old_meta = wp_get_attachment_metadata( $id );
		$old_mime = get_post_mime_type( $id );
		$dir      = dirname( $old_path );
		$artifact = $this->retention->keep( $id );

		if ( null === $artifact ) {
			return ReplaceResult::failed( 'retention_failed' );
		}

		$new_path = $dir . '/' . wp_unique_filename( $dir, sanitize_file_name( $client_name ) );
		$stage    = $new_path . '.rjstage';

		if ( ! $this->contained( $dir, $new_path ) || is_dir( $stage ) || ! copy( $tmp_path, $stage ) || ! $this->contained( $dir, $stage ) ) {
			$this->clean_attempt( $new_path );

			return ReplaceResult::failed( 'stage_failed' );
		}

		if ( ! rename( $stage, $new_path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Same-folder move of a staged, containment-checked copy; the previous file is already retained.
			$this->clean_attempt( $new_path );

			return ReplaceResult::failed( 'stage_failed' );
		}

		try {
			update_attached_file( $id, $new_path );

			if ( get_attached_file( $id, true ) !== $new_path || ! is_file( $new_path ) || ! is_readable( $new_path ) ) {
				throw new \RuntimeException( 'The attachment file could not be updated.' );
			}

			require_once ABSPATH . 'wp-admin/includes/image.php';

			$generated = wp_generate_attachment_metadata( $id, $new_path );

			if ( ! is_array( $generated ) || empty( $generated['width'] ) || empty( $generated['height'] ) ) {
				throw new \RuntimeException( 'The attachment metadata could not be generated.' );
			}

			$new_meta = $this->formats->convert( $generated, $id );
			$mime     = wp_check_filetype( $new_path )['type'];

			wp_update_attachment_metadata( $id, $new_meta );

			if ( is_string( $mime ) && '' !== $mime && get_post_mime_type( $id ) !== $mime ) {
				wp_update_post(
					array(
						'ID'             => $id,
						'post_mime_type' => $mime,
					)
				);
			}
		} catch ( \Throwable $failure ) {
			$this->rollback( $id, $artifact, $old_path, $old_meta, $old_mime, $new_path );

			return ReplaceResult::failed( 'replace_failed' );
		}

		$this->discard_old( $id, $old_path, $old_meta );

		return ReplaceResult::succeeded( (int) $new_meta['width'], (int) $new_meta['height'], $artifact );
	}

	/**
	 * Points the attachment back at its old path, restores the old bytes from the artifact and removes the attempt.
	 *
	 * RetentionStore::restore() writes to the attachment's current file, so the path is repointed first.
	 * It also keeps the file it overwrites as a further artifact.
	 *
	 * @param int    $id       Attachment ID.
	 * @param string $artifact Artifact holding the previous file.
	 * @param string $old_path Previous file path.
	 * @param mixed  $old_meta Metadata saved before the attempt.
	 * @param mixed  $old_mime Attachment MIME type saved before the attempt.
	 * @param string $new_path File the attempt wrote.
	 * @return void
	 */
	private function rollback( int $id, string $artifact, string $old_path, mixed $old_meta, mixed $old_mime, string $new_path ): void {
		if ( get_attached_file( $id ) !== $old_path ) {
			update_attached_file( $id, $old_path );

			if ( get_attached_file( $id, true ) !== $old_path ) {
				$relative = _wp_relative_upload_path( $old_path );

				if ( is_string( $relative ) && '' !== $relative ) {
					update_post_meta( $id, '_wp_attached_file', $relative );
				}
			}
		}

		if ( is_string( $old_mime ) && '' !== $old_mime && get_post_mime_type( $id ) !== $old_mime ) {
			wp_update_post(
				array(
					'ID'             => $id,
					'post_mime_type' => $old_mime,
				)
			);
		}

		$restored = $this->retention->restore( $id, $artifact );

		$this->clean_attempt( $new_path );

		if ( ! $restored ) {
			$this->logger->error( 'Media replace rollback could not restore the artifact.', array( 'attachment' => $id ) );

			return;
		}

		$regenerated = wp_generate_attachment_metadata( $id, $old_path );

		if ( is_array( $regenerated ) && ! empty( $regenerated['width'] ) && ! empty( $regenerated['height'] ) ) {
			wp_update_attachment_metadata( $id, $regenerated );

			return;
		}

		if ( is_array( $old_meta ) ) {
			wp_update_attachment_metadata( $id, $old_meta );
		}
	}

	/**
	 * Removes the file the attempt wrote, its staging copy and every size or format generated for it.
	 *
	 * Core may have written sizes before the failure, so they are found by name rather than from metadata.
	 *
	 * @param string $new_path File the attempt wrote.
	 * @return void
	 */
	private function clean_attempt( string $new_path ): void {
		$pattern = '~^' . preg_quote( pathinfo( $new_path, PATHINFO_FILENAME ), '~' ) . '(-\d+x\d+|-scaled)?\.[A-Za-z0-9]+(\.rjstage)?$~';

		try {
			foreach ( new \DirectoryIterator( dirname( $new_path ) ) as $item ) {
				if ( $item->isFile() && ! $item->isLink() && 1 === preg_match( $pattern, $item->getFilename() ) ) {
					wp_delete_file( $item->getPathname() );
				}
			}
		} catch ( \UnexpectedValueException $failure ) {
			return;
		}
	}

	/**
	 * Deletes the replaced file and its sizes once the new file is fully in place.
	 *
	 * @param int    $id       Attachment ID.
	 * @param string $old_path Replaced file.
	 * @param mixed  $old_meta Its metadata.
	 * @return void
	 */
	private function discard_old( int $id, string $old_path, mixed $old_meta ): void {
		if ( get_attached_file( $id ) === $old_path ) {
			return;
		}

		if ( is_array( $old_meta ) ) {
			wp_delete_attachment_files( $id, $old_meta, array(), $old_path );

			return;
		}

		wp_delete_file( $old_path );
	}

	/**
	 * Whether a path sits directly inside the folder, with no separator in its name.
	 *
	 * @param string $dir  Folder.
	 * @param string $path Path.
	 * @return bool
	 */
	private function contained( string $dir, string $path ): bool {
		$real = realpath( $dir );

		return is_string( $real ) && dirname( $path ) === $dir && basename( $path ) === substr( $path, strlen( $dir ) + 1 ) && str_starts_with( (string) realpath( dirname( $path ) ), $real );
	}
}
