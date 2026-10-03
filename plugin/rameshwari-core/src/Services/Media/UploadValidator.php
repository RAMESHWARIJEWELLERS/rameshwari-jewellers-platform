<?php
/**
 * Upload validation.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Services\Media;

use Rameshwari\Core\Services\Media\Value\ValidationResult;

/**
 * Hard validation of one uploaded image file.
 *
 * The client MIME type is never an input. The detected content type decides,
 * and the file name's extension must agree with it. Too-small sources are not
 * a hard failure (CL-8) and are not handled here.
 */
final class UploadValidator {

	public const MAX_BYTES = 15728640;

	public const MAX_WIDTH = 6000;

	public const MAX_HEIGHT = 6000;

	public const MAX_PIXELS = 24000000;

	public const SLOT_REEL = 'reel';

	/**
	 * Accepted detected types and the extensions allowed for each.
	 *
	 * @var array<string,array<int,string>>
	 */
	private const IMAGE_TYPES = array(
		'image/jpeg' => array( 'jpg', 'jpeg', 'jpe' ),
		'image/png'  => array( 'png' ),
		'image/webp' => array( 'webp' ),
	);

	/**
	 * Validates a file.
	 *
	 * @param string $tmp_path    Path of the file to check.
	 * @param string $client_name File name the client gave it.
	 * @param string $slot        Upload slot. Only the reel slot may carry video.
	 * @return ValidationResult
	 */
	public function validate( string $tmp_path, string $client_name, string $slot ): ValidationResult {
		if ( str_contains( $tmp_path, "\0" ) || ! is_file( $tmp_path ) || ! is_readable( $tmp_path ) ) {
			return ValidationResult::failed( 'file_missing' );
		}

		$bytes = filesize( $tmp_path );

		if ( false === $bytes ) {
			return ValidationResult::failed( 'file_missing' );
		}

		if ( $bytes > self::MAX_BYTES ) {
			return ValidationResult::failed( 'file_too_large' );
		}

		if ( ! $this->is_safe_name( $client_name ) ) {
			return ValidationResult::failed( 'unsafe_filename' );
		}

		$extension = strtolower( pathinfo( $client_name, PATHINFO_EXTENSION ) );

		if ( 'svg' === $extension || $this->looks_like_svg( $tmp_path ) ) {
			return ValidationResult::failed( 'svg_rejected' );
		}

		$mime = wp_get_image_mime( $tmp_path );

		if ( false === $mime || ! isset( self::IMAGE_TYPES[ $mime ] ) ) {
			return ValidationResult::failed( $this->not_an_accepted_image( $client_name, $slot ) );
		}

		if ( ! in_array( $extension, self::IMAGE_TYPES[ $mime ], true ) ) {
			return ValidationResult::failed( 'extension_mismatch' );
		}

		$size = wp_getimagesize( $tmp_path );

		if ( ! is_array( $size ) || $size[0] < 1 || $size[1] < 1 ) {
			return ValidationResult::failed( 'image_unreadable' );
		}

		if ( $size[0] > self::MAX_WIDTH ) {
			return ValidationResult::failed( 'width_too_large' );
		}

		if ( $size[1] > self::MAX_HEIGHT ) {
			return ValidationResult::failed( 'height_too_large' );
		}

		if ( $size[0] * $size[1] > self::MAX_PIXELS ) {
			return ValidationResult::failed( 'pixels_too_large' );
		}

		return ValidationResult::passed();
	}

	/**
	 * The failure code for a file whose content is not an accepted image.
	 *
	 * @param string $client_name File name the client gave.
	 * @param string $slot        Upload slot.
	 * @return string
	 */
	private function not_an_accepted_image( string $client_name, string $slot ): string {
		$type = wp_check_filetype( $client_name )['type'];

		if ( is_string( $type ) && str_starts_with( $type, 'video/' ) ) {
			return self::SLOT_REEL === $slot ? 'video_validation_deferred' : 'video_not_allowed';
		}

		return 'unsupported_type';
	}

	/**
	 * Whether the file starts like an SVG or XML document.
	 *
	 * @param string $path File path.
	 * @return bool
	 */
	private function looks_like_svg( string $path ): bool {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local temp file; no remote URL is involved.
		$head = file_get_contents( $path, false, null, 0, 1024 );

		return is_string( $head ) && 1 === preg_match( '/<svg|<\?xml/i', $head );
	}

	/**
	 * Whether a client file name is free of path and control characters.
	 *
	 * @param string $name File name.
	 * @return bool
	 */
	private function is_safe_name( string $name ): bool {
		if ( '' === $name || str_contains( $name, '..' ) || 1 === preg_match( '/[\\\\\/\x00-\x1F]/', $name ) ) {
			return false;
		}

		return '' !== sanitize_file_name( $name );
	}
}
