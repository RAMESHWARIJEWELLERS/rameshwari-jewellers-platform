<?php
/**
 * Poster extractor tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Integration\Media;

use Rameshwari\Core\Services\Media\Formats;
use Rameshwari\Core\Services\Media\PosterExtractor;
use Rameshwari\Core\Services\Media\Sizes;
use Rameshwari\Core\Services\Media\UploadValidator;

/**
 * A poster arrives as an image and becomes an ordinary attachment; nothing is bound or stored.
 */
final class PosterExtractorTest extends \WP_UnitTestCase {

	/**
	 * Upload base used by this test.
	 *
	 * @var string
	 */
	private string $base = '';

	/**
	 * Temp files to remove.
	 *
	 * @var array<int,string>
	 */
	private array $temps = array();

	/**
	 * Write statements seen while watching the database.
	 *
	 * @var array<int,string>
	 */
	private array $writes = array();

	/**
	 * Redirects uploads to a private folder.
	 */
	public function set_up(): void {
		parent::set_up();

		$uploads    = wp_upload_dir( null, false );
		$this->base = $uploads['basedir'] . '/rjtest-' . wp_generate_uuid4();

		wp_mkdir_p( $this->base );
		add_filter( 'upload_dir', array( $this, 'redirect_uploads' ) );
		wp_upload_dir( null, true, true );
	}

	/**
	 * Removes hooks, temp files and the private folder.
	 */
	public function tear_down(): void {
		remove_filter( 'upload_dir', array( $this, 'redirect_uploads' ) );
		remove_all_filters( 'query' );
		remove_all_filters( 'wp_handle_sideload_prefilter' );

		foreach ( $this->temps as $file ) {
			wp_delete_file( $file );
		}

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
	 * The service with its real collaborators.
	 *
	 * @return PosterExtractor
	 */
	private function extractor(): PosterExtractor {
		return new PosterExtractor( new UploadValidator(), new Sizes(), new Formats() );
	}

	/**
	 * Writes an incoming temp file.
	 *
	 * @param string $bytes File bytes.
	 * @return string
	 */
	private function incoming( string $bytes ): string {
		$path = wp_tempnam( 'rjposter' );

		file_put_contents( $path, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local test file.

		$this->temps[] = $path;

		return $path;
	}

	/**
	 * PNG bytes of a given size.
	 *
	 * @param int $width  Width.
	 * @param int $height Height.
	 * @return string
	 */
	private function png( int $width, int $height ): string {
		$image = imagecreatetruecolor( $width, $height );

		$this->assertNotFalse( $image );

		imagefill( $image, 0, 0, (int) imagecolorallocate( $image, 90, 60, 30 ) );
		ob_start();
		imagepng( $image );

		return (string) ob_get_clean();
	}

	/**
	 * How many attachments exist.
	 *
	 * @return int
	 */
	private function attachments(): int {
		return count(
			get_posts(
				array(
					'post_type'   => 'attachment',
					'post_status' => 'any',
					'numberposts' => -1,
					'fields'      => 'ids',
				)
			)
		);
	}

	/**
	 * The contract is the registered "reel poster" size and nothing else.
	 */
	public function test_contract_uses_the_registered_poster_size(): void {
		$contract   = $this->extractor()->contract();
		$definition = ( new Sizes() )->all()['reel poster'];

		$this->assertSame( 'reel poster', $contract['name'] );
		$this->assertSame( $definition['key'], $contract['key'] );
		$this->assertSame( $definition['width'], $contract['width'] );
		$this->assertSame( $definition['height'], $contract['height'] );
		$this->assertSame( ( new Sizes() )->key( 'reel poster' ), $contract['key'] );
	}

	/**
	 * A valid frame becomes an ordinary image attachment with the poster size generated.
	 */
	public function test_valid_poster_becomes_an_attachment(): void {
		$bytes  = $this->png( 1080, 1920 );
		$result = $this->extractor()->accept( $this->incoming( $bytes ), 'frame.png' );

		$this->assertTrue( $result->is_success(), $result->code() );

		$id   = $result->attachment_id();
		$meta = wp_get_attachment_metadata( $id );
		$key  = $this->extractor()->contract()['key'];

		$this->assertSame( 'attachment', get_post_type( $id ) );
		$this->assertTrue( wp_attachment_is_image( $id ) );
		$this->assertSame( 'image/png', get_post_mime_type( $id ) );
		$this->assertSame( hash( 'sha256', $bytes ), hash_file( 'sha256', (string) get_attached_file( $id ) ) );
		$this->assertIsArray( $meta );
		$this->assertSame( 720, $meta['sizes'][ $key ]['width'] );
		$this->assertSame( 1280, $meta['sizes'][ $key ]['height'] );
		$this->assertSame( dirname( (string) get_attached_file( $id ) ), $this->base );
	}

	/**
	 * Modern siblings are written beside the poster size where the host supports them.
	 */
	public function test_modern_siblings_follow_host_support(): void {
		$result = $this->extractor()->accept( $this->incoming( $this->png( 1080, 1920 ) ), 'frame.png' );

		$this->assertTrue( $result->is_success(), $result->code() );

		$id     = $result->attachment_id();
		$meta   = wp_get_attachment_metadata( $id );
		$file   = dirname( (string) get_attached_file( $id ) ) . '/' . $meta['sizes'][ $this->extractor()->contract()['key'] ]['file'];
		$webp   = dirname( $file ) . '/' . pathinfo( $file, PATHINFO_FILENAME ) . '.webp';
		$exists = ( new Formats() )->supports_webp();

		$this->assertSame( $exists, is_file( $webp ) );
	}

	/**
	 * Images the validator rejects fail as values and create nothing.
	 */
	public function test_invalid_input_fails_without_side_effects(): void {
		$before = $this->attachments();
		$cases  = array(
			'text'  => array( 'not an image', 'frame.png' ),
			'svg'   => array( '<svg xmlns="http://www.w3.org/2000/svg"></svg>', 'frame.svg' ),
			'video' => array( 'video bytes', 'clip.mp4' ),
			'cut'   => array( substr( $this->png( 100, 100 ), 0, 12 ), 'cut.png' ),
			'path'  => array( $this->png( 100, 100 ), '../frame.png' ),
		);

		foreach ( $cases as $name => $case ) {
			$result = $this->extractor()->accept( $this->incoming( $case[0] ), $case[1] );

			$this->assertFalse( $result->is_success(), $name );
			$this->assertNotSame( '', $result->code(), $name );
			$this->assertSame( 0, $result->attachment_id(), $name );
		}

		$this->assertSame( $before, $this->attachments() );
	}

	/**
	 * A video is refused outright: the poster slot is not the reel slot.
	 */
	public function test_video_is_not_a_poster(): void {
		$result = $this->extractor()->accept( $this->incoming( 'video bytes' ), 'clip.mp4' );

		$this->assertSame( 'video_not_allowed', $result->code() );
	}

	/**
	 * No poster is a failed value, not an exception, and changes nothing.
	 */
	public function test_missing_poster_is_a_value_not_an_error(): void {
		$before = $this->attachments();
		$none   = $this->extractor()->accept( '', 'frame.png' );
		$gone   = $this->extractor()->accept( $this->base . '/does-not-exist.png', 'frame.png' );

		$this->assertSame( 'no_poster', $none->code() );
		$this->assertSame( 'file_missing', $gone->code() );
		$this->assertFalse( $none->is_success() || $gone->is_success() );
		$this->assertSame( $before, $this->attachments() );
	}

	/**
	 * A failure while storing the file is reported as a value and leaves no attachment.
	 *
	 * The failure is injected through core's sideload pre-filter, which makes
	 * the upload step return an error on every supported WordPress version.
	 */
	public function test_storage_failure_is_isolated(): void {
		$before   = $this->attachments();
		$existing = get_posts(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
			)
		);
		$refuse   = static function ( array $file ): array {
			$file['error'] = 'Injected storage failure.';

			return $file;
		};

		add_filter( 'wp_handle_sideload_prefilter', $refuse );

		try {
			$result = $this->extractor()->accept( $this->incoming( $this->png( 1080, 1920 ) ), 'frame.png' );
		} finally {
			remove_filter( 'wp_handle_sideload_prefilter', $refuse );
		}

		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'ingest_failed', $result->code() );
		$this->assertSame( 0, $result->attachment_id() );
		$this->assertSame( $before, $this->attachments() );
		$this->assertSame(
			$existing,
			get_posts(
				array(
					'post_type'   => 'attachment',
					'post_status' => 'any',
					'numberposts' => -1,
					'fields'      => 'ids',
				)
			)
		);
	}

	/**
	 * Nothing about reels or state is written: no reel field, no option, no extra attachment key.
	 */
	public function test_nothing_is_bound_or_persisted(): void {
		$options      = wp_load_alloptions( true );
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

		$result = $this->extractor()->accept( $this->incoming( $this->png( 1080, 1920 ) ), 'frame.png' );

		$this->assertTrue( $result->is_success(), $result->code() );
		$this->assertNotEmpty( $this->writes );

		global $wpdb;

		foreach ( $this->writes as $sql ) {
			$this->assertStringNotContainsString( '_rj_', $sql );
			$this->assertStringNotContainsString( $wpdb->options, $sql );
			$this->assertStringNotContainsString( $wpdb->termmeta, $sql );
			$this->assertDoesNotMatchRegularExpression( '/^\s*(CREATE|ALTER|DROP)\b/i', $sql );
		}

		$keys = array_keys( get_post_meta( $result->attachment_id() ) );

		sort( $keys );

		$this->assertSame( array( '_wp_attached_file', '_wp_attachment_metadata' ), $keys );
		$this->assertSame( $options, wp_load_alloptions( true ) );
		$this->assertCount(
			0,
			get_posts(
				array(
					'post_type'   => 'rj_reel',
					'post_status' => 'any',
					'numberposts' => -1,
					'fields'      => 'ids',
				)
			)
		);
	}

	/**
	 * A poster smaller than the registered size is still accepted as an ordinary image.
	 */
	public function test_small_frame_is_still_accepted(): void {
		$result = $this->extractor()->accept( $this->incoming( $this->png( 360, 640 ) ), 'small.png' );

		$this->assertTrue( $result->is_success(), $result->code() );
		$this->assertTrue( wp_attachment_is_image( $result->attachment_id() ) );
	}
}
