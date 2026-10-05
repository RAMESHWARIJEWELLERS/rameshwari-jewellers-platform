<?php
/**
 * Reel poster capability.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Services\Media;

use Rameshwari\Core\Services\Media\Value\PosterResult;

/**
 * Receives a reel poster image and makes it an ordinary attachment (blueprint §11.8).
 *
 * The admin uploader grabs a frame from the video in the browser and sends it
 * as an image upload; nothing here reads video or runs an external program.
 * The image goes through UploadValidator, becomes a normal WordPress
 * attachment with the registered sizes (including "reel poster") and gets its
 * modern-format siblings through Formats. Binding the attachment to a reel is
 * the reel owner's job: this class stores nothing about reels, writes no meta
 * and keeps no state. A poster that cannot be received is a failed result, so
 * a reel never depends on one.
 */
final class PosterExtractor {

	/**
	 * Slot name given to the validator. It is not the reel slot, so a video is refused.
	 */
	public const SLOT = 'reel_poster';

	private const CONTRACT = 'reel poster';

	/**
	 * Builds the service.
	 *
	 * @param UploadValidator $validator Hard validation of the incoming image.
	 * @param Sizes           $sizes     The registered size definitions.
	 * @param Formats         $formats   Modern-format sibling writer.
	 */
	public function __construct(
		private readonly UploadValidator $validator,
		private readonly Sizes $sizes,
		private readonly Formats $formats
	) {
	}

	/**
	 * The registered poster size, read from Sizes, so the uploader knows what to capture.
	 *
	 * @return array{name:string,key:string,width:int,height:int}
	 * @throws \LogicException When the poster size is not registered.
	 */
	public function contract(): array {
		$definition = $this->sizes->all()[ self::CONTRACT ] ?? null;

		if ( null === $definition ) {
			throw new \LogicException( 'The reel poster size is not registered.' );
		}

		return array(
			'name'   => self::CONTRACT,
			'key'    => $definition['key'],
			'width'  => $definition['width'],
			'height' => $definition['height'],
		);
	}

	/**
	 * Receives an uploaded poster image.
	 *
	 * The file is moved into the uploads folder by WordPress, so the caller's
	 * temporary file is consumed on success.
	 *
	 * @param string $tmp_path    Path of the uploaded frame.
	 * @param string $client_name File name the client gave it.
	 * @return PosterResult The new attachment, or a failure the caller can ignore.
	 */
	public function accept( string $tmp_path, string $client_name ): PosterResult {
		if ( '' === $tmp_path ) {
			return PosterResult::failed( 'no_poster' );
		}

		$check = $this->validator->validate( $tmp_path, $client_name, self::SLOT );

		if ( ! $check->is_valid() ) {
			return PosterResult::failed( $check->code() );
		}

		try {
			return $this->ingest( $tmp_path, $client_name );
		} catch ( \Throwable $failure ) {
			return PosterResult::failed( 'poster_failed' );
		}
	}

	/**
	 * Creates the attachment and writes its modern siblings.
	 *
	 * @param string $tmp_path    Validated file.
	 * @param string $client_name Validated name.
	 * @return PosterResult
	 */
	private function ingest( string $tmp_path, string $client_name ): PosterResult {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$this->sizes->register();

		$id = media_handle_sideload(
			array(
				'name'     => $client_name,
				'tmp_name' => $tmp_path,
			),
			0
		);

		if ( is_wp_error( $id ) ) {
			return PosterResult::failed( 'ingest_failed' );
		}

		$meta = wp_get_attachment_metadata( $id );

		if ( is_array( $meta ) ) {
			$this->formats->convert( $meta, $id );
		}

		return PosterResult::succeeded( $id );
	}
}
