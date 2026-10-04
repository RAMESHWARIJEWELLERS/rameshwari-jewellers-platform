<?php
/**
 * Retention store tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Integration\Media;

use Rameshwari\Core\Services\Media\RetentionStore;

/**
 * Real files under a per-test upload base. Age is controlled by an injected clock, never by sleeping.
 */
final class RetentionStoreTest extends \WP_UnitTestCase {

	private const DAY = 86400;

	/**
	 * Upload base used by this test.
	 *
	 * @var string
	 */
	private string $base = '';

	/**
	 * Clock the store reads.
	 *
	 * @var int
	 */
	private int $now = 2000000000;

	/**
	 * Write statements seen while a test watches the database.
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
		$this->now  = 2000000000;

		add_filter( 'upload_dir', array( $this, 'redirect_uploads' ) );

		// Rebuild WordPress's cached upload locations so the filter is read from the first call.
		wp_upload_dir( null, true, true );
	}

	/**
	 * Removes the private folder.
	 */
	public function tear_down(): void {
		remove_filter( 'upload_dir', array( $this, 'redirect_uploads' ) );
		remove_all_filters( 'wp_delete_file' );
		remove_all_filters( 'query' );

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
	 * Store under test, reading the injected clock.
	 *
	 * @return RetentionStore
	 */
	private function store(): RetentionStore {
		return new RetentionStore( fn (): int => $this->now );
	}

	/**
	 * Retention directory for this test.
	 *
	 * @return string
	 */
	private function dir(): string {
		return $this->base . '/' . RetentionStore::DIRECTORY;
	}

	/**
	 * Reads a local test file.
	 *
	 * @param string $path File path.
	 * @return string
	 */
	private function contents( string $path ): string {
		return (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local test file; no remote URL is involved.
	}

	/**
	 * Writes a local test file, creating its folder.
	 *
	 * @param string $path    File path.
	 * @param string $content Content.
	 */
	private function write( string $path, string $content ): void {
		wp_mkdir_p( dirname( $path ) );

		file_put_contents( $path, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local test file in this test's private folder.
	}

	/**
	 * Creates an attachment with a real file.
	 *
	 * @param string $content File content.
	 * @return int Attachment ID.
	 */
	private function attachment( string $content = 'original' ): int {
		$upload = wp_upload_bits( 'rjret-' . wp_generate_uuid4() . '.txt', null, $content );

		$this->assertEmpty( $upload['error'], 'Fixture upload failed: ' . (string) $upload['error'] );
		$this->assertFileExists( $upload['file'] );

		$id = wp_insert_attachment(
			array(
				'post_mime_type' => 'text/plain',
				'post_title'     => 'Fixture',
				'post_status'    => 'inherit',
			),
			$upload['file'],
			0,
			true
		);

		$this->assertIsInt( $id, $id instanceof \WP_Error ? $id->get_error_message() : 'Attachment insert did not return an ID.' );
		$this->assertGreaterThan( 0, $id );

		update_attached_file( $id, $upload['file'] );

		$path = get_attached_file( $id );

		$this->assertSame( 'attachment', get_post_type( $id ) );
		$this->assertIsString( $path );
		$this->assertFileExists( (string) $path );
		$this->assertFileIsReadable( (string) $path );

		return $id;
	}

	/**
	 * Current file path of an attachment.
	 *
	 * @param int $id Attachment ID.
	 * @return string
	 */
	private function current( int $id ): string {
		return (string) get_attached_file( $id );
	}

	/**
	 * Keeps a file and returns the artifact name, failing the test when it cannot.
	 *
	 * @param int $id Attachment ID.
	 * @return string
	 */
	private function keep( int $id ): string {
		$name = $this->store()->keep( $id );

		$this->assertIsString( $name );

		return (string) $name;
	}

	/**
	 * Starts recording write statements.
	 */
	private function watch(): void {
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
	}

	/**
	 * The current file is copied and left exactly as it was.
	 */
	public function test_keep_copies_current_file_and_leaves_it_untouched(): void {
		$id   = $this->attachment( 'alpha' );
		$name = $this->keep( $id );

		$this->assertSame( 'alpha', $this->contents( $this->dir() . '/' . $name ) );
		$this->assertSame( 'alpha', $this->contents( $this->current( $id ) ) );
		$this->assertTrue( is_file( $this->dir() . '/' . $name ) );
	}

	/**
	 * The name is id, timestamp, random token and sanitised basename.
	 */
	public function test_artifact_name_follows_frozen_pattern(): void {
		$id   = $this->attachment();
		$name = $this->keep( $id );

		$this->assertMatchesRegularExpression( '/^' . $id . '-2000000000-[a-f0-9]{16}-rjret-[a-f0-9-]+\.txt$/', $name );
	}

	/**
	 * The artifact sits in a plugin-owned folder under the upload base.
	 */
	public function test_artifact_lives_under_upload_basedir(): void {
		$name = $this->keep( $this->attachment() );
		$real = realpath( $this->dir() . '/' . $name );

		$this->assertIsString( $real );
		$this->assertStringStartsWith( (string) realpath( $this->base ) . DIRECTORY_SEPARATOR . RetentionStore::DIRECTORY . DIRECTORY_SEPARATOR, (string) $real );
	}

	/**
	 * The folder carries a zero-byte index file and a deny rule.
	 */
	public function test_directory_carries_empty_index_and_deny_rule(): void {
		$this->keep( $this->attachment() );

		$this->assertFileExists( $this->dir() . '/index.php' );
		$this->assertSame( 0, filesize( $this->dir() . '/index.php' ) );
		$this->assertFileExists( $this->dir() . '/.htaccess' );
		$this->assertStringContainsString( 'Require all denied', $this->contents( $this->dir() . '/.htaccess' ) );
		$this->assertStringContainsString( 'Deny from all', $this->contents( $this->dir() . '/.htaccess' ) );
	}

	/**
	 * Missing guard files are added to an existing folder; present ones are left as they are.
	 */
	public function test_missing_guard_files_are_added_and_existing_ones_kept(): void {
		$id = $this->attachment();

		$this->write( $this->dir() . '/.htaccess', 'custom rule' );

		$this->keep( $id );

		$this->assertSame( 'custom rule', $this->contents( $this->dir() . '/.htaccess' ) );
		$this->assertSame( 0, filesize( $this->dir() . '/index.php' ) );

		wp_delete_file( $this->dir() . '/index.php' );

		$this->keep( $id );

		$this->assertFileExists( $this->dir() . '/index.php' );
		$this->assertSame( 0, filesize( $this->dir() . '/index.php' ) );
	}

	/**
	 * Guard files are never listed as artifacts.
	 */
	public function test_guard_files_are_not_listed_as_artifacts(): void {
		$id   = $this->attachment();
		$name = $this->keep( $id );

		$this->assertSame( array( $name ), $this->store()->list( $id ) );
		$this->assertSame( 0, $this->store()->purge_older_than( 0, 100 ) );
		$this->assertFileExists( $this->dir() . '/index.php' );
		$this->assertFileExists( $this->dir() . '/.htaccess' );
	}

	/**
	 * Well-formed names are read back; slash, backslash and control characters are refused.
	 */
	public function test_name_parsing_accepts_valid_and_rejects_unsafe_names(): void {
		$id    = $this->attachment( 'one' );
		$valid = $id . '-100-aaaaaaaaaaaaaaaa-photo (1) é.jpg';

		$this->write( $this->dir() . '/' . $valid, 'x' );
		$this->write( $this->current( $id ), 'two' );

		$this->assertSame( array( $valid ), $this->store()->list( $id ) );

		foreach ( array( $id . '-100-aaaaaaaaaaaaaaaa-a/b.jpg', $id . '-100-aaaaaaaaaaaaaaaa-a\\b.jpg', $id . "-100-aaaaaaaaaaaaaaaa-a\x01b.jpg", $id . "-100-aaaaaaaaaaaaaaaa-a\nb.jpg", $id . "-100-aaaaaaaaaaaaaaaa-a\x7Fb.jpg", $id . '-100-aaaaaaaaaaaaaaaa-', $id . '-100-AAAAAAAAAAAAAAAA-x.jpg', $id . '-100-aaaaaaaaaaaaaaaa-..\\..\\x.jpg' ) as $bad ) {
			$this->assertFalse( $this->store()->restore( $id, $bad ), bin2hex( $bad ) );
		}

		$this->assertSame( 'two', $this->contents( $this->current( $id ) ) );
	}

	/**
	 * The name kept from an awkward upload contains no slash, backslash or control character.
	 */
	public function test_kept_names_hold_only_safe_characters(): void {
		$name = $this->keep( $this->attachment() );

		$this->assertSame( 0, preg_match( '~[[:cntrl:]/\\\\]~', $name ) );
		$this->assertSame( $name, basename( $name ) );
	}

	/**
	 * Repeated keeps, even in the same second, give distinct artifacts and overwrite nothing.
	 */
	public function test_repeated_keep_creates_distinct_artifacts(): void {
		$id    = $this->attachment( 'one' );
		$first = $this->keep( $id );

		$this->write( $this->current( $id ), 'two' );

		$second = $this->keep( $id );

		$this->assertNotSame( $first, $second );
		$this->assertSame( 'one', $this->contents( $this->dir() . '/' . $first ) );
		$this->assertSame( 'two', $this->contents( $this->dir() . '/' . $second ) );
	}

	/**
	 * A missing attachment, a zero ID and a non-attachment post all give null.
	 */
	public function test_keep_missing_attachment_returns_null(): void {
		$post = self::factory()->post->create();

		$this->assertNull( $this->store()->keep( 99999999 ) );
		$this->assertNull( $this->store()->keep( 0 ) );
		$this->assertNull( $this->store()->keep( $post ) );
	}

	/**
	 * An attachment whose file is gone gives null.
	 */
	public function test_keep_with_missing_file_returns_null(): void {
		$id = $this->attachment();

		wp_delete_file( $this->current( $id ) );

		$this->assertNull( $this->store()->keep( $id ) );
	}

	/**
	 * Keeping leaves the attachment record and its meta unchanged.
	 */
	public function test_keep_changes_no_attachment_state(): void {
		$id   = $this->attachment();
		$meta = get_post_meta( $id );
		$post = get_post( $id );

		$this->keep( $id );

		$this->assertSame( $meta, get_post_meta( $id ) );
		$this->assertEquals( $post, get_post( $id ) );
	}

	/**
	 * Only this attachment's artifacts are listed, newest first.
	 */
	public function test_list_returns_only_this_attachments_artifacts_newest_first(): void {
		$id    = $this->attachment();
		$other = $this->attachment();

		$this->now = 1000000000;
		$oldest    = $this->keep( $id );
		$this->now = 1000000500;
		$middle    = $this->keep( $id );
		$this->now = 1000001000;
		$newest    = $this->keep( $id );
		$foreign   = $this->keep( $other );

		$this->assertSame( array( $newest, $middle, $oldest ), $this->store()->list( $id ) );
		$this->assertSame( array( $foreign ), $this->store()->list( $other ) );
	}

	/**
	 * Equal timestamps still give one fixed order.
	 */
	public function test_list_order_is_deterministic_for_equal_timestamps(): void {
		$id = $this->attachment();

		for ( $i = 0; $i < 4; $i++ ) {
			$this->keep( $id );
		}

		$listed = $this->store()->list( $id );
		$sorted = $listed;

		rsort( $sorted );

		$this->assertCount( 4, $listed );
		$this->assertSame( $sorted, $listed );
		$this->assertSame( $listed, $this->store()->list( $id ) );
	}

	/**
	 * Unrelated files, malformed names and directories are ignored.
	 */
	public function test_list_ignores_unrelated_files_and_directories(): void {
		$id   = $this->attachment();
		$name = $this->keep( $id );

		$this->write( $this->dir() . '/notes.txt', 'x' );
		$this->write( $this->dir() . '/' . $id . '-bad-name.txt', 'x' );
		wp_mkdir_p( $this->dir() . '/' . $id . '-100-aaaaaaaaaaaaaaaa-dir.txt' );

		$this->assertSame( array( $name ), $this->store()->list( $id ) );
	}

	/**
	 * An ID that merely starts with the same digits is not matched.
	 */
	public function test_list_does_not_prefix_match_other_ids(): void {
		$id = $this->attachment();

		$this->write( $this->dir() . '/' . $id . '0-100-aaaaaaaaaaaaaaaa-x.txt', 'x' );

		$this->assertSame( array(), $this->store()->list( $id ) );
	}

	/**
	 * With no retention folder, the list is empty and nothing is created.
	 */
	public function test_list_missing_directory_returns_empty(): void {
		$this->assertSame( array(), $this->store()->list( $this->attachment() ) );
		$this->assertDirectoryDoesNotExist( $this->dir() );
		$this->assertSame( array(), $this->store()->list( 0 ) );
	}

	/**
	 * Restore puts the selected file back and leaves the ID alone.
	 */
	public function test_restore_returns_file_and_keeps_id(): void {
		$id   = $this->attachment( 'one' );
		$name = $this->keep( $id );

		$this->write( $this->current( $id ), 'two' );

		$this->assertTrue( $this->store()->restore( $id, $name ) );
		$this->assertSame( 'one', $this->contents( $this->current( $id ) ) );
		$this->assertSame( $id, get_post( $id )->ID );
	}

	/**
	 * Restore first keeps the file it is about to replace, so it can be undone.
	 */
	public function test_restore_is_undoable(): void {
		$id   = $this->attachment( 'one' );
		$name = $this->keep( $id );

		$this->write( $this->current( $id ), 'two' );

		$this->now += 10;

		$this->assertTrue( $this->store()->restore( $id, $name ) );

		$listed = $this->store()->list( $id );

		$this->assertCount( 2, $listed );
		$this->assertSame( 'two', $this->contents( $this->dir() . '/' . $listed[0] ) );

		$this->now += 10;

		$this->assertTrue( $this->store()->restore( $id, $listed[0] ) );
		$this->assertSame( 'two', $this->contents( $this->current( $id ) ) );
	}

	/**
	 * The restored artifact stays until clean-up.
	 */
	public function test_restore_keeps_the_selected_artifact(): void {
		$id   = $this->attachment( 'one' );
		$name = $this->keep( $id );

		$this->store()->restore( $id, $name );

		$this->assertSame( 'one', $this->contents( $this->dir() . '/' . $name ) );
		$this->assertContains( $name, $this->store()->list( $id ) );
	}

	/**
	 * Names that are not artifact names are refused and the current file is untouched.
	 */
	public function test_restore_rejects_invalid_names(): void {
		$id = $this->attachment( 'one' );

		$this->keep( $id );

		foreach ( array( '', 'x', 'a/b', '..', '.', "{$id}-100-zz-x.txt", "{$id}-abc-aaaaaaaaaaaaaaaa-x.txt" ) as $name ) {
			$this->assertFalse( $this->store()->restore( $id, $name ), $name );
		}

		$this->assertSame( 'one', $this->contents( $this->current( $id ) ) );
		$this->assertCount( 1, $this->store()->list( $id ) );
	}

	/**
	 * Path tricks around a real artifact name are refused.
	 */
	public function test_restore_rejects_path_traversal(): void {
		$id   = $this->attachment( 'one' );
		$name = $this->keep( $id );

		$this->write( $this->base . '/' . $name, 'outside' );
		$this->write( $this->current( $id ), 'two' );

		foreach ( array( '../' . $name, '..\\' . $name, '/' . $name, 'sub/' . $name, $this->dir() . '/' . $name, $name . '/..' ) as $bad ) {
			$this->assertFalse( $this->store()->restore( $id, $bad ), $bad );
		}

		$this->assertSame( 'two', $this->contents( $this->current( $id ) ) );
	}

	/**
	 * Another attachment's artifact can never be restored, even if its name is used.
	 */
	public function test_restore_rejects_another_attachments_artifact(): void {
		$id    = $this->attachment( 'mine' );
		$other = $this->attachment( 'theirs' );
		$name  = $this->keep( $other );

		$this->write( $this->dir() . '/' . $id . '-100-aaaaaaaaaaaaaaaa-fake.txt', 'fabricated' );

		$this->assertFalse( $this->store()->restore( $id, $name ) );
		$this->assertSame( 'mine', $this->contents( $this->current( $id ) ) );
		$this->assertNotContains( $name, $this->store()->list( $id ) );
	}

	/**
	 * An artifact that does not exist returns false.
	 */
	public function test_restore_missing_artifact_returns_false(): void {
		$id = $this->attachment( 'one' );

		$this->keep( $id );

		$this->assertFalse( $this->store()->restore( $id, $id . '-100-aaaaaaaaaaaaaaaa-gone.txt' ) );
		$this->assertSame( 'one', $this->contents( $this->current( $id ) ) );
	}

	/**
	 * When the current file cannot be preserved, nothing is written.
	 */
	public function test_restore_writes_nothing_when_current_file_is_missing(): void {
		$id      = $this->attachment( 'one' );
		$name    = $this->keep( $id );
		$current = $this->current( $id );

		wp_delete_file( $current );

		$this->assertFalse( $this->store()->restore( $id, $name ) );
		$this->assertFileDoesNotExist( $current );
		$this->assertSame( array( $name ), $this->store()->list( $id ) );
	}

	/**
	 * A symbolic link posing as an artifact is never restored.
	 */
	public function test_restore_refuses_a_symlink_artifact(): void {
		$id = $this->attachment( 'one' );

		$this->keep( $id );
		$this->write( $this->base . '/outside.txt', 'outside' );

		$link = $this->dir() . '/' . $id . '-100-aaaaaaaaaaaaaaaa-link.txt';

		if ( ! function_exists( 'symlink' ) || ! @symlink( $this->base . '/outside.txt', $link ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Symlinks may be unavailable on this platform; the test then skips.
			$this->markTestSkipped( 'Symbolic links are not available on this platform.' );
		}

		$this->assertFalse( $this->store()->restore( $id, basename( $link ) ) );
		$this->assertSame( 'one', $this->contents( $this->current( $id ) ) );
		$this->assertNotContains( basename( $link ), $this->store()->list( $id ) );
	}

	/**
	 * The live attachment file is not an artifact and cannot be selected as one.
	 */
	public function test_active_file_is_not_selectable_as_an_artifact(): void {
		$id = $this->attachment( 'one' );

		$this->keep( $id );

		$this->assertFalse( $this->store()->restore( $id, basename( $this->current( $id ) ) ) );
		$this->assertNotContains( basename( $this->current( $id ) ), $this->store()->list( $id ) );
	}

	/**
	 * Only artifacts strictly older than the threshold go; newer ones and a boundary one stay.
	 */
	public function test_purge_older_than_deletes_old_and_keeps_new(): void {
		$id = $this->attachment();

		$this->now = 2000000000 - 31 * self::DAY;
		$old       = $this->keep( $id );
		$this->now = 2000000000 - 30 * self::DAY;
		$boundary  = $this->keep( $id );
		$this->now = 2000000000 - self::DAY;
		$recent    = $this->keep( $id );
		$this->now = 2000000000;

		$this->assertSame( 1, $this->store()->purge_older_than( 30, 10 ) );
		$this->assertFileDoesNotExist( $this->dir() . '/' . $old );
		$this->assertFileExists( $this->dir() . '/' . $boundary );
		$this->assertFileExists( $this->dir() . '/' . $recent );
	}

	/**
	 * The limit caps deletions and the oldest go first.
	 */
	public function test_purge_older_than_respects_the_limit_oldest_first(): void {
		$id    = $this->attachment();
		$names = array();

		foreach ( array( 40, 39, 38 ) as $days ) {
			$this->now = 2000000000 - $days * self::DAY;
			$names[]   = $this->keep( $id );
		}

		$this->now = 2000000000;

		$this->assertSame( 2, $this->store()->purge_older_than( 30, 2 ) );
		$this->assertFileDoesNotExist( $this->dir() . '/' . $names[0] );
		$this->assertFileDoesNotExist( $this->dir() . '/' . $names[1] );
		$this->assertFileExists( $this->dir() . '/' . $names[2] );
	}

	/**
	 * A limit of zero deletes nothing.
	 */
	public function test_purge_older_than_limit_zero_deletes_none(): void {
		$id        = $this->attachment();
		$this->now = 2000000000 - 90 * self::DAY;
		$name      = $this->keep( $id );
		$this->now = 2000000000;

		$this->assertSame( 0, $this->store()->purge_older_than( 30, 0 ) );
		$this->assertFileExists( $this->dir() . '/' . $name );
	}

	/**
	 * Negative days are refused.
	 */
	public function test_purge_older_than_rejects_negative_days(): void {
		$this->expectException( \InvalidArgumentException::class );

		$this->store()->purge_older_than( -1, 10 );
	}

	/**
	 * A negative limit is refused.
	 */
	public function test_purge_older_than_rejects_negative_limit(): void {
		$this->expectException( \InvalidArgumentException::class );

		$this->store()->purge_older_than( 30, -1 );
	}

	/**
	 * One artifact that cannot be removed does not stop the rest, and is not counted.
	 */
	public function test_one_failed_delete_does_not_stop_others(): void {
		$id = $this->attachment();

		$this->now = 2000000000 - 40 * self::DAY;

		$names = array( $this->keep( $id ), $this->keep( $id ), $this->keep( $id ) );
		$stuck = $names[1];

		$this->now = 2000000000;

		add_filter(
			'wp_delete_file',
			static fn ( string $file ): string => basename( $file ) === $stuck ? '' : $file
		);

		$this->assertSame( 2, $this->store()->purge_older_than( 30, 10 ) );
		$this->assertFileExists( $this->dir() . '/' . $stuck );
	}

	/**
	 * The live file and unrelated files survive a purge.
	 */
	public function test_purge_older_than_leaves_current_and_unrelated_files(): void {
		$id        = $this->attachment( 'live' );
		$this->now = 2000000000 - 90 * self::DAY;

		$this->keep( $id );
		$this->write( $this->dir() . '/notes.txt', 'x' );
		$this->write( $this->dir() . '/' . $id . '-bad.txt', 'x' );

		$this->now = 2000000000;
		$this->store()->purge_older_than( 30, 100 );

		$this->assertSame( 'live', $this->contents( $this->current( $id ) ) );
		$this->assertFileExists( $this->dir() . '/notes.txt' );
		$this->assertFileExists( $this->dir() . '/' . $id . '-bad.txt' );
	}

	/**
	 * Only the requested attachment's artifacts are removed.
	 */
	public function test_purge_for_removes_only_that_attachment(): void {
		$id    = $this->attachment();
		$other = $this->attachment();

		$this->keep( $id );
		$this->write( $this->current( $id ), 'two' );
		$this->keep( $id );

		$kept = $this->keep( $other );

		$this->assertSame( 2, $this->store()->purge_for( $id ) );
		$this->assertSame( array(), $this->store()->list( $id ) );
		$this->assertSame( array( $kept ), $this->store()->list( $other ) );
	}

	/**
	 * With nothing to remove, the count is zero and nothing fails.
	 */
	public function test_purge_for_is_safe_when_nothing_exists(): void {
		$id = $this->attachment();

		$this->assertSame( 0, $this->store()->purge_for( $id ) );
		$this->assertSame( 0, $this->store()->purge_for( 0 ) );
		$this->assertDirectoryDoesNotExist( $this->dir() );
	}

	/**
	 * The live file is never removed by purge_for.
	 */
	public function test_purge_for_never_touches_the_current_file(): void {
		$id = $this->attachment( 'live' );

		$this->keep( $id );
		$this->store()->purge_for( $id );

		$this->assertSame( 'live', $this->contents( $this->current( $id ) ) );
	}

	/**
	 * Nothing outside the retention folder is touched, even with a matching name.
	 */
	public function test_nothing_outside_the_retention_directory_is_touched(): void {
		$id        = $this->attachment();
		$decoy     = $this->base . '/' . $id . '-100-aaaaaaaaaaaaaaaa-decoy.txt';
		$this->now = 2000000000 - 90 * self::DAY;

		$this->write( $decoy, 'decoy' );
		$this->keep( $id );

		$this->now = 2000000000;
		$this->store()->purge_older_than( 30, 100 );
		$this->store()->purge_for( $id );

		$this->assertFileExists( $decoy );
	}

	/**
	 * No operation writes to the database, options or meta.
	 */
	public function test_no_operation_writes_to_the_database(): void {
		$id      = $this->attachment( 'one' );
		$store   = $this->store();
		$options = wp_load_alloptions( true );
		$meta    = get_post_meta( $id );

		$this->watch();

		$name = $store->keep( $id );

		$store->list( $id );
		$store->restore( $id, (string) $name );
		$store->purge_older_than( 30, 10 );
		$store->purge_for( $id );

		$this->assertSame( array(), $this->writes );
		$this->assertSame( $options, wp_load_alloptions( true ) );
		$this->assertSame( $meta, get_post_meta( $id ) );
	}
}
