<?php
/**
 * Source-level checks for the media integration layer.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Unit\Media;

use PHPUnit\Framework\TestCase;

/**
 * MediaModule and BulkUploader stay inside Stage 7's boundaries.
 */
final class MediaIntegrationSourceTest extends TestCase {

	/**
	 * Source of one of the two files.
	 *
	 * @param string $relative Path under src/.
	 * @return string
	 */
	private function source( string $relative ): string {
		$text = file_get_contents( dirname( __DIR__, 3 ) . '/plugin/rameshwari-core/src/' . $relative ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source file; no remote URL is involved.

		$this->assertIsString( $text );

		return $text;
	}

	/**
	 * Neither file touches the database, options, routes, menus or other stages.
	 */
	public function test_no_forbidden_apis(): void {
		$forbidden = array( 'register_rest_route', '$wpdb', 'update_option', 'add_option', 'add_menu_page', 'add_submenu_page', 'register_post_type', 'register_taxonomy', 'register_meta', 'dbDelta', 'exec(', 'shell_exec', 'SlotGuidance', 'wp_ajax_nopriv_', 'update_post_meta(', 'Product\\ProductService', 'Category\\' );

		foreach ( array( 'Services/Media/MediaModule.php', 'Admin/Media/BulkUploader.php' ) as $file ) {
			$text = $this->source( $file );

			foreach ( $forbidden as $needle ) {
				$this->assertStringNotContainsString( $needle, $text, $file . ' contains ' . $needle );
			}
		}
	}

	/**
	 * The module has no constructor, so building it does no work.
	 */
	public function test_module_has_no_constructor(): void {
		$this->assertStringNotContainsString( '__construct', $this->source( 'Services/Media/MediaModule.php' ) );
	}

	/**
	 * The uploader delegates to the existing services instead of re-implementing them.
	 */
	public function test_uploader_delegates_to_existing_services(): void {
		$text = $this->source( 'Admin/Media/BulkUploader.php' );

		foreach ( array( 'UploadValidator', 'AltText', 'media_handle_upload', 'check_ajax_referer', "current_user_can( 'upload_files' )" ) as $needle ) {
			$this->assertStringContainsString( $needle, $text, $needle );
		}

		foreach ( array( 'imagecreate', 'imagejpeg', 'getimagesize', 'move_uploaded_file', 'wp_handle_upload' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $text, 'duplicate upload logic: ' . $needle );
		}
	}
}
