<?php
/**
 * Modern format tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Integration\Media;

use Rameshwari\Core\Services\Media\Formats;
use Rameshwari\Core\Services\Media\Sizes;

/**
 * Host support is detected, never assumed. Tests hold on any host.
 */
final class FormatsTest extends \WP_UnitTestCase {

	/**
	 * Attachment IDs and sibling files to remove after each test.
	 *
	 * @var array<int,int>
	 */
	private array $attachments = array();

	/**
	 * Extra files to remove after each test.
	 *
	 * @var array<int,string>
	 */
	private array $files = array();

	/**
	 * Removes what the test created.
	 */
	public function tear_down(): void {
		remove_all_filters( 'wp_image_editors' );
		remove_all_filters( 'wp_get_attachment_image_src' );

		foreach ( $this->files as $file ) {
			wp_delete_file( $file );
		}

		foreach ( $this->attachments as $id ) {
			wp_delete_attachment( $id, true );
		}

		parent::tear_down();
	}

	/**
	 * Creates a real 2000 by 2000 attachment in the given format with all five sizes generated.
	 *
	 * @param string $format png or jpeg.
	 * @return int Attachment ID.
	 * @throws \RuntimeException When the test fixture cannot be created.
	 */
	private function image_attachment( string $format = 'png' ): int {
		( new Sizes() )->register();

		$image = imagecreatetruecolor( 2000, 2000 );

		if ( false === $image ) {
			throw new \RuntimeException( 'The test image could not be created.' );
		}

		ob_start();
		'jpeg' === $format ? imagejpeg( $image ) : imagepng( $image );
		$bytes  = (string) ob_get_clean();
		$upload = wp_upload_bits( 'rjfixture-' . wp_generate_uuid4() . ( 'jpeg' === $format ? '.jpg' : '.png' ), null, $bytes );

		return $this->attach( $upload['file'], 'jpeg' === $format ? 'image/jpeg' : 'image/png' );
	}

	/**
	 * Creates a WebP original through the same image editor the code under test uses.
	 *
	 * Callers check Formats::supports_webp() first.
	 *
	 * @return int Attachment ID.
	 * @throws \RuntimeException When the test fixture cannot be created.
	 */
	private function webp_attachment(): int {
		$png    = $this->image_attachment();
		$editor = wp_get_image_editor( (string) get_attached_file( $png ) );

		if ( is_wp_error( $editor ) ) {
			throw new \RuntimeException( 'No image editor is available.' );
		}

		$saved = $editor->save( dirname( (string) get_attached_file( $png ) ) . '/rjfixture-' . wp_generate_uuid4() . '.webp', 'image/webp' );

		if ( is_wp_error( $saved ) ) {
			throw new \RuntimeException( 'The WebP test original could not be written.' );
		}

		return $this->attach( $saved['path'], 'image/webp' );
	}

	/**
	 * Registers a file as an attachment and generates its sizes.
	 *
	 * @param string $file Absolute file path.
	 * @param string $mime MIME type.
	 * @return int Attachment ID.
	 * @throws \RuntimeException When the test fixture cannot be created.
	 */
	private function attach( string $file, string $mime ): int {
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$id = wp_insert_attachment(
			array(
				'post_mime_type' => $mime,
				'post_title'     => 'Fixture',
				'post_status'    => 'inherit',
			),
			$file
		);

		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $file ) );

		$this->attachments[] = $id;

		return $id;
	}

	/**
	 * Path of the generated file for a registered size.
	 *
	 * @param int    $id  Attachment ID.
	 * @param string $key WordPress size key.
	 * @return string
	 */
	private function derivative( int $id, string $key ): string {
		$metadata = wp_get_attachment_metadata( $id );
		$file     = is_array( $metadata ) && isset( $metadata['sizes'][ $key ]['file'] ) ? (string) $metadata['sizes'][ $key ]['file'] : '';

		return dirname( (string) get_attached_file( $id ) ) . '/' . $file;
	}

	/**
	 * Copies a derivative to a sibling name, as a modern-format file would sit.
	 *
	 * @param string $derivative Derivative path.
	 * @param string $extension  Sibling extension.
	 * @return string Sibling path.
	 */
	private function sibling( string $derivative, string $extension ): string {
		$path = dirname( $derivative ) . '/' . pathinfo( $derivative, PATHINFO_FILENAME ) . '.' . $extension;

		copy( $derivative, $path );

		$this->files[] = $path;

		return $path;
	}

	/**
	 * Detection matches what WordPress reports for the active editor.
	 */
	public function test_detection_matches_the_image_editor(): void {
		$formats = new Formats();

		$this->assertSame( (bool) wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ), $formats->supports_webp() );
		$this->assertSame( (bool) wp_image_editor_supports( array( 'mime_type' => 'image/avif' ) ), $formats->supports_avif() );
	}

	/**
	 * With no usable editor, neither format is supported and health reports both.
	 */
	public function test_unsupported_host_reports_both_findings(): void {
		add_filter( 'wp_image_editors', '__return_empty_array' );

		$formats = new Formats();

		$this->assertFalse( $formats->supports_webp() );
		$this->assertFalse( $formats->supports_avif() );
		$this->assertCount( 2, $formats->health() );
	}

	/**
	 * Health lists a finding only for each format the host cannot write.
	 */
	public function test_health_matches_detection(): void {
		$formats = new Formats();

		$this->assertCount( (int) ! $formats->supports_webp() + (int) ! $formats->supports_avif(), $formats->health() );
	}

	/**
	 * Siblings are discovered by file existence, AVIF first.
	 */
	public function test_siblings_are_found_by_file_existence(): void {
		$derivative = $this->derivative( $this->image_attachment(), 'rj_card' );
		$formats    = new Formats();

		$this->assertSame( array(), $formats->siblings( $derivative ) );

		$webp = $this->sibling( $derivative, 'webp' );
		$avif = $this->sibling( $derivative, 'avif' );

		$this->assertSame(
			array(
				'image/avif' => $avif,
				'image/webp' => $webp,
			),
			$formats->siblings( $derivative )
		);
	}

	/**
	 * Conversion on a host that cannot write the format leaves the metadata and files alone.
	 */
	public function test_conversion_failure_leaves_the_image_usable(): void {
		$id         = $this->image_attachment();
		$metadata   = wp_get_attachment_metadata( $id );
		$derivative = $this->derivative( $id, 'rj_card' );

		add_filter( 'wp_image_editors', '__return_empty_array' );

		$result = ( new Formats() )->convert( is_array( $metadata ) ? $metadata : array(), $id );

		$this->assertSame( $metadata, $result );
		$this->assertFileExists( $derivative );
		$this->assertFileExists( (string) get_attached_file( $id ) );
		$this->assertSame( array(), ( new Formats() )->siblings( $derivative ) );
	}

	/**
	 * Metadata without sizes is returned as given.
	 */
	public function test_metadata_without_sizes_is_returned_unchanged(): void {
		$id = $this->image_attachment();

		$this->assertSame( array( 'width' => 1 ), ( new Formats() )->convert( array( 'width' => 1 ), $id ) );
	}

	/**
	 * Where the host can write a format, a sibling appears beside every size and the originals stay.
	 */
	public function test_supported_formats_are_written_beside_each_size(): void {
		$id       = $this->image_attachment();
		$metadata = wp_get_attachment_metadata( $id );
		$formats  = new Formats();

		( new Formats() )->convert( is_array( $metadata ) ? $metadata : array(), $id );

		foreach ( array( 'rj_thumb', 'rj_card', 'rj_detail', 'rj_hero', 'rj_reel_poster' ) as $key ) {
			$derivative = $this->derivative( $id, $key );
			$siblings   = $formats->siblings( $derivative );

			$this->assertFileExists( $derivative, $key );
			$this->assertSame( $formats->supports_webp(), isset( $siblings['image/webp'] ), $key );
			$this->assertSame( $formats->supports_avif(), isset( $siblings['image/avif'] ), $key );

			foreach ( $siblings as $path ) {
				$this->files[] = $path;
			}
		}
	}

	/**
	 * Conversion stores nothing: no meta key is added or changed.
	 */
	public function test_conversion_persists_nothing(): void {
		$id       = $this->image_attachment();
		$metadata = wp_get_attachment_metadata( $id );
		$meta     = get_post_meta( $id );
		$options  = wp_load_alloptions( true );

		$formats = new Formats();
		$formats->convert( is_array( $metadata ) ? $metadata : array(), $id );

		foreach ( $formats->siblings( $this->derivative( $id, 'rj_card' ) ) as $path ) {
			$this->files[] = $path;
		}

		$this->assertSame( $meta, get_post_meta( $id ) );
		$this->assertSame( $options, wp_load_alloptions( true ) );
	}

	/**
	 * A JPEG original is converted like a PNG one, and the original stays.
	 */
	public function test_jpeg_source_gets_modern_siblings_where_supported(): void {
		$id       = $this->image_attachment( 'jpeg' );
		$metadata = wp_get_attachment_metadata( $id );
		$formats  = new Formats();
		$original = (string) get_attached_file( $id );
		$before   = md5_file( $original );

		$formats->convert( is_array( $metadata ) ? $metadata : array(), $id );

		foreach ( array( 'rj_thumb', 'rj_card', 'rj_detail', 'rj_hero', 'rj_reel_poster' ) as $key ) {
			$derivative = $this->derivative( $id, $key );
			$siblings   = $formats->siblings( $derivative );

			$this->assertFileExists( $derivative, $key );
			$this->assertSame( $formats->supports_webp(), isset( $siblings['image/webp'] ), $key );
			$this->assertSame( $formats->supports_avif(), isset( $siblings['image/avif'] ), $key );

			foreach ( $siblings as $path ) {
				$this->files[] = $path;
			}
		}

		$this->assertSame( $before, md5_file( $original ) );
	}

	/**
	 * A WebP original is converted only to AVIF, never to WebP again, and is left untouched.
	 *
	 * Needs a host that can write WebP, since that is the only way to make a WebP original.
	 */
	public function test_webp_source_converts_only_to_avif(): void {
		$formats = new Formats();

		if ( ! $formats->supports_webp() ) {
			$this->markTestSkipped( 'This host cannot write WebP, so no WebP original can be made.' );
		}

		$id       = $this->webp_attachment();
		$metadata = wp_get_attachment_metadata( $id );
		$original = (string) get_attached_file( $id );
		$before   = md5_file( $original );

		$formats->convert( is_array( $metadata ) ? $metadata : array(), $id );

		foreach ( array( 'rj_thumb', 'rj_card', 'rj_detail', 'rj_hero', 'rj_reel_poster' ) as $key ) {
			$derivative = $this->derivative( $id, $key );
			$siblings   = $formats->siblings( $derivative );

			$this->assertFileExists( $derivative, $key );
			$this->assertArrayNotHasKey( 'image/webp', $siblings, $key );
			$this->assertSame( $formats->supports_avif(), isset( $siblings['image/avif'] ), $key );

			foreach ( $siblings as $path ) {
				$this->files[] = $path;
			}
		}

		$this->assertFileExists( $original );
		$this->assertSame( $before, md5_file( $original ) );
	}
}
