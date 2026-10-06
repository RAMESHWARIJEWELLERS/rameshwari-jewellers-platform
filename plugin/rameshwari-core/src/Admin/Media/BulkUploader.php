<?php
/**
 * Bulk uploader.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Admin\Media;

use Rameshwari\Core\Services\Media\AltText;
use Rameshwari\Core\Services\Media\UploadValidator;
use Rameshwari\Core\Services\Media\Value\AltContext;
use Rameshwari\Core\Support\Logger;

/**
 * Admin entry point for uploading one image per request (blueprint §11.10).
 *
 * The browser queues files, sends them one at a time and keeps its own record of
 * the finished ones, so a resume re-sends only what is left. There is no byte-range
 * resume and no server-side state. Every request: nonce, upload capability, the
 * business capability for the slot, the object check when a post is named, hard
 * validation, WordPress's own upload handling, then alt text if the attachment has
 * none. Modern formats are added by the metadata filter that MediaModule hooks, so
 * this class does not convert anything itself. It does not attach the new image to
 * an owner and it registers no route.
 */
final class BulkUploader {

	/**
	 * Admin AJAX action name.
	 */
	public const ACTION = 'rj_media_upload';

	/**
	 * Nonce action.
	 */
	public const NONCE_ACTION = 'rj_media_upload';

	/**
	 * Name of the uploaded file field.
	 */
	public const FIELD = 'file';

	/**
	 * Upload slot and the business capability that goes with it (blueprint §13).
	 */
	private const SLOTS = array(
		'product'  => 'rj_manage_catalogue',
		'category' => 'rj_manage_categories',
		'reel'     => 'rj_manage_reels',
	);

	/**
	 * Stores the file in the media library.
	 *
	 * @var callable(array<string,mixed>,int):mixed
	 */
	private $store;

	/**
	 * Sends the JSON reply.
	 *
	 * @var callable(int,array<string,mixed>):void
	 */
	private $responder;

	/**
	 * Builds the uploader.
	 *
	 * @param UploadValidator $validator Hard validation of the incoming file.
	 * @param AltText         $alt       Alt text for the new attachment.
	 * @param Logger          $logger    Failure log.
	 * @param callable|null   $store     Stores the file and returns an attachment ID or WP_Error. Defaults to media_handle_upload().
	 * @param callable|null   $responder Sends the reply. Defaults to wp_send_json().
	 */
	public function __construct(
		private readonly UploadValidator $validator,
		private readonly AltText $alt,
		private readonly Logger $logger,
		?callable $store = null,
		?callable $responder = null
	) {
		$this->store     = $store ?? static function ( array $file, int $post_id ): mixed {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';

			return media_handle_upload( self::FIELD, $post_id );
		};
		$this->responder = $responder ?? static function ( int $status, array $body ): void {
			wp_send_json( $body, $status );
		};
	}

	/**
	 * Handles the AJAX request: nonce first, then everything in upload().
	 *
	 * @return void
	 */
	public function handle(): void {
		if ( false === check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			$this->reply( 403, self::error( 'bad_nonce' ) );

			return;
		}

		$slot    = isset( $_POST['slot'] ) ? sanitize_key( wp_unslash( $_POST['slot'] ) ) : '';
		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
		$file    = isset( $_FILES[ self::FIELD ] ) && is_array( $_FILES[ self::FIELD ] ) ? $_FILES[ self::FIELD ] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each element is validated by upload() and UploadValidator.

		$result = $this->upload( $slot, $post_id, $file );

		$this->reply( $result['status'], $result['body'] );
	}

	/**
	 * Checks and stores one file. The nonce has already been verified.
	 *
	 * @param string              $slot    Upload slot: product, category or reel.
	 * @param int                 $post_id Owner post, or 0 when none is named.
	 * @param array<string,mixed> $file    Entry of the uploaded-files array.
	 * @return array{status:int,body:array<string,mixed>}
	 */
	public function upload( string $slot, int $post_id, array $file ): array {
		if ( ! isset( self::SLOTS[ $slot ] ) ) {
			return self::fail( 400, 'invalid_slot' );
		}

		if ( ! current_user_can( 'upload_files' ) || ! current_user_can( self::SLOTS[ $slot ] ) ) {
			return self::fail( 403, 'forbidden' );
		}

		if ( $post_id > 0 && ! current_user_can( 'edit_post', $post_id ) ) {
			return self::fail( 403, 'forbidden_object' );
		}

		$tmp  = $file['tmp_name'] ?? null;
		$name = $file['name'] ?? null;

		if ( ! is_string( $tmp ) || '' === $tmp || ! is_string( $name ) || '' === $name || UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_OK ) ) {
			return self::fail( 400, 'upload_error' );
		}

		$validation = $this->validator->validate( $tmp, $name, $slot );

		if ( ! $validation->is_valid() ) {
			return self::fail( 400, $validation->code() );
		}

		$id = $this->store_scoped( $file, $post_id );

		if ( $id < 1 ) {
			$this->logger->error( 'Bulk upload could not store a file.', array( 'slot' => $slot ) );

			return self::fail( 500, 'store_failed' );
		}

		$this->fill_alt( $id, $post_id );

		return array(
			'status' => 200,
			'body'   => array(
				'status'        => 'ok',
				'attachment_id' => $id,
				'error_code'    => '',
			),
		);
	}

	/**
	 * Stores the file with the upload types limited to images, then lifts the limit.
	 *
	 * @param array<string,mixed> $file    Uploaded-file entry.
	 * @param int                 $post_id Owner post, or 0.
	 * @return int Attachment ID, or 0 on failure.
	 */
	private function store_scoped( array $file, int $post_id ): int {
		add_filter( 'upload_mimes', array( self::class, 'image_mimes' ) );

		try {
			$id = ( $this->store )( $file, $post_id );
		} catch ( \Throwable $failure ) {
			$id = 0;
		} finally {
			remove_filter( 'upload_mimes', array( self::class, 'image_mimes' ) );
		}

		return is_int( $id ) && $id > 0 ? $id : 0;
	}

	/**
	 * The upload types the pipeline accepts.
	 *
	 * @param mixed $mimes Types WordPress would allow.
	 * @return array<string,string>
	 */
	public static function image_mimes( mixed $mimes ): array {
		unset( $mimes );

		return array(
			'jpg|jpeg|jpe' => 'image/jpeg',
			'png'          => 'image/png',
			'webp'         => 'image/webp',
		);
	}

	/**
	 * Fills the alt text from the owner post's title when the attachment has none.
	 *
	 * @param int $id      Attachment ID.
	 * @param int $post_id Owner post, or 0.
	 * @return void
	 */
	private function fill_alt( int $id, int $post_id ): void {
		if ( $post_id < 1 ) {
			return;
		}

		$title = wp_strip_all_tags( get_the_title( $post_id ) );

		if ( '' === trim( $title ) ) {
			return;
		}

		try {
			$this->alt->apply( $id, new AltContext( $title, '' ) );
		} catch ( \InvalidArgumentException $failure ) {
			return;
		}
	}

	/**
	 * Sends the reply through the responder.
	 *
	 * @param int                 $status HTTP status.
	 * @param array<string,mixed> $body   JSON body.
	 * @return void
	 */
	private function reply( int $status, array $body ): void {
		( $this->responder )( $status, $body );
	}

	/**
	 * A failed upload.
	 *
	 * @param int    $status HTTP status.
	 * @param string $code   Error code.
	 * @return array{status:int,body:array<string,mixed>}
	 */
	private static function fail( int $status, string $code ): array {
		return array(
			'status' => $status,
			'body'   => self::error( $code ),
		);
	}

	/**
	 * The JSON body of a failure.
	 *
	 * @param string $code Error code.
	 * @return array<string,mixed>
	 */
	private static function error( string $code ): array {
		return array(
			'status'        => 'error',
			'attachment_id' => 0,
			'error_code'    => $code,
		);
	}
}
