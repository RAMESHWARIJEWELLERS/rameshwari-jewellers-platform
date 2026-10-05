<?php
/**
 * Restorer tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Integration\Media;

use Rameshwari\Core\Services\Media\Formats;
use Rameshwari\Core\Services\Media\Replacer;
use Rameshwari\Core\Services\Media\Restorer;
use Rameshwari\Core\Services\Media\RetentionStore;
use Rameshwari\Core\Services\Media\UploadValidator;
use Rameshwari\Core\Support\Lock;
use Rameshwari\Core\Support\Logger;

/**
 * Restoring retained versions against real images in a private upload folder.
 */
final class RestorerTest extends \WP_UnitTestCase {

	private const DAY = 86400;

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
		remove_all_filters( 'wp_generate_attachment_metadata' );

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
	 * The restorer with real collaborators.
	 *
	 * @param RetentionStore|null $store Retention store, to control its clock.
	 * @return Restorer
	 */
	private function restorer( ?RetentionStore $store = null ): Restorer {
		return new Restorer( $store ?? new RetentionStore(), new Formats(), new Lock(), new Logger( static function (): void {} ) );
	}

	/**
	 * The replacer with real collaborators.
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
	 * Creates an image attachment with a real file and metadata.
	 *
	 * @param int $size  Width and height.
	 * @param int $shade Grey level.
	 * @return int
	 */
	private function attachment( int $size = 300, int $shade = 40 ): int {
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$upload = wp_upload_bits( 'rjrest-' . wp_generate_uuid4() . '.png', null, $this->png( $size, $shade ) );

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
	 * Overwrites the attachment's current file with bytes.
	 *
	 * @param int    $id    Attachment ID.
	 * @param string $bytes File bytes.
	 * @return void
	 */
	private function write_current( int $id, string $bytes ): void {
		file_put_contents( (string) get_attached_file( $id ), $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture file.
	}

	/**
	 * Hash of the attachment's current file.
	 *
	 * @param int $id Attachment ID.
	 * @return string
	 */
	private function hash_of( int $id ): string {
		return (string) hash_file( 'sha256', (string) get_attached_file( $id ) );
	}

	/**
	 * Replaces with a new PNG through the real replacer.
	 *
	 * @param int $id    Attachment ID.
	 * @param int $size  New size.
	 * @param int $shade New shade.
	 * @return string The artifact that kept the old file.
	 */
	private function replace_with( int $id, int $size, int $shade ): string {
		$tmp = wp_tempnam( 'rjincoming' );

		file_put_contents( $tmp, $this->png( $size, $shade ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local test file.

		$this->temps[] = $tmp;

		$result = $this->replacer()->replace( $id, $tmp, 'new.png', 'image' );

		$this->assertTrue( $result->is_success(), $result->code() );

		return $result->artifact();
	}

	/**
	 * Candidates come back newest first, in the order RetentionStore lists them.
	 */
	public function test_discovery_is_newest_first(): void {
		$id  = $this->attachment();
		$now = time();

		$oldest = ( new RetentionStore( static fn (): int => $now - 3 * self::DAY ) )->keep( $id );
		$middle = ( new RetentionStore( static fn (): int => $now - 2 * self::DAY ) )->keep( $id );
		$newest = ( new RetentionStore( static fn (): int => $now - 1 * self::DAY ) )->keep( $id );

		$candidates = $this->restorer()->candidates( $id );

		$this->assertSame( array( $newest, $middle, $oldest ), $candidates->names() );
		$this->assertSame( $newest, $candidates->newest() );
		$this->assertSame( ( new RetentionStore() )->list( $id ), $candidates->names() );
	}

	/**
	 * An attachment with nothing retained has no candidates.
	 */
	public function test_discovery_of_nothing(): void {
		$this->assertSame( 0, $this->restorer()->candidates( $this->attachment() )->count() );
	}

	/**
	 * A restore brings the old bytes and size back, and the current file becomes an artifact.
	 */
	public function test_successful_restore(): void {
		$id       = $this->attachment( 300, 40 );
		$original = $this->hash_of( $id );
		$artifact = $this->replace_with( $id, 120, 200 );
		$replaced = $this->hash_of( $id );

		$result = $this->restorer()->restore( $id, $artifact );

		$this->assertTrue( $result->is_success(), $result->code() );
		$this->assertSame( $original, $this->hash_of( $id ) );
		$this->assertSame( array( 300, 300 ), array( $result->width(), $result->height() ) );
		$this->assertSame( $artifact, $result->restored() );
		$this->assertContains( $result->undo_artifact(), ( new RetentionStore() )->list( $id ) );
		$this->assertNotSame( $replaced, $original );
	}

	/**
	 * The restore can be undone through the artifact it reports.
	 */
	public function test_restore_can_be_undone(): void {
		$id       = $this->attachment( 300, 40 );
		$artifact = $this->replace_with( $id, 120, 200 );
		$replaced = $this->hash_of( $id );

		$restored = $this->restorer()->restore( $id, $artifact );

		$this->assertTrue( $restored->is_success() );

		$undone = $this->restorer()->restore( $id, $restored->undo_artifact() );

		$this->assertTrue( $undone->is_success(), $undone->code() );
		$this->assertSame( $replaced, $this->hash_of( $id ) );
		$this->assertSame( 120, wp_get_attachment_metadata( $id )['width'] );
	}

	/**
	 * Sizes and metadata are rebuilt for the restored image.
	 */
	public function test_derivatives_are_regenerated(): void {
		$id       = $this->attachment( 600, 40 );
		$artifact = $this->replace_with( $id, 150, 200 );

		$this->assertSame( 150, wp_get_attachment_metadata( $id )['width'] );

		$result = $this->restorer()->restore( $id, $artifact );
		$meta   = wp_get_attachment_metadata( $id );
		$dir    = dirname( (string) get_attached_file( $id ) );

		$this->assertTrue( $result->is_success(), $result->code() );
		$this->assertSame( 600, $meta['width'] );
		$this->assertSame( 600, $meta['height'] );

		foreach ( (array) ( $meta['sizes'] ?? array() ) as $size ) {
			$this->assertFileExists( $dir . '/' . $size['file'] );
		}
	}

	/**
	 * Identity and references are untouched.
	 */
	public function test_identity_and_references_are_preserved(): void {
		$id       = $this->attachment();
		$other    = $this->attachment();
		$post     = self::factory()->post->create();
		$title    = get_post( $id )->post_title;
		$artifact = $this->replace_with( $id, 120, 200 );

		set_post_thumbnail( $post, $id );
		update_post_meta( $post, '_rj_gallery', array( $other, $id ) );

		$thumb   = get_post_meta( $post, '_thumbnail_id', true );
		$gallery = get_post_meta( $post, '_rj_gallery', true );

		$this->assertTrue( $this->restorer()->restore( $id, $artifact )->is_success() );
		$this->assertSame( $id, get_post( $id )->ID );
		$this->assertSame( $title, get_post( $id )->post_title );
		$this->assertSame( $thumb, get_post_meta( $post, '_thumbnail_id', true ) );
		$this->assertSame( $gallery, get_post_meta( $post, '_rj_gallery', true ) );
	}

	/**
	 * An unknown, foreign or traversal name changes nothing.
	 */
	public function test_missing_artifact_is_refused(): void {
		$id   = $this->attachment();
		$hash = $this->hash_of( $id );

		$this->replace_with( $id, 120, 200 );

		$after = $this->hash_of( $id );

		foreach ( array( 'nope.png', '../evil.png', '', $id . '-1-aaaaaaaaaaaaaaaa-gone.png' ) as $name ) {
			$this->assertSame( 'artifact_not_found', $this->restorer()->restore( $id, $name )->code(), $name );
		}

		$this->assertSame( $after, $this->hash_of( $id ) );
		$this->assertNotSame( $hash, $after );
	}

	/**
	 * An artifact belonging to another attachment is refused.
	 */
	public function test_other_attachments_artifact_is_refused(): void {
		$one      = $this->attachment();
		$two      = $this->attachment();
		$artifact = $this->replace_with( $one, 120, 200 );
		$hash     = $this->hash_of( $two );

		$this->assertSame( 'artifact_not_found', $this->restorer()->restore( $two, $artifact )->code() );
		$this->assertSame( $hash, $this->hash_of( $two ) );
	}

	/**
	 * A retained file that is not a readable image is rolled back and the good file stays.
	 */
	public function test_corrupt_artifact_is_rolled_back(): void {
		$id   = $this->attachment( 300, 40 );
		$good = $this->hash_of( $id );
		$meta = wp_get_attachment_metadata( $id );

		$this->write_current( $id, 'this is not an image' );

		$corrupt = ( new RetentionStore() )->keep( $id );

		$this->assertNotNull( $corrupt );

		$this->write_current( $id, $this->png( 300, 40 ) );

		$result = $this->restorer()->restore( $id, $corrupt );

		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'artifact_corrupt', $result->code() );
		$this->assertSame( $good, $this->hash_of( $id ) );
		$this->assertSame( $meta['width'], wp_get_attachment_metadata( $id )['width'] );
	}

	/**
	 * A failed rebuild puts the replaced file and its metadata back.
	 */
	public function test_regeneration_failure_is_rolled_back(): void {
		$id       = $this->attachment( 300, 40 );
		$artifact = $this->replace_with( $id, 120, 200 );
		$current  = $this->hash_of( $id );
		$meta     = wp_get_attachment_metadata( $id );

		add_filter( 'wp_generate_attachment_metadata', static fn (): array => array() );

		$result = $this->restorer()->restore( $id, $artifact );

		remove_all_filters( 'wp_generate_attachment_metadata' );

		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'regeneration_failed', $result->code() );
		$this->assertSame( $current, $this->hash_of( $id ) );
		$this->assertSame( $meta, wp_get_attachment_metadata( $id ) );
		$this->assertSame( $id, get_post( $id )->ID );
	}

	/**
	 * After a failed replace the original is back and rebuilt, without any restore call.
	 */
	public function test_automatic_recovery_after_failed_replace(): void {
		$id   = $this->attachment( 300, 40 );
		$hash = $this->hash_of( $id );
		$tmp  = wp_tempnam( 'rjincoming' );

		file_put_contents( $tmp, $this->png( 120, 200 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local test file.

		$this->temps[] = $tmp;

		add_filter( 'wp_generate_attachment_metadata', static fn (): array => array() );

		$result = $this->replacer()->replace( $id, $tmp, 'new.png', 'image' );

		remove_all_filters( 'wp_generate_attachment_metadata' );

		$this->assertFalse( $result->is_success() );
		$this->assertSame( $hash, $this->hash_of( $id ) );
		$this->assertSame( 300, wp_get_attachment_metadata( $id )['width'] );
		$this->assertNotSame( array(), $this->restorer()->candidates( $id )->names() );
	}

	/**
	 * With several retained versions, any one can be restored.
	 */
	public function test_multiple_versions(): void {
		$id = $this->attachment( 300, 40 );
		$h1 = $this->hash_of( $id );
		$a1 = $this->replace_with( $id, 200, 100 );
		$h2 = $this->hash_of( $id );
		$a2 = $this->replace_with( $id, 100, 220 );

		$this->assertSame( array( $a2, $a1 ), array_slice( $this->restorer()->candidates( $id )->names(), 0, 2 ) );

		$this->assertTrue( $this->restorer()->restore( $id, $a1 )->is_success() );
		$this->assertSame( $h1, $this->hash_of( $id ) );

		$this->assertTrue( $this->restorer()->restore( $id, $a2 )->is_success() );
		$this->assertSame( $h2, $this->hash_of( $id ) );
	}

	/**
	 * A restore refuses while a replace holds the same lock.
	 */
	public function test_lock_contention_is_refused(): void {
		$id       = $this->attachment();
		$artifact = $this->replace_with( $id, 120, 200 );
		$hash     = $this->hash_of( $id );
		$lock     = new Lock();
		$token    = $lock->acquire( Replacer::LOCK_PREFIX . $id, 60 );

		$this->assertNotNull( $token );
		$this->assertSame( 'restore_in_progress', $this->restorer()->restore( $id, $artifact )->code() );
		$this->assertSame( $hash, $this->hash_of( $id ) );

		$lock->release( Replacer::LOCK_PREFIX . $id, (string) $token );

		$this->assertTrue( $this->restorer()->restore( $id, $artifact )->is_success() );
	}

	/**
	 * A missing attachment, a non-image attachment and an attachment with no file are refused.
	 */
	public function test_unsuitable_attachments_are_refused(): void {
		$text = (int) wp_insert_attachment(
			array(
				'post_mime_type' => 'text/plain',
				'post_title'     => 'Text',
				'post_status'    => 'inherit',
			)
		);

		$this->assertSame( 'attachment_not_found', $this->restorer()->restore( 99999999, 'x' )->code() );
		$this->assertSame( 'not_an_image_attachment', $this->restorer()->restore( $text, 'x' )->code() );
	}

	/**
	 * Retention clean-up still removes only old artifacts and never the restored undo artifact.
	 */
	public function test_cleanup_compatibility(): void {
		$id     = $this->attachment( 300, 40 );
		$now    = time();
		$old    = ( new RetentionStore( static fn (): int => $now - 31 * self::DAY ) )->keep( $id );
		$store  = new RetentionStore( static fn (): int => $now );
		$result = $this->restorer( $store )->restore( $id, (string) $old );

		$this->assertTrue( $result->is_success(), $result->code() );
		$this->assertSame( 1, $store->purge_older_than( 30, 100 ) );
		$this->assertContains( $result->undo_artifact(), $store->list( $id ) );
		$this->assertNotContains( (string) $old, $store->list( $id ) );
		$this->assertFileExists( (string) get_attached_file( $id ) );
	}
}
