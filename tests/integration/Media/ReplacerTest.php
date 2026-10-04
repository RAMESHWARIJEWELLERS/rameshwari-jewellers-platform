<?php
/**
 * Replacer tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Integration\Media;

use Rameshwari\Core\Services\Media\Formats;
use Rameshwari\Core\Services\Media\Replacer;
use Rameshwari\Core\Services\Media\RetentionStore;
use Rameshwari\Core\Services\Media\UploadValidator;
use Rameshwari\Core\Support\Lock;
use Rameshwari\Core\Support\Logger;

/**
 * Replacement against real images in a private upload folder. Failures are injected with core hooks only.
 */
final class ReplacerTest extends \WP_UnitTestCase {

	private const SLOT = 'image';

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

		add_filter( 'upload_dir', array( $this, 'redirect_uploads' ) );
		wp_upload_dir( null, true, true );
	}

	/**
	 * Removes hooks, temp files and the private folder.
	 */
	public function tear_down(): void {
		remove_filter( 'upload_dir', array( $this, 'redirect_uploads' ) );
		remove_all_filters( 'update_attached_file' );
		remove_all_filters( 'wp_generate_attachment_metadata' );
		remove_all_filters( 'query' );
		remove_all_filters( 'wp_unique_filename' );

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
	 * Builds the service with its real collaborators.
	 *
	 * @return Replacer
	 */
	private function replacer(): Replacer {
		return new Replacer( new UploadValidator(), new RetentionStore(), new Formats(), new Lock(), new Logger( static function (): void {} ) );
	}

	/**
	 * PNG bytes of a given size and shade.
	 *
	 * @param int $size  Width and height.
	 * @param int $shade Grey level.
	 * @return string
	 */
	private function png( int $size, int $shade ): string {
		$image = imagecreatetruecolor( $size, $size );

		$this->assertNotFalse( $image );

		imagefill( $image, 0, 0, (int) imagecolorallocate( $image, $shade, $shade, $shade ) );
		ob_start();
		imagepng( $image );

		return (string) ob_get_clean();
	}

	/**
	 * Writes an incoming temp file and returns its path.
	 *
	 * @param string $bytes File bytes.
	 * @return string
	 */
	private function incoming( string $bytes ): string {
		$path = wp_tempnam( 'rjincoming' );

		file_put_contents( $path, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local test file.

		$this->temps[] = $path;

		return $path;
	}

	/**
	 * Creates an image attachment with a real file and metadata.
	 *
	 * @param string $mime  Attachment MIME type.
	 * @param string $bytes File bytes.
	 * @param string $ext   File extension.
	 * @return int
	 */
	private function attachment( string $mime = 'image/png', string $bytes = '', string $ext = 'png' ): int {
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$upload = wp_upload_bits( 'rjrep-' . wp_generate_uuid4() . '.' . $ext, null, '' === $bytes ? $this->png( 300, 40 ) : $bytes );

		$this->assertEmpty( $upload['error'], (string) $upload['error'] );

		$id = wp_insert_attachment(
			array(
				'post_mime_type' => $mime,
				'post_title'     => 'Fixture',
				'post_status'    => 'inherit',
			),
			$upload['file'],
			0,
			true
		);

		$this->assertIsInt( $id );

		update_attached_file( $id, $upload['file'] );

		if ( str_starts_with( $mime, 'image/' ) ) {
			wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $upload['file'] ) );
		}

		$this->assertFileIsReadable( (string) get_attached_file( $id ) );

		return $id;
	}

	/**
	 * Hash of an attachment's current file.
	 *
	 * @param int $id Attachment ID.
	 * @return string
	 */
	private function hash_of( int $id ): string {
		return (string) hash_file( 'sha256', (string) get_attached_file( $id ) );
	}

	/**
	 * Hash of a retained artifact.
	 *
	 * @param int    $id   Attachment ID.
	 * @param string $name Artifact name.
	 * @return string
	 */
	private function artifact_hash( int $id, string $name ): string {
		$uploads = wp_upload_dir( null, false );

		$this->assertContains( $name, ( new RetentionStore() )->list( $id ) );

		return (string) hash_file( 'sha256', $uploads['basedir'] . '/' . RetentionStore::DIRECTORY . '/' . $name );
	}

	/**
	 * Hashes of every retained artifact of an attachment.
	 *
	 * @param int $id Attachment ID.
	 * @return array<int,string>
	 */
	private function artifact_hashes( int $id ): array {
		return array_map( fn ( string $name ): string => $this->artifact_hash( $id, $name ), ( new RetentionStore() )->list( $id ) );
	}

	/**
	 * Names of files in the attachment folder that contain a word, staging copies included.
	 *
	 * @param string $dir  Folder.
	 * @param string $word Word to look for.
	 * @return array<int,string>
	 */
	private function files_like( string $dir, string $word ): array {
		return array_values( array_filter( (array) scandir( $dir ), static fn ( string $f ): bool => str_contains( $f, $word ) ) );
	}

	/**
	 * JPEG bytes of a given size.
	 *
	 * @param int $size Width and height.
	 * @return string
	 */
	private function jpeg( int $size ): string {
		$image = imagecreatetruecolor( $size, $size );

		$this->assertNotFalse( $image );

		ob_start();
		imagejpeg( $image );

		return (string) ob_get_clean();
	}

	/**
	 * Replaces with a fresh PNG and returns the result.
	 *
	 * @param int    $id    Attachment ID.
	 * @param int    $shade Grey level of the new image.
	 * @param string $name  Client file name.
	 * @return \Rameshwari\Core\Services\Media\Value\ReplaceResult
	 */
	private function replace_with( int $id, int $shade = 200, string $name = 'new.png' ) {
		return $this->replacer()->replace( $id, $this->incoming( $this->png( 120, $shade ) ), $name, self::SLOT );
	}

	/**
	 * The ID stays, the file changes, the old file is retained and dimensions are reported.
	 */
	public function test_successful_replacement(): void {
		$id   = $this->attachment();
		$old  = $this->hash_of( $id );
		$post = get_post( $id );
		$prev = (string) get_attached_file( $id );

		$result = $this->replace_with( $id );

		$this->assertNotSame( $prev, (string) get_attached_file( $id ) );
		$this->assertFileDoesNotExist( $prev );

		$this->assertTrue( $result->is_success(), $result->code() );
		$this->assertSame( 120, $result->width() );
		$this->assertSame( 120, $result->height() );
		$this->assertSame( $id, get_post( $id )->ID );
		$this->assertSame( $post->post_title, get_post( $id )->post_title );
		$this->assertNotSame( $old, $this->hash_of( $id ) );
		$this->assertSame( $old, $this->artifact_hash( $id, $result->artifact() ) );
		$this->assertSame( 120, wp_get_attachment_metadata( $id )['width'] );
	}

	/**
	 * Featured image and gallery references are the same before and after.
	 */
	public function test_references_are_unchanged(): void {
		$id    = $this->attachment();
		$other = $this->attachment();
		$post  = self::factory()->post->create();

		set_post_thumbnail( $post, $id );
		update_post_meta( $post, '_rj_gallery', array( $other, $id ) );

		$thumb   = get_post_meta( $post, '_thumbnail_id', true );
		$gallery = get_post_meta( $post, '_rj_gallery', true );

		$this->assertTrue( $this->replace_with( $id )->is_success() );
		$this->assertSame( $thumb, get_post_meta( $post, '_thumbnail_id', true ) );
		$this->assertSame( $gallery, get_post_meta( $post, '_rj_gallery', true ) );
	}

	/**
	 * Each replacement retains the file it replaced, in separate artifacts.
	 */
	public function test_repeated_replacement_keeps_each_old_file(): void {
		$id    = $this->attachment();
		$first = $this->hash_of( $id );

		$one = $this->replace_with( $id, 150, 'one.png' );

		$second = $this->hash_of( $id );

		$two = $this->replace_with( $id, 220, 'two.png' );

		$this->assertTrue( $one->is_success() && $two->is_success() );
		$this->assertNotSame( $one->artifact(), $two->artifact() );
		$this->assertSame( $first, $this->artifact_hash( $id, $one->artifact() ) );
		$this->assertSame( $second, $this->artifact_hash( $id, $two->artifact() ) );
		$this->assertCount( 2, ( new RetentionStore() )->list( $id ) );
	}

	/**
	 * An invalid incoming file touches nothing and keeps nothing.
	 */
	public function test_validation_failure_changes_nothing(): void {
		$id   = $this->attachment();
		$hash = $this->hash_of( $id );

		$result = $this->replacer()->replace( $id, $this->incoming( 'not an image' ), 'bad.png', self::SLOT );

		$this->assertFalse( $result->is_success() );
		$this->assertNotSame( '', $result->code() );
		$this->assertSame( $hash, $this->hash_of( $id ) );
		$this->assertSame( array(), ( new RetentionStore() )->list( $id ) );
	}

	/**
	 * A missing attachment is a typed failure and nothing is kept.
	 */
	public function test_missing_attachment_fails(): void {
		$result = $this->replace_with( 99999999 );

		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'attachment_not_found', $result->code() );
		$this->assertSame( array(), ( new RetentionStore() )->list( 99999999 ) );
	}

	/**
	 * A non-image attachment is refused and left alone.
	 */
	public function test_non_image_attachment_is_refused(): void {
		$id   = $this->attachment( 'text/plain', 'plain text', 'txt' );
		$hash = $this->hash_of( $id );

		$result = $this->replace_with( $id );

		$this->assertSame( 'not_an_image_attachment', $result->code() );
		$this->assertSame( $hash, $this->hash_of( $id ) );
		$this->assertSame( array(), ( new RetentionStore() )->list( $id ) );
	}

	/**
	 * A replacement already holding the lock makes a second one wait out.
	 */
	public function test_lock_contention_is_refused(): void {
		$id    = $this->attachment();
		$hash  = $this->hash_of( $id );
		$lock  = new Lock();
		$token = $lock->acquire( Replacer::LOCK_PREFIX . $id, 60 );

		$this->assertNotNull( $token );

		$result = $this->replace_with( $id );

		$this->assertSame( 'replace_in_progress', $result->code() );
		$this->assertSame( $hash, $this->hash_of( $id ) );
		$this->assertSame( array(), ( new RetentionStore() )->list( $id ) );

		$lock->release( Replacer::LOCK_PREFIX . $id, (string) $token );

		$this->assertTrue( $this->replace_with( $id )->is_success() );
	}

	/**
	 * When the file update is refused after retention, the original stays and is still the file.
	 */
	public function test_failure_after_retention_keeps_original(): void {
		$id      = $this->attachment();
		$hash    = $this->hash_of( $id );
		$path    = (string) get_attached_file( $id );
		$post    = self::factory()->post->create();
		$gallery = array( $id );

		update_post_meta( $post, '_rj_gallery', $gallery );
		add_filter( 'update_attached_file', '__return_false' );

		$result = $this->replace_with( $id );

		remove_all_filters( 'update_attached_file' );

		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'replace_failed', $result->code() );
		$this->assertSame( $path, get_attached_file( $id ) );
		$this->assertSame( $hash, $this->hash_of( $id ) );
		$this->assertSame( $id, get_post( $id )->ID );
		$this->assertSame( $gallery, get_post_meta( $post, '_rj_gallery', true ) );
		$this->assertContains( $hash, $this->artifact_hashes( $id ) );
		$this->assertSame( array(), $this->files_like( dirname( $path ), 'new' ) );
	}

	/**
	 * A metadata failure rolls back: the old file and metadata return and the new file is gone.
	 */
	public function test_metadata_failure_rolls_back(): void {
		$id   = $this->attachment();
		$hash = $this->hash_of( $id );
		$path = (string) get_attached_file( $id );
		$meta = wp_get_attachment_metadata( $id );

		add_filter( 'wp_generate_attachment_metadata', static fn (): array => array() );

		$result = $this->replace_with( $id, 200, 'fresh.png' );

		remove_all_filters( 'wp_generate_attachment_metadata' );

		$this->assertSame( 'replace_failed', $result->code() );
		$this->assertSame( $path, get_attached_file( $id ) );
		$this->assertSame( $hash, $this->hash_of( $id ) );
		$this->assertSame( $meta, wp_get_attachment_metadata( $id ) );
		$this->assertSame( 300, wp_get_attachment_metadata( $id )['width'] );
		$this->assertContains( $hash, $this->artifact_hashes( $id ) );
		$this->assertSame( array(), $this->files_like( dirname( $path ), 'fresh' ) );
		$this->assertSame( array(), $this->files_like( dirname( $path ), '.rjstage' ) );
		$this->assertTrue( wp_attachment_is_image( $id ) );
	}

	/**
	 * Names that try to leave the folder are refused before anything is written.
	 */
	public function test_traversal_names_are_rejected(): void {
		$id   = $this->attachment();
		$hash = $this->hash_of( $id );

		foreach ( array( '../evil.png', '..\\evil.png', 'a/b.png', "x\0.png" ) as $name ) {
			$result = $this->replacer()->replace( $id, $this->incoming( $this->png( 120, 90 ) ), $name, self::SLOT );

			$this->assertFalse( $result->is_success(), $name );
		}

		$this->assertSame( $hash, $this->hash_of( $id ) );
		$this->assertSame( array(), ( new RetentionStore() )->list( $id ) );
		$this->assertFileDoesNotExist( dirname( $this->base ) . '/evil.png' );
	}

	/**
	 * Only attachment rows and the expected temporary lock option change.
	 */
	public function test_only_attachment_rows_are_written(): void {
		$id          = $this->attachment();
		$keys        = array_keys( get_post_meta( $id ) );
		$options     = wp_load_alloptions( true );
		$lock_option = 'rj_lock_media_replace_' . $id;
		$lock_before = get_option( $lock_option, null );

		$this->writes = array();

		add_filter(
			'query',
			function ( string $sql ): string {
				if ( 1 === preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/i', $sql ) ) {
					$this->writes[] = $sql;
				}

				return $sql;
			}
		);

		$this->assertTrue( $this->replace_with( $id )->is_success() );

		global $wpdb;

		foreach ( $this->writes as $sql ) {
			$this->assertStringNotContainsString( $wpdb->termmeta, $sql );

			if ( str_contains( $sql, $wpdb->options ) ) {
				$this->assertMatchesRegularExpression( '/^\s*(INSERT|DELETE)\b/i', $sql );
				$this->assertStringContainsString( $lock_option, $sql );

				continue;
			}

			$this->assertStringContainsString( $wpdb->postmeta, $sql );
		}

		$this->assertNotEmpty( $this->writes );
		$this->assertSame( $lock_before, get_option( $lock_option, null ) );
		$this->assertFalse( get_option( $lock_option, false ) );
		$this->assertSame( $options, wp_load_alloptions( true ) );
		$this->assertEqualsCanonicalizing( $keys, array_keys( get_post_meta( $id ) ) );
	}

	/**
	 * The success result has a fixed shape.
	 */
	public function test_success_result_shape(): void {
		$id     = $this->attachment();
		$result = $this->replace_with( $id );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( '', $result->code() );
		$this->assertMatchesRegularExpression( '/^' . $id . '-\d+-[a-f0-9]{16}-/', $result->artifact() );
		$this->assertSame( array( 120, 120 ), array( $result->width(), $result->height() ) );
	}

	/**
	 * The slot is passed to the validator: the same non-image file fails differently by slot.
	 */
	public function test_requested_slot_reaches_the_validator(): void {
		$id    = $this->attachment();
		$bytes = $this->incoming( 'not an image' );
		$other = $this->replacer()->replace( $id, $bytes, 'clip.mp4', self::SLOT );
		$reel  = $this->replacer()->replace( $id, $bytes, 'clip.mp4', UploadValidator::SLOT_REEL );

		$this->assertNotSame( $other->code(), $reel->code() );
		$this->assertSame( 'slot_required', $this->replacer()->replace( $id, $bytes, 'x.png', '' )->code() );
	}

	/**
	 * A current file that is gone is a typed failure and nothing is kept.
	 */
	public function test_missing_current_file_fails(): void {
		$id = $this->attachment();

		wp_delete_file( (string) get_attached_file( $id ) );

		$this->assertSame( 'current_file_missing', $this->replace_with( $id )->code() );
		$this->assertSame( array(), ( new RetentionStore() )->list( $id ) );
	}

	/**
	 * When the staged copy cannot be made, the current file is untouched; the artifact stays.
	 */
	public function test_stage_copy_failure_leaves_the_current_file(): void {
		$id   = $this->attachment();
		$hash = $this->hash_of( $id );

		add_filter(
			'wp_unique_filename',
			static function ( string $filename, string $ext, string $dir ): string {
				wp_mkdir_p( $dir . '/' . $filename . '.rjstage' );

				return $filename;
			},
			10,
			3
		);

		$result = $this->replace_with( $id );

		remove_all_filters( 'wp_unique_filename' );

		$this->assertSame( 'stage_failed', $result->code() );
		$this->assertSame( $hash, $this->hash_of( $id ) );
		$this->assertContains( $hash, $this->artifact_hashes( $id ) );
	}

	/**
	 * A rollback puts the original bytes back from the artifact, and keeps the id and references.
	 */
	public function test_rollback_restores_from_the_artifact(): void {
		$id   = $this->attachment();
		$hash = $this->hash_of( $id );
		$post = self::factory()->post->create();

		set_post_thumbnail( $post, $id );
		add_filter( 'wp_generate_attachment_metadata', static fn (): array => array() );

		$result = $this->replace_with( $id, 10 );

		remove_all_filters( 'wp_generate_attachment_metadata' );

		$this->assertFalse( $result->is_success() );
		$this->assertSame( $hash, $this->hash_of( $id ) );
		$this->assertSame( $id, (int) get_post_thumbnail_id( $post ) );
		$this->assertGreaterThanOrEqual( 2, count( ( new RetentionStore() )->list( $id ) ) );
		$this->assertSame( 300, wp_get_attachment_metadata( $id )['width'] );
	}

	/**
	 * A PNG can be replaced by a JPEG: new path, new extension, same ID and references.
	 */
	public function test_replacement_can_change_file_extension(): void {
		$id   = $this->attachment();
		$old  = $this->hash_of( $id );
		$post = self::factory()->post->create();

		set_post_thumbnail( $post, $id );

		$result = $this->replacer()->replace( $id, $this->incoming( $this->jpeg( 140 ) ), 'photo.jpg', self::SLOT );

		$this->assertTrue( $result->is_success(), $result->code() );
		$this->assertStringEndsWith( '.jpg', (string) get_attached_file( $id ) );
		$this->assertSame( 'image/jpeg', get_post_mime_type( $id ) );
		$this->assertSame( 140, wp_get_attachment_metadata( $id )['width'] );
		$this->assertSame( $old, $this->artifact_hash( $id, $result->artifact() ) );
		$this->assertSame( $id, (int) get_post_thumbnail_id( $post ) );
	}

	/**
	 * The new file lands inside the uploads folder under a sanitised, collision-safe name.
	 */
	public function test_destination_is_contained_sanitised_and_unique(): void {
		$id = $this->attachment();

		$one = $this->replacer()->replace( $id, $this->incoming( $this->png( 120, 100 ) ), 'My Photo (1).png', self::SLOT );
		$p1  = (string) get_attached_file( $id );
		$two = $this->replacer()->replace( $id, $this->incoming( $this->png( 120, 110 ) ), 'My Photo (1).png', self::SLOT );
		$p2  = (string) get_attached_file( $id );

		$this->assertTrue( $one->is_success() && $two->is_success() );
		$this->assertNotSame( $p1, $p2 );
		$this->assertSame( realpath( $this->base ), realpath( dirname( $p2 ) ) );
		$this->assertMatchesRegularExpression( '~^[^\s()\\\\/]+\.png$~', basename( $p2 ) );
		$this->assertFileDoesNotExist( $p1 );
	}

	/**
	 * A failure after the path changed puts the old path, bytes and metadata back and leaves no new file.
	 */
	public function test_rollback_after_path_update_restores_old_state(): void {
		$id   = $this->attachment();
		$hash = $this->hash_of( $id );
		$path = (string) get_attached_file( $id );
		$meta = wp_get_attachment_metadata( $id );

		add_filter( 'wp_generate_attachment_metadata', static fn (): array => array() );

		$result = $this->replacer()->replace( $id, $this->incoming( $this->jpeg( 90 ) ), 'late.jpg', self::SLOT );

		remove_all_filters( 'wp_generate_attachment_metadata' );

		$this->assertSame( 'replace_failed', $result->code() );
		$this->assertSame( $path, get_attached_file( $id ) );
		$this->assertSame( 'image/png', get_post_mime_type( $id ) );
		$this->assertSame( $hash, $this->hash_of( $id ) );
		$this->assertSame( $meta, wp_get_attachment_metadata( $id ) );
		$this->assertSame( array(), $this->files_like( dirname( $path ), 'late' ) );
		$this->assertContains( $hash, $this->artifact_hashes( $id ) );
	}
}
