<?php
/**
 * Responsive source tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Integration\Media;

use Rameshwari\Core\Services\Media\Formats;
use Rameshwari\Core\Services\Media\Responsive;
use Rameshwari\Core\Services\Media\Sizes;

/**
 * Sources come from WordPress attachment APIs; modern siblings go first.
 */
final class ResponsiveTest extends \WP_UnitTestCase {

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
	 * Builds the service under test.
	 *
	 * @return Responsive
	 */
	private function service(): Responsive {
		return new Responsive( new Sizes(), new Formats() );
	}

	/**
	 * With no modern sibling, only the original format is listed, at the registered size.
	 */
	public function test_original_fallback_is_always_present(): void {
		$id      = $this->image_attachment();
		$sources = $this->service()->sources( $id, 'card' );

		$this->assertCount( 1, $sources );
		$this->assertSame( 'image/png', $sources[0]['mime'] );
		$this->assertSame( 600, $sources[0]['width'] );
		$this->assertSame( 600, $sources[0]['height'] );
	}

	/**
	 * The listed URL is the one WordPress reports for that size.
	 */
	public function test_url_comes_from_wordpress(): void {
		$id    = $this->image_attachment();
		$image = wp_get_attachment_image_src( $id, 'rj_card' );

		$this->assertIsArray( $image );
		$this->assertSame( $image[0], $this->service()->sources( $id, 'card' )[0]['url'] );
	}

	/**
	 * Existing modern siblings are listed first, AVIF then WebP, then the original.
	 */
	public function test_modern_formats_come_first_when_files_exist(): void {
		$id         = $this->image_attachment();
		$derivative = $this->derivative( $id, 'rj_card' );

		$this->sibling( $derivative, 'webp' );
		$this->sibling( $derivative, 'avif' );

		$sources = $this->service()->sources( $id, 'card' );

		$this->assertSame( array( 'image/avif', 'image/webp', 'image/png' ), array_column( $sources, 'mime' ) );
		$this->assertStringEndsWith( '.avif', $sources[0]['url'] );
		$this->assertStringEndsWith( '.webp', $sources[1]['url'] );
		$this->assertStringEndsWith( '.png', $sources[2]['url'] );
	}

	/**
	 * Every entry carries the registered dimensions.
	 */
	public function test_every_entry_has_the_registered_dimensions(): void {
		$id         = $this->image_attachment();
		$derivative = $this->derivative( $id, 'rj_hero' );

		$this->sibling( $derivative, 'webp' );

		foreach ( $this->service()->sources( $id, 'hero' ) as $source ) {
			$this->assertSame( 1920, $source['width'] );
			$this->assertSame( 1200, $source['height'] );
		}
	}

	/**
	 * All five contract names resolve, including the two-word reel poster.
	 */
	public function test_all_registered_sizes_resolve(): void {
		$id = $this->image_attachment();

		foreach ( ( new Sizes() )->all() as $name => $definition ) {
			$sources = $this->service()->sources( $id, $name );

			$this->assertNotEmpty( $sources, $name );
			$this->assertSame( $definition['width'], $sources[ count( $sources ) - 1 ]['width'], $name );
			$this->assertSame( $definition['height'], $sources[ count( $sources ) - 1 ]['height'], $name );
		}
	}

	/**
	 * A missing attachment gives an empty list.
	 */
	public function test_missing_attachment_returns_empty(): void {
		$this->assertSame( array(), $this->service()->sources( 99999999, 'card' ) );
		$this->assertSame( array(), $this->service()->sources( 0, 'card' ) );
	}

	/**
	 * A non-image attachment gives an empty list.
	 */
	public function test_non_image_attachment_returns_empty(): void {
		$id = wp_insert_attachment(
			array(
				'post_mime_type' => 'application/pdf',
				'post_title'     => 'Document',
				'post_status'    => 'inherit',
			)
		);

		$this->attachments[] = $id;

		$this->assertSame( array(), $this->service()->sources( $id, 'card' ) );
	}

	/**
	 * An unknown contract name fails explicitly, even for a missing attachment.
	 */
	public function test_unknown_size_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );

		$this->service()->sources( 99999999, 'rj_card' );
	}

	/**
	 * Every returned URL sits under the attachment's own upload location.
	 */
	public function test_urls_share_the_attachment_location(): void {
		$id   = $this->image_attachment();
		$base = dirname( (string) wp_get_attachment_url( $id ) );

		$this->sibling( $this->derivative( $id, 'rj_card' ), 'webp' );

		foreach ( $this->service()->sources( $id, 'card' ) as $source ) {
			$this->assertSame( $base, dirname( $source['url'] ) );
		}
	}

	/**
	 * Reading sources stores nothing and renders no markup.
	 */
	public function test_returns_plain_data_and_stores_nothing(): void {
		$id      = $this->image_attachment();
		$meta    = get_post_meta( $id );
		$sources = $this->service()->sources( $id, 'thumb' );

		$this->assertSame( $meta, get_post_meta( $id ) );
		$this->assertSame( array( 'mime', 'url', 'width', 'height' ), array_keys( $sources[0] ) );
		$this->assertStringNotContainsString( '<', $sources[0]['url'] );
	}

	/**
	 * A JPEG original keeps its own MIME type as the fallback.
	 */
	public function test_jpeg_original_is_the_fallback(): void {
		$id         = $this->image_attachment( 'jpeg' );
		$derivative = $this->derivative( $id, 'rj_card' );

		$this->sibling( $derivative, 'webp' );

		$this->assertSame( array( 'image/webp', 'image/jpeg' ), array_column( $this->service()->sources( $id, 'card' ), 'mime' ) );
	}

	/**
	 * A WebP original lists AVIF first and WebP last, with WebP never repeated.
	 *
	 * Needs a host that can write WebP, since that is the only way to make a WebP original.
	 */
	public function test_webp_original_lists_avif_then_webp(): void {
		if ( ! ( new Formats() )->supports_webp() ) {
			$this->markTestSkipped( 'This host cannot write WebP, so no WebP original can be made.' );
		}

		$id = $this->webp_attachment();

		$this->sibling( $this->derivative( $id, 'rj_card' ), 'avif' );

		$this->assertSame( array( 'image/avif', 'image/webp' ), array_column( $this->service()->sources( $id, 'card' ), 'mime' ) );
	}

	/**
	 * A query string or fragment on the URL is kept after the sibling file name.
	 */
	public function test_query_string_and_fragment_survive(): void {
		$id = $this->image_attachment();

		$this->sibling( $this->derivative( $id, 'rj_card' ), 'webp' );

		add_filter(
			'wp_get_attachment_image_src',
			static function ( array|false $image ): array|false {
				if ( is_array( $image ) ) {
					$image[0] = (string) $image[0] . '?v=1#x';
				}

				return $image;
			}
		);

		$sources = $this->service()->sources( $id, 'card' );

		$this->assertSame( array( 'image/webp', 'image/png' ), array_column( $sources, 'mime' ) );
		$this->assertStringEndsWith( '.webp?v=1#x', $sources[0]['url'] );
		$this->assertStringEndsWith( '.png?v=1#x', $sources[1]['url'] );
	}

	/**
	 * If the URL's file name is not the derivative's own, no modern URL is guessed.
	 */
	public function test_rewritten_file_name_offers_only_the_original(): void {
		$id = $this->image_attachment();

		$this->sibling( $this->derivative( $id, 'rj_card' ), 'webp' );

		add_filter(
			'wp_get_attachment_image_src',
			static function ( array|false $image ): array|false {
				if ( is_array( $image ) ) {
					$image[0] = dirname( (string) $image[0] ) . '/renamed.png';
				}

				return $image;
			}
		);

		$sources = $this->service()->sources( $id, 'card' );

		$this->assertCount( 1, $sources );
		$this->assertSame( 'image/png', $sources[0]['mime'] );
	}
}
