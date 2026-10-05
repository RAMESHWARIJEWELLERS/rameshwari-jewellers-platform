<?php
/**
 * Regenerator tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Integration\Media;

use Rameshwari\Core\Services\Media\Formats;
use Rameshwari\Core\Services\Media\Regenerator;
use Rameshwari\Core\Services\Media\Sizes;

/**
 * Batches against real images in a private upload folder, with the host's real image editor.
 */
final class RegeneratorTest extends \WP_UnitTestCase {

	/**
	 * Upload base used by this test.
	 *
	 * @var string
	 */
	private string $base = '';

	/**
	 * Write statements seen while watching the database.
	 *
	 * @var array<int,string>
	 */
	private array $writes = array();

	/**
	 * Redirects uploads to a private folder and registers the sizes.
	 */
	public function set_up(): void {
		parent::set_up();

		$uploads    = wp_upload_dir( null, false );
		$this->base = $uploads['basedir'] . '/rjtest-' . wp_generate_uuid4();

		add_filter( 'upload_dir', array( $this, 'redirect_uploads' ) );
		wp_upload_dir( null, true, true );
		( new Sizes() )->register();
	}

	/**
	 * Removes hooks and the private folder.
	 */
	public function tear_down(): void {
		remove_filter( 'upload_dir', array( $this, 'redirect_uploads' ) );
		remove_all_filters( 'query' );
		remove_all_filters( 'wp_image_editors' );

		if ( is_dir( $this->base ) ) {
			$items = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->base, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );

			foreach ( $items as $item ) {
				if ( $item->isDir() && ! $item->isLink() ) {
					rmdir( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test cleanup of this test's private folder.
				} else {
					wp_delete_file( $item->getPathname() );
				}
			}

			rmdir( $this->base ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test cleanup of this test's private folder.
		}

		parent::tear_down();
	}

	/**
	 * The upload_dir filter callback.
	 *
	 * @param array<string,mixed> $uploads Upload locations.
	 * @return array<string,mixed>
	 */
	public function redirect_uploads( array $uploads ): array {
		$uploads['basedir'] = $this->base;
		$uploads['baseurl'] = 'http://example.org/rjtest';
		$uploads['path']    = $this->base;
		$uploads['url']     = 'http://example.org/rjtest';
		$uploads['subdir']  = '';
		$uploads['error']   = false;

		return $uploads;
	}

	/**
	 * The service under test.
	 *
	 * @return Regenerator
	 */
	private function regenerator(): Regenerator {
		return new Regenerator( new Sizes(), new Formats() );
	}

	/**
	 * Creates an image attachment with real sub-sizes.
	 *
	 * @return int
	 */
	private function image(): int {
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$canvas = imagecreatetruecolor( 2000, 1600 );

		$this->assertNotFalse( $canvas );

		imagefill( $canvas, 0, 0, (int) imagecolorallocate( $canvas, 120, 80, 40 ) );
		ob_start();
		imagepng( $canvas );

		$upload = wp_upload_bits( 'rjregen-' . wp_generate_uuid4() . '.png', null, (string) ob_get_clean() );

		$this->assertEmpty( $upload['error'], (string) $upload['error'] );

		$id = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/png',
				'post_title'     => 'Fixture',
				'post_status'    => 'inherit',
			),
			$upload['file'],
			0,
			true
		);

		$this->assertIsInt( $id );

		update_attached_file( $id, $upload['file'] );
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $upload['file'] ) );

		return $id;
	}

	/**
	 * A non-image attachment.
	 *
	 * @return int
	 */
	private function document(): int {
		return (int) self::factory()->attachment->create(
			array(
				'post_mime_type' => 'text/plain',
				'post_title'     => 'Doc',
			)
		);
	}

	/**
	 * Path of a generated size.
	 *
	 * @param int    $id  Attachment ID.
	 * @param string $key Size key.
	 * @return string
	 */
	private function size_path( int $id, string $key ): string {
		$meta = wp_get_attachment_metadata( $id );

		$this->assertIsArray( $meta );
		$this->assertIsArray( $meta['sizes'][ $key ] ?? null, $key );

		return dirname( (string) get_attached_file( $id ) ) . '/' . $meta['sizes'][ $key ]['file'];
	}

	/**
	 * Every file in the private folder with its hash.
	 *
	 * @return array<string,string>
	 */
	private function files(): array {
		$out = array();

		foreach ( (array) scandir( $this->base ) as $name ) {
			if ( is_file( $this->base . '/' . $name ) ) {
				$out[ $name ] = (string) hash_file( 'sha256', $this->base . '/' . $name );
			}
		}

		ksort( $out );

		return $out;
	}

	/**
	 * A first pass, so modern siblings exist where the host supports them.
	 *
	 * @param int $id Attachment ID.
	 * @return void
	 */
	private function settle( int $id ): void {
		$this->regenerator()->regenerate( $id - 1, 1 );
	}

	/**
	 * Batches start after the cursor, run in ascending order, respect the size and advance the cursor.
	 */
	public function test_batches_follow_the_cursor(): void {
		$ids = array( $this->image(), $this->image(), $this->image(), $this->image(), $this->image() );

		$first = $this->regenerator()->regenerate( 0, 2 );

		$this->assertSame( 2, $first->processed() );
		$this->assertSame( $ids[1], $first->cursor() );
		$this->assertFalse( $first->is_done() );

		$second = $this->regenerator()->regenerate( $first->cursor(), 2 );

		$this->assertSame( 2, $second->processed() );
		$this->assertSame( $ids[3], $second->cursor() );

		$third = $this->regenerator()->regenerate( $second->cursor(), 2 );

		$this->assertSame( 1, $third->processed() );
		$this->assertSame( $ids[4], $third->cursor() );
		$this->assertTrue( $third->is_done() );
	}

	/**
	 * IDs at or below the cursor are never touched.
	 */
	public function test_cursor_is_strictly_exclusive(): void {
		$one = $this->image();
		$two = $this->image();

		unlink( $this->size_path( $one, 'rj_card' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test removes a derivative on purpose.

		$result = $this->regenerator()->regenerate( $one, 10 );

		$this->assertSame( 1, $result->processed() );
		$this->assertSame( $two, $result->cursor() );
		$this->assertFileDoesNotExist( $this->size_path( $one, 'rj_card' ) );
	}

	/**
	 * Non-image attachments are not part of a batch.
	 */
	public function test_non_images_are_not_selected(): void {
		$one = $this->image();

		$this->document();

		$two    = $this->image();
		$result = $this->regenerator()->regenerate( 0, 10 );

		$this->assertSame( 2, $result->processed() );
		$this->assertSame( $two, $result->cursor() );
		$this->assertGreaterThan( $one, $two );
	}

	/**
	 * With no attachments, the cursor stays and the run is done.
	 */
	public function test_empty_run_keeps_the_cursor(): void {
		$result = $this->regenerator()->regenerate( 77, 5 );

		$this->assertSame( 77, $result->cursor() );
		$this->assertSame( 0, $result->processed() );
		$this->assertTrue( $result->is_done() );
	}

	/**
	 * Out-of-range input is refused before any work.
	 */
	public function test_invalid_arguments_are_rejected(): void {
		foreach ( array( array( -1, 5 ), array( 0, 0 ), array( 0, Regenerator::MAX_BATCH + 1 ) ) as $args ) {
			try {
				$this->regenerator()->regenerate( $args[0], $args[1] );

				$this->fail( 'Invalid arguments were accepted.' );
			} catch ( \InvalidArgumentException $expected ) {
				$this->assertNotSame( '', $expected->getMessage() );
			}
		}
	}

	/**
	 * A deleted derivative is rebuilt with its current dimensions.
	 */
	public function test_missing_derivative_is_rebuilt(): void {
		$id = $this->image();

		$this->settle( $id );

		$path = $this->size_path( $id, 'rj_card' );

		unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test removes a derivative on purpose.

		$result = $this->regenerator()->regenerate( $id - 1, 1 );

		$this->assertSame( 1, $result->regenerated() );
		$this->assertSame( 0, $result->failed() );
		$this->assertFileExists( $this->size_path( $id, 'rj_card' ) );
		$this->assertSame( array( 600, 600 ), array_slice( (array) wp_getimagesize( $this->size_path( $id, 'rj_card' ) ), 0, 2 ) );
	}

	/**
	 * An entry with outdated dimensions is rebuilt to the current definition.
	 */
	public function test_outdated_dimensions_are_rebuilt(): void {
		$id   = $this->image();
		$meta = wp_get_attachment_metadata( $id );

		$this->settle( $id );

		$meta = wp_get_attachment_metadata( $id );

		$this->assertIsArray( $meta );

		$meta['sizes']['rj_card']['width']  = 10;
		$meta['sizes']['rj_card']['height'] = 10;

		wp_update_attachment_metadata( $id, $meta );

		$result = $this->regenerator()->regenerate( $id - 1, 1 );
		$after  = wp_get_attachment_metadata( $id );

		$this->assertSame( 1, $result->regenerated() );
		$this->assertIsArray( $after );
		$this->assertSame( 600, $after['sizes']['rj_card']['width'] );
		$this->assertSame( 600, $after['sizes']['rj_card']['height'] );
	}

	/**
	 * A second run changes nothing: same files, same metadata, everything skipped.
	 */
	public function test_second_run_is_idempotent(): void {
		$id = $this->image();

		$this->regenerator()->regenerate( $id - 1, 1 );

		$files = $this->files();
		$meta  = wp_get_attachment_metadata( $id );
		$again = $this->regenerator()->regenerate( $id - 1, 1 );

		$this->assertSame( 1, $again->skipped() );
		$this->assertSame( 0, $again->regenerated() );
		$this->assertSame( $files, $this->files() );
		$this->assertSame( $meta, wp_get_attachment_metadata( $id ) );
	}

	/**
	 * A missing modern sibling is written again, and a present one is left alone.
	 */
	public function test_missing_sibling_is_repaired_when_the_host_supports_it(): void {
		$formats = new Formats();

		if ( ! $formats->supports_webp() ) {
			$this->markTestSkipped( 'This host cannot write WebP, so there is no sibling to repair.' );
		}

		$id = $this->image();

		$this->settle( $id );

		$card    = $this->size_path( $id, 'rj_card' );
		$sibling = dirname( $card ) . '/' . pathinfo( $card, PATHINFO_FILENAME ) . '.webp';

		$this->assertFileExists( $sibling );

		$kept = $this->size_path( $id, 'rj_thumb' );
		$keep = dirname( $kept ) . '/' . pathinfo( $kept, PATHINFO_FILENAME ) . '.webp';
		$hash = hash_file( 'sha256', $keep );

		unlink( $sibling ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test removes a sibling on purpose.

		$result = $this->regenerator()->regenerate( $id - 1, 1 );

		$this->assertSame( 1, $result->regenerated() );
		$this->assertFileExists( $sibling );
		$this->assertSame( $hash, hash_file( 'sha256', $keep ) );
	}

	/**
	 * Without WebP or AVIF support nothing fails and no sibling appears.
	 */
	public function test_unsupported_host_regenerates_without_siblings(): void {
		$id = $this->image();

		$this->assertIsArray( wp_get_attachment_metadata( $id ) );
		$this->assertFileExists( $this->size_path( $id, 'rj_card' ) );

		add_filter( 'wp_image_editors', '__return_empty_array' );

		try {
			$result = $this->regenerator()->regenerate( $id - 1, 1 );
		} finally {
			remove_filter( 'wp_image_editors', '__return_empty_array' );
		}

		$this->assertSame( 0, $result->regenerated() );
		$this->assertSame( 0, $result->failed() );
		$this->assertSame( 1, $result->skipped() );
	}

	/**
	 * The original file, its path and the attachment ID are untouched.
	 */
	public function test_original_and_identity_remain(): void {
		$id       = $this->image();
		$path     = (string) get_attached_file( $id );
		$original = (string) hash_file( 'sha256', $path );
		$post     = get_post( $id );

		unlink( $this->size_path( $id, 'rj_hero' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test removes a derivative on purpose.

		$this->regenerator()->regenerate( $id - 1, 1 );

		$this->assertSame( $path, get_attached_file( $id ) );
		$this->assertSame( $original, hash_file( 'sha256', $path ) );
		$this->assertSame( $id, get_post( $id )->ID );
		$this->assertSame( $post->post_title, get_post( $id )->post_title );
	}

	/**
	 * Metadata the service does not own is kept.
	 */
	public function test_unrelated_metadata_is_preserved(): void {
		$id   = $this->image();
		$meta = wp_get_attachment_metadata( $id );

		$this->assertIsArray( $meta );

		$meta['rj_extra'] = 'keep me';

		wp_update_attachment_metadata( $id, $meta );

		$thumbnail = $meta['sizes']['thumbnail'] ?? null;

		unlink( $this->size_path( $id, 'rj_card' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test removes a derivative on purpose.

		$this->regenerator()->regenerate( $id - 1, 1 );

		$after = wp_get_attachment_metadata( $id );

		$this->assertIsArray( $after );
		$this->assertSame( 'keep me', $after['rj_extra'] );
		$this->assertSame( $thumbnail, $after['sizes']['thumbnail'] ?? null );
		$this->assertSame( $meta['image_meta'] ?? null, $after['image_meta'] ?? null );
	}

	/**
	 * Only the registered sizes are rebuilt; a missing core size is left missing.
	 */
	public function test_only_registered_sizes_are_rebuilt(): void {
		$id   = $this->image();
		$meta = wp_get_attachment_metadata( $id );

		$this->assertIsArray( $meta );

		unset( $meta['sizes']['medium'] );

		wp_update_attachment_metadata( $id, $meta );

		unlink( $this->size_path( $id, 'rj_card' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test removes a derivative on purpose.

		$this->regenerator()->regenerate( $id - 1, 1 );

		$after = wp_get_attachment_metadata( $id );

		$this->assertIsArray( $after );
		$this->assertArrayNotHasKey( 'medium', $after['sizes'] );
		$this->assertArrayHasKey( 'rj_card', $after['sizes'] );
	}

	/**
	 * One failing attachment is reported and the rest of the batch still runs.
	 */
	public function test_one_failure_does_not_stop_the_batch(): void {
		$broken = $this->image();
		$good   = $this->image();

		unlink( (string) get_attached_file( $broken ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test removes the source on purpose.
		unlink( $this->size_path( $good, 'rj_card' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test removes a derivative on purpose.

		$result = $this->regenerator()->regenerate( 0, 10 );

		$this->assertSame( array( $broken => 'source_missing' ), $result->failures() );
		$this->assertSame( 1, $result->regenerated() );
		$this->assertSame( $good, $result->cursor() );
		$this->assertFileExists( $this->size_path( $good, 'rj_card' ) );
	}

	/**
	 * An attachment with no metadata is reported and not given new metadata.
	 */
	public function test_missing_metadata_is_reported(): void {
		$id = $this->image();

		delete_post_meta( $id, '_wp_attachment_metadata' );

		$result = $this->regenerator()->regenerate( $id - 1, 1 );

		$this->assertSame( array( $id => 'metadata_missing' ), $result->failures() );
		$this->assertFalse( metadata_exists( 'post', $id, '_wp_attachment_metadata' ) );
	}

	/**
	 * Nothing is persisted about the run: no option, no transient, no new meta key.
	 */
	public function test_no_server_state_is_created(): void {
		$id      = $this->image();
		$keys    = array_keys( get_post_meta( $id ) );
		$options = wp_load_alloptions( true );

		unlink( $this->size_path( $id, 'rj_card' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test removes a derivative on purpose.

		$this->writes = array();

		add_filter(
			'query',
			function ( string $sql ): string {
				if ( 1 === preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP)\b/i', $sql ) ) {
					$this->writes[] = $sql;
				}

				return $sql;
			}
		);

		$this->regenerator()->regenerate( $id - 1, 1 );

		global $wpdb;

		$this->assertNotEmpty( $this->writes );

		foreach ( $this->writes as $sql ) {
			$this->assertStringNotContainsString( $wpdb->options, $sql );
			$this->assertStringNotContainsString( $wpdb->termmeta, $sql );
			$this->assertDoesNotMatchRegularExpression( '/^\s*(CREATE|ALTER|DROP)\b/i', $sql );
		}

		$this->assertSame( $options, wp_load_alloptions( true ) );
		$this->assertEqualsCanonicalizing( $keys, array_keys( get_post_meta( $id ) ) );
	}

	/**
	 * References to the attachment are unchanged.
	 */
	public function test_references_are_unchanged(): void {
		$id   = $this->image();
		$post = self::factory()->post->create();

		set_post_thumbnail( $post, $id );
		unlink( $this->size_path( $id, 'rj_card' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test removes a derivative on purpose.

		$this->regenerator()->regenerate( $id - 1, 1 );

		$this->assertSame( $id, (int) get_post_thumbnail_id( $post ) );
	}
}
