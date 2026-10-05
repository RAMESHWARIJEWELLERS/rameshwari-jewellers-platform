<?php
/**
 * Media audit tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Integration\Media;

use Rameshwari\Core\Cron\MediaAudit;
use Rameshwari\Core\Data\Capabilities;
use Rameshwari\Core\Product\ProductSystemScope;
use Rameshwari\Core\Services\Media\ReferenceGuard;
use Rameshwari\Core\Services\Media\ReferenceSources;
use Rameshwari\Core\Services\Media\RetentionStore;
use Rameshwari\Core\Services\Media\Value\MediaAuditReport;
use Rameshwari\Core\Support\Logger;

/**
 * The audit against real posts, terms and attachments, with a fixed clock and no stored result.
 */
final class MediaAuditTest extends \WP_UnitTestCase {

	private const DAY = 86400;

	/**
	 * The fixed current time.
	 *
	 * @var int
	 */
	private int $now = 0;

	/**
	 * Upload base used by this test.
	 *
	 * @var string
	 */
	private string $base = '';

	/**
	 * Lines written by the logger.
	 *
	 * @var array<int,string>
	 */
	private array $log = array();

	/**
	 * Write statements seen while watching the database.
	 *
	 * @var array<int,string>
	 */
	private array $writes = array();

	/**
	 * Registers the plugin's types, signs in an administrator and redirects uploads.
	 */
	public function set_up(): void {
		parent::set_up();
		do_action( 'init' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Exercises the plugin's normal init registration.
		Capabilities::apply();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->now  = time();
		$uploads    = wp_upload_dir( null, false );
		$this->base = $uploads['basedir'] . '/rjtest-' . wp_generate_uuid4();

		wp_mkdir_p( $this->base );
		add_filter( 'upload_dir', array( $this, 'redirect_uploads' ) );
		wp_upload_dir( null, true, true );
	}

	/**
	 * Removes hooks and the private folder, and registers the types again.
	 */
	public function tear_down(): void {
		remove_filter( 'upload_dir', array( $this, 'redirect_uploads' ) );
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

		do_action( 'init' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Restores the plugin's registrations.

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
	 * The audit under test.
	 *
	 * @param int $page_size IDs per query.
	 * @param int $max_pages Most pages per check.
	 * @return MediaAudit
	 */
	private function audit( int $page_size = 50, int $max_pages = 20 ): MediaAudit {
		$logger = new Logger(
			function ( string $line ): void {
				$this->log[] = $line;
			},
			true
		);
		$now    = $this->now;

		return new MediaAudit( new ReferenceGuard( new ReferenceSources(), $logger ), new RetentionStore( static fn (): int => $now ), $logger, static fn (): int => $now, $page_size, $max_pages );
	}

	/**
	 * Creates a post of one of the plugin's types without the product guard.
	 *
	 * @param string $type   Post type.
	 * @param string $status Post status.
	 * @return int
	 */
	private function post( string $type, string $status = 'publish' ): int {
		$id = (int) ProductSystemScope::run(
			static fn() => wp_insert_post(
				array(
					'post_type'   => $type,
					'post_status' => 'draft',
					'post_title'  => 'Audit fixture',
				)
			)
		);

		if ( 'draft' !== $status ) {
			global $wpdb;

			// Fixture only: set the status directly so no publish transition runs and the product guard cannot demote the post.
			$wpdb->update( $wpdb->posts, array( 'post_status' => $status ), array( 'ID' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture.

			clean_post_cache( $id );
		}

		return $id;
	}

	/**
	 * Writes post meta without the product guard.
	 *
	 * @param int    $id    Post ID.
	 * @param string $key   Meta key.
	 * @param mixed  $value Value.
	 * @return void
	 */
	private function meta( int $id, string $key, mixed $value ): void {
		ProductSystemScope::run( static fn() => update_post_meta( $id, $key, $value ) );
	}

	/**
	 * Creates an attachment dated a number of days before now.
	 *
	 * @param int    $days Age in days.
	 * @param string $mime MIME type.
	 * @return int
	 */
	private function attachment( int $days = 0, string $mime = 'image/png' ): int {
		$when = gmdate( 'Y-m-d H:i:s', $this->now - $days * self::DAY );

		return (int) wp_insert_attachment(
			array(
				'post_mime_type' => $mime,
				'post_title'     => 'Fixture',
				'post_status'    => 'inherit',
				'post_date'      => $when,
				'post_date_gmt'  => $when,
			)
		);
	}

	/**
	 * The finding IDs of one check.
	 *
	 * @param MediaAuditReport $report Report.
	 * @param string           $check  Check name.
	 * @return array<int,int>
	 */
	private function ids( MediaAuditReport $report, string $check ): array {
		return array_map( static fn ( array $finding ): int => $finding['id'], $report->findings()[ $check ] );
	}

	/**
	 * A run returns a report listing every check, and can be called any time.
	 */
	public function test_on_demand_run_returns_a_structured_report(): void {
		$report = $this->audit()->run();

		$this->assertInstanceOf( MediaAuditReport::class, $report );
		$this->assertSame( MediaAuditReport::CHECKS, array_keys( $report->findings() ) );
		$this->assertTrue( $report->is_complete(), implode( ',', $report->failures() ) );
		$this->assertSame( 0, $report->count() );
	}

	/**
	 * Published products with no usable image are found; others are not.
	 */
	public function test_products_without_images(): void {
		$bare     = $this->post( 'rj_product' );
		$featured = $this->post( 'rj_product' );
		$gallery  = $this->post( 'rj_product' );
		$ghost    = $this->post( 'rj_product' );
		$draft    = $this->post( 'rj_product', 'draft' );
		$real_a   = $this->attachment();
		$real_b   = $this->attachment();

		$this->meta( $featured, '_thumbnail_id', $real_a );
		$this->meta( $gallery, '_rj_gallery', array( $real_b ) );
		$this->meta( $ghost, '_thumbnail_id', 99999999 );

		$found = $this->ids( $this->audit()->run(), 'products_without_images' );

		$this->assertSame( array( $bare, $ghost ), $found );
		$this->assertNotContains( $draft, $found );
	}

	/**
	 * Category terms with no real image are found.
	 */
	public function test_categories_without_thumbnails(): void {
		$bare  = (int) self::factory()->term->create( array( 'taxonomy' => 'rj_category' ) );
		$good  = (int) self::factory()->term->create( array( 'taxonomy' => 'rj_category' ) );
		$ghost = (int) self::factory()->term->create( array( 'taxonomy' => 'rj_category' ) );

		update_term_meta( $good, '_rj_image_id', $this->attachment() );
		update_term_meta( $ghost, '_rj_image_id', 99999999 );

		$found = $this->ids( $this->audit()->run(), 'categories_without_thumbnails' );

		$this->assertContains( $bare, $found );
		$this->assertContains( $ghost, $found );
		$this->assertNotContains( $good, $found );
	}

	/**
	 * Reels are judged by their own source type; an external-URL reel is not judged.
	 */
	public function test_reels_without_source(): void {
		$upload_bad  = $this->post( 'rj_reel' );
		$upload_good = $this->post( 'rj_reel' );
		$youtube_ok  = $this->post( 'rj_reel' );
		$youtube_bad = $this->post( 'rj_reel' );
		$insta_bad   = $this->post( 'rj_reel' );
		$external    = $this->post( 'rj_reel' );

		$this->meta( $upload_bad, '_rj_source_type', 'upload' );
		$this->meta( $upload_good, '_rj_source_type', 'upload' );
		$this->meta( $upload_good, '_rj_attachment_id', $this->attachment( 0, 'video/mp4' ) );
		$this->meta( $youtube_ok, '_rj_source_type', 'youtube' );
		$this->meta( $youtube_ok, '_rj_video_id', 'abc123' );
		$this->meta( $youtube_bad, '_rj_source_type', 'youtube' );
		$this->meta( $insta_bad, '_rj_source_type', 'instagram' );
		$this->meta( $external, '_rj_source_type', 'url' );

		$found = $this->ids( $this->audit()->run(), 'reels_without_source' );

		$this->assertEqualsCanonicalizing( array( $upload_bad, $youtube_bad, $insta_bad ), $found );
	}

	/**
	 * Only attachments strictly older than 90 days with no reference are reported.
	 */
	public function test_unreferenced_attachments_age_boundary(): void {
		$old    = $this->attachment( 91 );
		$exact  = $this->attachment( 90 );
		$recent = $this->attachment( 89 );
		$used   = $this->attachment( 120 );
		$post   = $this->post( 'rj_product' );

		$this->meta( $post, '_thumbnail_id', $used );

		$found = $this->ids( $this->audit()->run(), 'unreferenced_attachments' );

		$this->assertSame( array( $old ), $found );
		$this->assertNotContains( $exact, $found );
		$this->assertNotContains( $recent, $found );
		$this->assertNotContains( $used, $found );
	}

	/**
	 * Reading in small pages finds everything, in ID order, and says nothing was cut off.
	 */
	public function test_paging_reaches_every_item(): void {
		$ids = array();

		for ( $i = 0; $i < 5; $i++ ) {
			$ids[] = $this->attachment( 100 + $i );
		}

		$report = $this->audit( 2, 10 )->run();

		sort( $ids );

		$this->assertSame( $ids, $this->ids( $report, 'unreferenced_attachments' ) );
		$this->assertNotContains( 'unreferenced_attachments', $report->truncated() );
	}

	/**
	 * A check that reaches its page bound reports the cut instead of reading on.
	 */
	public function test_page_bound_is_reported(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			$this->attachment( 100 + $i );
		}

		$report = $this->audit( 2, 2 )->run();

		$this->assertCount( 4, $report->findings()['unreferenced_attachments'] );
		$this->assertContains( 'unreferenced_attachments', $report->truncated() );
		$this->assertFalse( $report->is_complete() );
	}

	/**
	 * Retained files past 30 days go through RetentionStore; newer ones stay.
	 */
	public function test_retention_cleanup_uses_the_store(): void {
		$upload = wp_upload_bits( 'rjaudit-' . wp_generate_uuid4() . '.txt', null, 'data' );
		$id     = (int) wp_insert_attachment(
			array(
				'post_mime_type' => 'text/plain',
				'post_title'     => 'Fixture',
				'post_status'    => 'inherit',
			),
			$upload['file']
		);

		update_attached_file( $id, $upload['file'] );

		$now   = $this->now;
		$old   = ( new RetentionStore( static fn (): int => $now - 31 * self::DAY ) )->keep( $id );
		$fresh = ( new RetentionStore( static fn (): int => $now - 29 * self::DAY ) )->keep( $id );

		$this->assertNotNull( $old );
		$this->assertNotNull( $fresh );

		$report = $this->audit()->run();
		$left   = ( new RetentionStore() )->list( $id );

		$this->assertSame( 1, $report->retention_purged() );
		$this->assertNotContains( $old, $left );
		$this->assertContains( $fresh, $left );
		$this->assertFileExists( (string) get_attached_file( $id ) );
		$this->assertSame( 'attachment', get_post_type( $id ) );
	}

	/**
	 * One failing check is reported and the rest still run.
	 */
	public function test_one_failing_check_does_not_stop_the_others(): void {
		$bare = $this->post( 'rj_product' );

		unregister_taxonomy( 'rj_category' );

		$report = $this->audit()->run();

		$this->assertArrayHasKey( 'categories_without_thumbnails', $report->failures() );
		$this->assertNotSame( '', $report->failures()['categories_without_thumbnails'] );
		$this->assertContains( $bare, $this->ids( $report, 'products_without_images' ) );
		$this->assertFalse( $report->is_complete() );
		$this->assertArrayNotHasKey( 'products_without_images', $report->failures() );
	}

	/**
	 * The audit changes no data: no write of any kind, no option, no stored result, nothing deleted.
	 */
	public function test_audit_writes_nothing(): void {
		$old     = $this->attachment( 120 );
		$used    = $this->attachment( 120 );
		$product = $this->post( 'rj_product' );

		$this->meta( $product, '_thumbnail_id', $used );

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

		$report = $this->audit()->run();

		remove_all_filters( 'query' );

		$this->assertSame( array( $old ), $this->ids( $report, 'unreferenced_attachments' ) );
		$this->assertSame( array(), $this->writes );
		$this->assertSame( $options, wp_load_alloptions( true ) );
		$this->assertSame( 'attachment', get_post_type( $old ) );
		$this->assertSame( 'attachment', get_post_type( $used ) );
	}

	/**
	 * Attachment findings carry an edit link.
	 */
	public function test_findings_carry_edit_links(): void {
		$old = $this->attachment( 120 );

		$finding = $this->audit()->run()->findings()['unreferenced_attachments'][0];

		$this->assertSame( $old, $finding['id'] );
		$this->assertStringContainsString( 'post=' . $old, $finding['edit_link'] );
	}

	/**
	 * The scheduled entry point logs one count-only line.
	 */
	public function test_cron_logs_a_single_summary_line(): void {
		$this->attachment( 120 );

		$this->audit()->cron();

		$lines = array_values( array_filter( $this->log, static fn ( string $line ): bool => str_contains( $line, 'Media audit:' ) ) );

		$this->assertCount( 1, $lines );
		$this->assertStringContainsString( 'unreferenced_attachments=1', $lines[0] );
	}

	/**
	 * Paging settings below 1 are refused.
	 */
	public function test_invalid_paging_is_rejected(): void {
		foreach ( array( array( 0, 5 ), array( 5, 0 ) ) as $args ) {
			try {
				$this->audit( $args[0], $args[1] );

				$this->fail( 'Invalid paging was accepted.' );
			} catch ( \InvalidArgumentException $expected ) {
				$this->assertNotSame( '', $expected->getMessage() );
			}
		}
	}
}
