<?php
/**
 * Upload validator tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Integration\Media;

use Rameshwari\Core\Services\Media\UploadValidator;
use Rameshwari\Core\Services\Media\Value\ValidationResult;

/**
 * Hard upload validation. Fixtures are headers only, so no large images are built.
 */
final class UploadValidatorTest extends \WP_UnitTestCase {

	/**
	 * Temp files to remove after each test.
	 *
	 * @var array<int,string>
	 */
	private array $files = array();

	/**
	 * Removes temp files.
	 */
	public function tear_down(): void {
		foreach ( $this->files as $file ) {
			wp_delete_file( $file );
		}

		parent::tear_down();
	}

	/**
	 * Writes bytes to a temp file.
	 *
	 * @param string $bytes File content.
	 * @return string Path.
	 */
	private function temp( string $bytes ): string {
		$path = wp_tempnam( 'rjmedia' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local test fixture in the system temp directory.
		file_put_contents( $path, $bytes );

		$this->files[] = $path;

		return $path;
	}

	/**
	 * A PNG that declares the given size. Only the header is real, which is all the size check reads.
	 *
	 * @param int $width  Width.
	 * @param int $height Height.
	 * @param int $total  Pad the file to this many bytes, 0 for none.
	 * @return string Bytes.
	 */
	private function png( int $width, int $height, int $total = 0 ): string {
		$chunk = static function ( string $type, string $data ): string {
			return pack( 'N', strlen( $data ) ) . $type . $data . pack( 'N', crc32( $type . $data ) );
		};

		$bytes = "\x89PNG\r\n\x1a\n" . $chunk( 'IHDR', pack( 'NNCCCCC', $width, $height, 8, 2, 0, 0, 0 ) ) . $chunk( 'IEND', '' );

		return $total > strlen( $bytes ) ? $bytes . str_repeat( "\0", $total - strlen( $bytes ) ) : $bytes;
	}

	/**
	 * A JPEG that declares the given size.
	 *
	 * @param int $width  Width.
	 * @param int $height Height.
	 * @return string Bytes.
	 */
	private function jpeg( int $width, int $height ): string {
		return "\xFF\xD8\xFF\xC0" . pack( 'n', 11 ) . "\x08" . pack( 'nn', $height, $width ) . "\x01\x01\x11\x00\xFF\xD9";
	}

	/**
	 * A WebP (VP8X) that declares the given size.
	 *
	 * @param int $width  Width.
	 * @param int $height Height.
	 * @return string Bytes.
	 */
	private function webp( int $width, int $height ): string {
		$data = "\0\0\0\0" . substr( pack( 'V', $width - 1 ), 0, 3 ) . substr( pack( 'V', $height - 1 ), 0, 3 );

		return 'RIFF' . pack( 'V', 22 ) . 'WEBPVP8X' . pack( 'V', 10 ) . $data;
	}

	/**
	 * Asserts a failure code.
	 *
	 * @param string $code   Expected code.
	 * @param string $bytes  File content.
	 * @param string $name   Client file name.
	 * @param string $slot   Slot.
	 */
	private function assertFails( string $code, string $bytes, string $name, string $slot = 'product_primary' ): void {
		$result = ( new UploadValidator() )->validate( $this->temp( $bytes ), $name, $slot );

		$this->assertFalse( $result->is_valid(), $name );
		$this->assertSame( $code, $result->code(), $name );
	}

	/**
	 * A valid JPEG passes.
	 */
	public function test_valid_jpeg_passes(): void {
		$result = ( new UploadValidator() )->validate( $this->temp( $this->jpeg( 800, 600 ) ), 'ring.jpg', 'product_primary' );

		$this->assertTrue( $result->equals( ValidationResult::passed() ) );
	}

	/**
	 * A valid PNG passes.
	 */
	public function test_valid_png_passes(): void {
		$result = ( new UploadValidator() )->validate( $this->temp( $this->png( 800, 600 ) ), 'ring.png', 'product_primary' );

		$this->assertTrue( $result->is_valid() );
		$this->assertSame( '', $result->code() );
	}

	/**
	 * A valid WebP passes.
	 */
	public function test_valid_webp_passes(): void {
		$result = ( new UploadValidator() )->validate( $this->temp( $this->webp( 800, 600 ) ), 'ring.webp', 'product_primary' );

		$this->assertTrue( $result->is_valid() );
	}

	/**
	 * An .svg name is rejected whatever the content.
	 */
	public function test_svg_rejected_by_extension(): void {
		$this->assertFails( 'svg_rejected', $this->png( 10, 10 ), 'logo.svg' );
	}

	/**
	 * SVG content is rejected even under an image name.
	 */
	public function test_svg_rejected_by_content(): void {
		$this->assertFails( 'svg_rejected', '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"></svg>', 'photo.png' );
		$this->assertFails( 'svg_rejected', "  <SVG width='1'></SVG>", 'photo.jpg' );
	}

	/**
	 * Other image types are not accepted.
	 */
	public function test_unsupported_image_type_rejected(): void {
		$this->assertFails( 'unsupported_type', "GIF89a\x01\x00\x01\x00\x00\x00\x00;", 'anim.gif' );
		$this->assertFails( 'unsupported_type', 'just some text', 'notes.png' );
	}

	/**
	 * One byte over 15 MiB fails.
	 */
	public function test_file_over_15_mib_rejected(): void {
		$this->assertFails( 'file_too_large', $this->png( 10, 10, UploadValidator::MAX_BYTES + 1 ), 'big.png' );
	}

	/**
	 * Exactly 15 MiB passes.
	 */
	public function test_15_mib_boundary_accepted(): void {
		$result = ( new UploadValidator() )->validate( $this->temp( $this->png( 10, 10, UploadValidator::MAX_BYTES ) ), 'edge.png', 'product_primary' );

		$this->assertTrue( $result->is_valid() );
	}

	/**
	 * Width over 6000 fails.
	 */
	public function test_width_over_6000_rejected(): void {
		$this->assertFails( 'width_too_large', $this->png( 6001, 10 ), 'wide.png' );
	}

	/**
	 * Height over 6000 fails.
	 */
	public function test_height_over_6000_rejected(): void {
		$this->assertFails( 'height_too_large', $this->png( 10, 6001 ), 'tall.png' );
	}

	/**
	 * 6000 on one side passes when the pixel count allows it.
	 */
	public function test_6000_side_boundary_accepted(): void {
		$this->assertTrue( ( new UploadValidator() )->validate( $this->temp( $this->png( 6000, 100 ) ), 'a.png', 'product_primary' )->is_valid() );
		$this->assertTrue( ( new UploadValidator() )->validate( $this->temp( $this->png( 100, 6000 ) ), 'b.png', 'product_primary' )->is_valid() );
	}

	/**
	 * Over 24 megapixels fails.
	 */
	public function test_pixel_count_over_24mp_rejected(): void {
		$this->assertFails( 'pixels_too_large', $this->png( 6000, 4001 ), 'huge.png' );
	}

	/**
	 * Exactly 24 megapixels passes.
	 */
	public function test_24mp_boundary_accepted(): void {
		$this->assertTrue( ( new UploadValidator() )->validate( $this->temp( $this->png( 6000, 4000 ) ), 'edge.png', 'product_primary' )->is_valid() );
		$this->assertTrue( ( new UploadValidator() )->validate( $this->temp( $this->png( 4000, 6000 ) ), 'edge2.png', 'product_primary' )->is_valid() );
	}

	/**
	 * The name's extension must agree with the real type.
	 */
	public function test_content_and_extension_mismatch_rejected(): void {
		$this->assertFails( 'extension_mismatch', $this->png( 10, 10 ), 'photo.jpg' );
		$this->assertFails( 'extension_mismatch', $this->jpeg( 10, 10 ), 'photo.png' );
		$this->assertFails( 'extension_mismatch', $this->webp( 10, 10 ), 'photo.jpeg' );
	}

	/**
	 * Unsafe names are rejected.
	 */
	public function test_unsafe_filename_rejected(): void {
		foreach ( array( '../photo.png', 'dir/photo.png', 'dir\\photo.png', "photo\0.png", '' ) as $name ) {
			$this->assertFails( 'unsafe_filename', $this->png( 10, 10 ), $name );
		}
	}

	/**
	 * A missing or unreadable file fails.
	 */
	public function test_missing_temp_file_rejected(): void {
		$result = ( new UploadValidator() )->validate( '/nonexistent/path/none.png', 'a.png', 'product_primary' );

		$this->assertFalse( $result->is_valid() );
		$this->assertSame( 'file_missing', $result->code() );
	}

		/**
		 * A truncated, corrupt image file is rejected.
		 *
		 * The detector classifies a cut-off file differently by environment: some
		 * identify the PNG signature and then fail to read dimensions
		 * (image_unreadable), others do not recognise the type at all
		 * (unsupported_type). Both are legitimate rejections. The invariant is that
		 * the file is never accepted and carries one of exactly these two codes.
		 */
	public function test_truncated_file_is_not_an_image(): void {
		$result = ( new UploadValidator() )->validate( $this->temp( substr( $this->png( 10, 10 ), 0, 12 ) ), 'cut.png', 'product_primary' );

		$this->assertFalse( $result->is_valid() );
		$this->assertNotSame( '', $result->code() );
		$this->assertContains( $result->code(), array( 'unsupported_type', 'image_unreadable' ) );
	}


	/**
	 * The detected type decides, not what the caller says it is.
	 */
	public function test_client_claims_cannot_override_detection(): void {
		$this->assertFails( 'unsupported_type', 'MZ not an image at all', 'innocent.jpg' );
		$this->assertFails( 'extension_mismatch', $this->png( 10, 10 ), 'innocent.webp' );

		$params = ( new \ReflectionMethod( UploadValidator::class, 'validate' ) )->getParameters();

		$this->assertSame( array( 'tmp_path', 'client_name', 'slot' ), array_map( static fn ( \ReflectionParameter $p ): string => $p->getName(), $params ) );
	}

	/**
	 * Success is the shared passed() value.
	 */
	public function test_success_is_validation_result_passed(): void {
		$result = ( new UploadValidator() )->validate( $this->temp( $this->png( 10, 10 ) ), 'ok.png', 'product_gallery' );

		$this->assertInstanceOf( ValidationResult::class, $result );
		$this->assertTrue( $result->equals( ValidationResult::passed() ) );
	}

	/**
	 * Failure is a failed() value with a stable, non-empty code.
	 */
	public function test_failure_is_validation_result_failed(): void {
		$result = ( new UploadValidator() )->validate( $this->temp( $this->png( 6001, 10 ) ), 'wide.png', 'product_primary' );

		$this->assertTrue( $result->equals( ValidationResult::failed( 'width_too_large' ) ) );
		$this->assertNotSame( '', $result->code() );
	}

	/**
	 * Video is refused in image-only slots.
	 */
	public function test_image_only_slot_does_not_accept_video(): void {
		foreach ( array( 'product_primary', 'product_gallery', 'category_image', 'hero_desktop', 'showroom_gallery' ) as $slot ) {
			$this->assertFails( 'video_not_allowed', 'not-really-a-video', 'clip.mp4', $slot );
		}
	}

	/**
	 * The reel slot reaches the deferred video path, not an image error.
	 */
	public function test_reel_slot_video_is_deferred_not_rejected_as_image(): void {
		$this->assertFails( 'video_validation_deferred', 'not-really-a-video', 'clip.mp4', UploadValidator::SLOT_REEL );
	}
}
