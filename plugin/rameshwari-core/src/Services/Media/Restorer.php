<?php
/**
 * Media restorer.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Services\Media;

use Rameshwari\Core\Services\Media\Value\RestoreCandidates;
use Rameshwari\Core\Services\Media\Value\RestoreResult;
use Rameshwari\Core\Support\Lock;
use Rameshwari\Core\Support\Logger;

/**
 * Brings back an earlier retained version of an attachment's file (blueprint §11.4).
 *
 * RetentionStore owns the retained files and does the file swap, keeping the
 * current file as a new artifact first. This class adds what a restore of a live
 * image needs around it: the same lock Replacer uses, a check that the restored
 * bytes are a readable image, and rebuilt sizes and modern formats. If the
 * restored file is unusable or its sizes cannot be rebuilt, the file it replaced
 * is put back from that new artifact and the earlier metadata is restored. The
 * attachment ID and every reference are never written.
 */
final class Restorer {

	private const LOCK_TTL = 300;

	/**
	 * Builds the restorer.
	 *
	 * @param RetentionStore $retention Owns the retained files.
	 * @param Formats        $formats   Modern-format siblings.
	 * @param Lock           $lock      Per-attachment lock shared with Replacer.
	 * @param Logger         $logger    Failure log.
	 */
	public function __construct(
		private readonly RetentionStore $retention,
		private readonly Formats $formats,
		private readonly Lock $lock,
		private readonly Logger $logger
	) {
	}

	/**
	 * The versions that can be restored for an attachment, newest first.
	 *
	 * @param int $id Attachment ID.
	 * @return RestoreCandidates
	 * @throws \InvalidArgumentException When the ID is not positive.
	 */
	public function candidates( int $id ): RestoreCandidates {
		return new RestoreCandidates( $id, $this->retention->list( $id ) );
	}

	/**
	 * Restores one retained version. The caller has already checked who may do this.
	 *
	 * @param int    $id   Attachment ID.
	 * @param string $name Artifact name from candidates().
	 * @return RestoreResult Success with dimensions and the undo artifact, or a typed failure.
	 */
	public function restore( int $id, string $name ): RestoreResult {
		if ( $id < 1 || 'attachment' !== get_post_type( $id ) ) {
			return RestoreResult::failed( 'attachment_not_found' );
		}

		if ( ! wp_attachment_is_image( $id ) ) {
			return RestoreResult::failed( 'not_an_image_attachment' );
		}

		if ( ! $this->candidates( $id )->has( $name ) ) {
			return RestoreResult::failed( 'artifact_not_found' );
		}

		$lock  = Replacer::LOCK_PREFIX . $id;
		$token = $this->lock->acquire( $lock, self::LOCK_TTL );

		if ( null === $token ) {
			return RestoreResult::failed( 'restore_in_progress' );
		}

		try {
			return $this->swap( $id, $name );
		} catch ( \Throwable $failure ) {
			$this->logger->error( 'Media restore failed unexpectedly.', array( 'attachment' => $id ) );

			return RestoreResult::failed( 'restore_failed' );
		} finally {
			$this->lock->release( $lock, $token );
		}
	}

	/**
	 * Restores the artifact, checks it, rebuilds the sizes and undoes everything on failure.
	 *
	 * @param int    $id   Attachment ID.
	 * @param string $name Artifact name.
	 * @return RestoreResult
	 */
	private function swap( int $id, string $name ): RestoreResult {
		$path     = get_attached_file( $id );
		$before   = $this->retention->list( $id );
		$old_meta = wp_get_attachment_metadata( $id );

		if ( ! is_string( $path ) || ! is_file( $path ) ) {
			return RestoreResult::failed( 'current_file_missing' );
		}

		if ( ! $this->retention->restore( $id, $name ) ) {
			return RestoreResult::failed( 'restore_failed' );
		}

		$undo = $this->new_artifact( $id, $before );

		if ( null === $undo ) {
			$this->logger->error( 'Media restore could not find the undo artifact.', array( 'attachment' => $id ) );

			return RestoreResult::failed( 'restore_failed' );
		}

		$size = wp_getimagesize( $path );

		if ( ! is_array( $size ) || $size[0] < 1 || $size[1] < 1 ) {
			$this->undo( $id, $undo, $old_meta );

			return RestoreResult::failed( 'artifact_corrupt' );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';

		$generated = wp_generate_attachment_metadata( $id, $path );

		if ( ! is_array( $generated ) || empty( $generated['width'] ) || empty( $generated['height'] ) ) {
			$this->undo( $id, $undo, $old_meta );

			return RestoreResult::failed( 'regeneration_failed' );
		}

		$new_meta = $this->formats->convert( $generated, $id );

		wp_update_attachment_metadata( $id, $new_meta );
		$this->prune( $path, $old_meta, $new_meta );

		return RestoreResult::succeeded( (int) $new_meta['width'], (int) $new_meta['height'], $name, $undo );
	}

	/**
	 * The artifact a restore added: the one that was not listed before.
	 *
	 * @param int               $id     Attachment ID.
	 * @param array<int,string> $before Names listed before the restore.
	 * @return string|null
	 */
	private function new_artifact( int $id, array $before ): ?string {
		foreach ( $this->retention->list( $id ) as $name ) {
			if ( ! in_array( $name, $before, true ) ) {
				return $name;
			}
		}

		return null;
	}

	/**
	 * Puts the replaced file back from its artifact and restores the earlier metadata.
	 *
	 * RetentionStore keeps the unusable file as a further artifact, so nothing is lost.
	 *
	 * @param int    $id       Attachment ID.
	 * @param string $undo     Artifact holding the replaced file.
	 * @param mixed  $old_meta Metadata before the restore.
	 * @return void
	 */
	private function undo( int $id, string $undo, mixed $old_meta ): void {
		if ( ! $this->retention->restore( $id, $undo ) ) {
			$this->logger->error( 'Media restore could not be undone.', array( 'attachment' => $id ) );

			return;
		}

		if ( is_array( $old_meta ) ) {
			wp_update_attachment_metadata( $id, $old_meta );
		}
	}

	/**
	 * Removes sizes of the previous image that the restored image no longer has.
	 *
	 * @param string $path     Current file.
	 * @param mixed  $old_meta Metadata before the restore.
	 * @param mixed  $new_meta Metadata after it.
	 * @return void
	 */
	private function prune( string $path, mixed $old_meta, mixed $new_meta ): void {
		$old = is_array( $old_meta ) && is_array( $old_meta['sizes'] ?? null ) ? $old_meta['sizes'] : array();
		$new = is_array( $new_meta ) && is_array( $new_meta['sizes'] ?? null ) ? array_column( $new_meta['sizes'], 'file' ) : array();

		foreach ( $old as $size ) {
			$file = is_array( $size ) ? ( $size['file'] ?? null ) : null;

			if ( is_string( $file ) && '' !== $file && ! in_array( $file, $new, true ) ) {
				wp_delete_file( dirname( $path ) . '/' . basename( $file ) );
			}
		}
	}
}
