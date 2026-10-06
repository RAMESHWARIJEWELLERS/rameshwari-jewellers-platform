<?php
/**
 * Bulk uploader tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Integration\Media;

use Rameshwari\Core\Admin\Media\BulkUploader;
use Rameshwari\Core\Services\Media\AltText;
use Rameshwari\Core\Services\Media\UploadValidator;
use Rameshwari\Core\Support\Logger;

/**
 * One-file-per-request uploads: nonce, capabilities, validation, storage and alt text.
 */
final class BulkUploaderTest extends \WP_UnitTestCase {

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
	 * Replies the responder received.
	 *
	 * @var array<int,array{0:int,1:array<string,mixed>}>
	 */
	private array $replies = array();

	/**
	 * Times the store was called.
	 *
	 * @var int
	 */
	private int $stores = 0;

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
	 * Cleans hooks, request data, temp files and the private folder.
	 */
	public function tear_down(): void {
		remove_filter( 'upload_dir', array( $this, 'redirect_uploads' ) );

		$_POST    = array();
		$_FILES   = array();
		$_REQUEST = array();

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
	 * An uploader whose store sideloads the file and whose replies are collected.
	 *
	 * @param callable|null $store Replacement store, to inject a failure.
	 * @return BulkUploader
	 */
	private function uploader( ?callable $store = null ): BulkUploader {
		$default = function ( array $file, int $post_id ): mixed {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';

			return media_handle_sideload(
				array(
					'name'     => $file['name'],
					'tmp_name' => $file['tmp_name'],
				),
				$post_id
			);
		};

		$counted = function ( array $file, int $post_id ) use ( $store, $default ): mixed {
			++$this->stores;

			return ( $store ?? $default )( $file, $post_id );
		};

		$responder = function ( int $status, array $body ): void {
			$this->replies[] = array( $status, $body );
		};

		return new BulkUploader( new UploadValidator(), new AltText(), new Logger( static function (): void {} ), $counted, $responder );
	}

	/**
	 * An uploaded-file entry for PNG bytes.
	 *
	 * @param string $name  Client file name.
	 * @param string $bytes File bytes, or a real PNG when empty.
	 * @return array<string,mixed>
	 */
	private function file( string $name = 'photo.png', string $bytes = '' ): array {
		$image = imagecreatetruecolor( 40, 40 );

		$this->assertNotFalse( $image );

		ob_start();
		imagepng( $image );

		$path = wp_tempnam( 'rjbulk' );

		file_put_contents( $path, '' === $bytes ? (string) ob_get_clean() : $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local test file.

		if ( '' !== $bytes ) {
			ob_end_clean();
		}

		$this->temps[] = $path;

		return array(
			'name'     => $name,
			'tmp_name' => $path,
			'error'    => UPLOAD_ERR_OK,
			'size'     => (int) filesize( $path ),
		);
	}

	/**
	 * Signs in a user with a role and extra capabilities.
	 *
	 * @param string            $role Role name.
	 * @param array<int,string> $caps Extra capabilities.
	 * @return int User ID.
	 */
	private function sign_in( string $role, array $caps = array() ): int {
		$id   = self::factory()->user->create( array( 'role' => $role ) );
		$user = new \WP_User( $id );

		foreach ( $caps as $cap ) {
			$user->add_cap( $cap );
		}

		wp_set_current_user( $id );

		return $id;
	}

	/**
	 * Fills the request as the browser would.
	 *
	 * @param string              $slot  Upload slot.
	 * @param array<string,mixed> $file  Uploaded-file entry.
	 * @param bool                $nonce Whether to send a valid nonce.
	 * @param int                 $post  Owner post.
	 * @return void
	 */
	private function request( string $slot, array $file, bool $nonce = true, int $post = 0 ): void {
		$_POST  = array(
			'slot'    => $slot,
			'post_id' => (string) $post,
		);
		$_FILES = array( BulkUploader::FIELD => $file );

		$_REQUEST = $nonce ? array( 'nonce' => wp_create_nonce( BulkUploader::NONCE_ACTION ) ) : array();
	}

	/**
	 * The one reply sent.
	 *
	 * @return array{0:int,1:array<string,mixed>}
	 */
	private function reply(): array {
		$this->assertCount( 1, $this->replies );

		return $this->replies[0];
	}

	/**
	 * No nonce, no work: nothing is stored.
	 */
	public function test_missing_nonce_is_refused(): void {
		$this->sign_in( 'administrator', array( 'rj_manage_catalogue' ) );
		$this->request( 'product', $this->file(), false );
		$this->uploader()->handle();

		$reply = $this->reply();

		$this->assertSame( 403, $reply[0] );
		$this->assertSame( 'bad_nonce', $reply[1]['error_code'] );
		$this->assertSame( 0, $this->stores );
	}

	/**
	 * A valid nonce is not authorisation: a user without the capabilities is refused.
	 */
	public function test_valid_nonce_without_capability_is_refused(): void {
		$this->sign_in( 'subscriber' );
		$this->request( 'product', $this->file() );
		$this->uploader()->handle();

		$this->assertSame( 403, $this->reply()[0] );
		$this->assertSame( 'forbidden', $this->reply()[1]['error_code'] );
		$this->assertSame( 0, $this->stores );
	}

	/**
	 * Upload rights alone are not enough; the slot's business capability is also required.
	 */
	public function test_business_capability_is_required_per_slot(): void {
		$user = $this->sign_in( 'author' );

		$this->assertTrue( user_can( $user, 'upload_files' ) );
		$this->assertSame( 403, $this->uploader()->upload( 'product', 0, $this->file() )['status'] );

		wp_get_current_user()->add_cap( 'rj_manage_catalogue' );

		$this->assertSame( 200, $this->uploader()->upload( 'product', 0, $this->file() )['status'] );
		$this->assertSame( 403, $this->uploader()->upload( 'reel', 0, $this->file() )['status'] );
		$this->assertSame( 403, $this->uploader()->upload( 'category', 0, $this->file() )['status'] );
	}

	/**
	 * Naming a post the user cannot edit is refused.
	 */
	public function test_object_check_applies_when_a_post_is_named(): void {
		$other = self::factory()->post->create();

		$this->sign_in( 'author', array( 'rj_manage_catalogue' ) );

		$result = $this->uploader()->upload( 'product', $other, $this->file() );

		$this->assertSame( 403, $result['status'] );
		$this->assertSame( 'forbidden_object', $result['body']['error_code'] );
		$this->assertSame( 0, $this->stores );
	}

	/**
	 * An unknown slot is refused before any capability or file work.
	 */
	public function test_unknown_slot_is_refused(): void {
		$this->sign_in( 'administrator', array( 'rj_manage_catalogue' ) );

		$result = $this->uploader()->upload( 'hero', 0, $this->file() );

		$this->assertSame( 400, $result['status'] );
		$this->assertSame( 'invalid_slot', $result['body']['error_code'] );
	}

	/**
	 * The shared validator decides; a rejected file is not stored.
	 */
	public function test_validator_decides_and_nothing_is_stored_on_rejection(): void {
		$this->sign_in( 'administrator', array( 'rj_manage_catalogue' ) );

		$bad      = $this->file( 'note.png', 'not an image' );
		$expected = ( new UploadValidator() )->validate( $bad['tmp_name'], 'note.png', 'product' );
		$result   = $this->uploader()->upload( 'product', 0, $bad );

		$this->assertFalse( $expected->is_valid() );
		$this->assertSame( 400, $result['status'] );
		$this->assertSame( $expected->code(), $result['body']['error_code'] );
		$this->assertSame( 0, $this->stores );
	}

	/**
	 * A good file becomes an attachment, and the reply has the documented shape.
	 */
	public function test_valid_upload_succeeds(): void {
		$this->sign_in( 'administrator', array( 'rj_manage_catalogue' ) );

		$result = $this->uploader()->upload( 'product', 0, $this->file() );

		$this->assertSame( 200, $result['status'] );
		$this->assertSame( array( 'status', 'attachment_id', 'error_code' ), array_keys( $result['body'] ) );
		$this->assertSame( 'ok', $result['body']['status'] );
		$this->assertSame( '', $result['body']['error_code'] );
		$this->assertSame( 'attachment', get_post_type( $result['body']['attachment_id'] ) );
		$this->assertSame( 1, $this->stores );
	}

	/**
	 * The whole request path works: nonce, request fields, file entry and reply.
	 */
	public function test_handle_runs_the_whole_request(): void {
		$this->sign_in( 'administrator', array( 'rj_manage_catalogue' ) );
		$this->request( 'product', $this->file() );
		$this->uploader()->handle();

		$reply = $this->reply();

		$this->assertSame( 200, $reply[0] );
		$this->assertGreaterThan( 0, $reply[1]['attachment_id'] );
	}

	/**
	 * Only image types are allowed while the store runs, and the restriction is lifted afterwards.
	 */
	public function test_upload_mimes_is_scoped_to_the_call(): void {
		$this->sign_in( 'administrator', array( 'rj_manage_catalogue' ) );

		$seen = array();

		$this->uploader(
			function ( array $file, int $post_id ) use ( &$seen ): int {
				unset( $file, $post_id );

				$seen = array_keys( apply_filters( 'upload_mimes', array( 'x' => 'y' ) ) );

				return 0;
			}
		)->upload( 'product', 0, $this->file() );

		$this->assertSame( array( 'jpg|jpeg|jpe', 'png', 'webp' ), $seen );
		$this->assertFalse( has_filter( 'upload_mimes', array( BulkUploader::class, 'image_mimes' ) ) );
	}

	/**
	 * A failing or throwing store is a typed error, leaves no filter behind and affects nothing else.
	 */
	public function test_store_failure_is_isolated(): void {
		$this->sign_in( 'administrator', array( 'rj_manage_catalogue' ) );

		foreach ( array( new \WP_Error( 'x', 'no' ), 'oops' ) as $outcome ) {
			$result = $this->uploader( static fn(): mixed => $outcome )->upload( 'product', 0, $this->file() );

			$this->assertSame( 500, $result['status'] );
			$this->assertSame( 'store_failed', $result['body']['error_code'] );
		}

		$throwing = $this->uploader(
			static function (): int {
				throw new \RuntimeException( 'disk' );
			}
		)->upload( 'product', 0, $this->file() );

		$this->assertSame( 'store_failed', $throwing['body']['error_code'] );
		$this->assertFalse( has_filter( 'upload_mimes', array( BulkUploader::class, 'image_mimes' ) ) );
	}

	/**
	 * One bad file does not fail the next one.
	 */
	public function test_one_bad_file_does_not_fail_the_batch(): void {
		$this->sign_in( 'administrator', array( 'rj_manage_catalogue' ) );

		$uploader = $this->uploader();
		$bad      = $uploader->upload( 'product', 0, $this->file( 'a.png', 'junk' ) );
		$good     = $uploader->upload( 'product', 0, $this->file( 'b.png' ) );

		$this->assertSame( 400, $bad['status'] );
		$this->assertSame( 200, $good['status'] );
	}

	/**
	 * A missing or failed file entry is a typed error.
	 */
	public function test_bad_file_entry_is_refused(): void {
		$this->sign_in( 'administrator', array( 'rj_manage_catalogue' ) );

		$entry          = $this->file();
		$entry['error'] = UPLOAD_ERR_PARTIAL;

		$this->assertSame( 'upload_error', $this->uploader()->upload( 'product', 0, array() )['body']['error_code'] );
		$this->assertSame( 'upload_error', $this->uploader()->upload( 'product', 0, $entry )['body']['error_code'] );
		$this->assertSame( 0, $this->stores );
	}

	/**
	 * Alt text comes from the owner post's title, and only when blank.
	 */
	public function test_alt_text_is_filled_from_the_owner_title(): void {
		$user = $this->sign_in( 'author', array( 'rj_manage_catalogue' ) );
		$post = self::factory()->post->create(
			array(
				'post_author' => $user,
				'post_title'  => 'Kundan Necklace',
			)
		);

		$result = $this->uploader()->upload( 'product', $post, $this->file() );

		$this->assertSame( 200, $result['status'] );
		$this->assertSame( 'Kundan Necklace', get_post_meta( $result['body']['attachment_id'], '_wp_attachment_image_alt', true ) );
	}

	/**
	 * Without an owner post no alt text is invented.
	 */
	public function test_no_owner_means_no_alt_text(): void {
		$this->sign_in( 'administrator', array( 'rj_manage_catalogue' ) );

		$result = $this->uploader()->upload( 'product', 0, $this->file() );

		$this->assertSame( '', (string) get_post_meta( $result['body']['attachment_id'], '_wp_attachment_image_alt', true ) );
	}

	/**
	 * The module's AJAX hook is for signed-in users only.
	 */
	public function test_action_name_has_no_public_variant(): void {
		$this->assertSame( 'rj_media_upload', BulkUploader::ACTION );
		$this->assertFalse( has_action( 'wp_ajax_nopriv_' . BulkUploader::ACTION ) );
	}
}
