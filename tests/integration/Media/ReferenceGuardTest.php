<?php
/**
 * Reference guard tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Integration\Media;

use Rameshwari\Core\Data\Options;
use Rameshwari\Core\Services\Media\ReferenceGuard;
use Rameshwari\Core\Services\Media\ReferenceSources;
use Rameshwari\Core\Support\Logger;

/**
 * Real WordPress deletes against every reference kind. The guard is registered
 * here by the test; nothing in the plugin wires it yet.
 */
final class ReferenceGuardTest extends \WP_UnitTestCase {

	/**
	 * Guard under test.
	 *
	 * @var ReferenceGuard
	 */
	private ReferenceGuard $guard;

	/**
	 * Captured log lines.
	 *
	 * @var array<int,string>
	 */
	private array $log = array();

	/**
	 * Registers the guard.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->log   = array();
		$this->guard = new ReferenceGuard(
			new ReferenceSources(),
			new Logger(
				function ( string $line ): void {
					$this->log[] = $line;
				}
			)
		);
		$this->guard->register();
	}

	/**
	 * Detaches the guard.
	 */
	public function tear_down(): void {
		$this->guard->unregister();
		remove_all_filters( 'query' );

		parent::tear_down();
	}

	/**
	 * Creates an attachment.
	 *
	 * @return int
	 */
	private function attachment(): int {
		$id = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/png',
				'post_title'     => 'Fixture',
				'post_status'    => 'inherit',
			)
		);

		// WordPress only accepts an attachment as a featured image when it has an image file name.
		update_attached_file( $id, 'rjfixture-' . $id . '.png' );

		return $id;
	}

	/**
	 * Whether a permanent delete is blocked and the attachment kept.
	 *
	 * @param int $id Attachment ID.
	 * @return bool
	 */
	private function blocked( int $id ): bool {
		return false === wp_delete_attachment( $id, true ) && null !== get_post( $id );
	}

	/**
	 * Creates an owner post of a registered type.
	 *
	 * @param string $type Post type.
	 * @return int
	 */
	private function owner( string $type ): int {
		return wp_insert_post(
			array(
				'post_type'   => $type,
				'post_title'  => 'Owner',
				'post_status' => 'publish',
			)
		);
	}

	/**
	 * An attachment nothing uses is deleted normally.
	 */
	public function test_unreferenced_attachment_can_be_deleted(): void {
		$id = $this->attachment();

		$this->assertFalse( $this->guard->find( $id )->is_referenced() );
		$this->assertInstanceOf( \WP_Post::class, wp_delete_attachment( $id, true ) );
		$this->assertNull( get_post( $id ) );
	}

	/**
	 * A featured image blocks the delete.
	 */
	public function test_featured_image_blocks_delete(): void {
		$id   = $this->attachment();
		$post = $this->owner( 'post' );

		$this->assertNotFalse( set_post_thumbnail( $post, $id ) );
		$this->assertSame( $id, (int) get_post_meta( $post, '_thumbnail_id', true ) );
		$this->assertSame( $id, (int) get_post_thumbnail_id( $post ) );

		$this->assertSame( array( '_thumbnail_id' ), array_column( $this->guard->find( $id )->references(), 'field' ) );
		$this->assertTrue( $this->blocked( $id ) );
		$this->assertSame( array( 'post' ), array_column( $this->guard->find( $id )->references(), 'owner_type' ) );
		$this->assertSame( $post, $this->guard->find( $id )->references()[0]['owner_id'] );
	}

	/**
	 * A single-ID post meta blocks the delete.
	 */
	public function test_single_post_meta_blocks_delete(): void {
		$id    = $this->attachment();
		$owner = $this->owner( 'rj_collection' );

		update_post_meta( $owner, '_rj_cover_id', $id );

		$this->assertTrue( $this->blocked( $id ) );
		$this->assertSame( '_rj_cover_id', $this->guard->find( $id )->references()[0]['field'] );
	}

	/**
	 * An ID inside a list meta blocks the delete.
	 */
	public function test_attachment_list_meta_blocks_delete(): void {
		$id    = $this->attachment();
		$owner = $this->owner( 'rj_showroom' );

		update_post_meta( $owner, '_rj_gallery', array( $this->attachment(), $id ) );

		$this->assertTrue( $this->blocked( $id ) );
		$this->assertSame( '_rj_gallery', $this->guard->find( $id )->references()[0]['field'] );
	}

	/**
	 * A term meta blocks the delete.
	 */
	public function test_term_meta_blocks_delete(): void {
		$id   = $this->attachment();
		$term = wp_insert_term( 'Guard Fixture', 'rj_category' );

		$this->assertIsArray( $term );

		update_term_meta( $term['term_id'], '_rj_image_id', $id );

		$this->assertTrue( $this->blocked( $id ) );
		$this->assertSame( 'term', $this->guard->find( $id )->references()[0]['owner_type'] );
	}

	/**
	 * A registered option field blocks the delete.
	 */
	public function test_option_field_blocks_delete(): void {
		$id = $this->attachment();

		$this->assertTrue( Options::save( 'rj_seo', array( 'open_graph' => array( 'default_image_id' => $id ) ) ) );
		$this->assertTrue( $this->blocked( $id ) );
		$this->assertSame( 'rj_seo.open_graph.default_image_id', $this->guard->find( $id )->references()[0]['field'] );
	}

	/**
	 * Every reference is reported, in a stable order.
	 */
	public function test_multiple_references_are_all_reported_deterministically(): void {
		$id    = $this->attachment();
		$post  = $this->owner( 'post' );
		$owner = $this->owner( 'rj_collection' );

		$this->assertNotFalse( set_post_thumbnail( $post, $id ) );
		update_post_meta( $owner, '_rj_cover_id', $id );

		$first  = $this->guard->find( $id )->references();
		$second = $this->guard->find( $id )->references();

		$this->assertCount( 2, $first );
		$this->assertEqualsCanonicalizing( array( '_thumbnail_id', '_rj_cover_id' ), array_column( $first, 'field' ) );
		$this->assertSame( $first, $second );
		$this->assertSame( $this->guard->find( $id )->count(), count( $first ) );
	}

	/**
	 * Other IDs, near-miss IDs and malformed lists are not references.
	 */
	public function test_non_references_and_malformed_data_do_not_block(): void {
		global $wpdb;

		$id    = $this->attachment();
		$owner = $this->owner( 'rj_showroom' );

		update_post_meta( $owner, '_rj_gallery', array( $this->attachment() ) );

		foreach ( array( 'a:1:{i:0;i:' . $id . '0;}', 'a:1:{i:0;i:' . $id . ';', 'not serialized ' . $id ) as $raw ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Writes malformed rows that the public API would refuse.
			$wpdb->insert(
				$wpdb->postmeta,
				array(
					'post_id'    => $owner,
					'meta_key'   => '_rj_gallery', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Deliberate malformed fixture row for ReferenceGuard testing.
					'meta_value' => $raw, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Deliberate malformed fixture row for ReferenceGuard testing.
				)
			);
		}

		$this->assertFalse( $this->guard->find( $id )->is_referenced() );
		$this->assertInstanceOf( \WP_Post::class, wp_delete_attachment( $id, true ) );
	}

	/**
	 * Meta that points at an owner that no longer exists is not a reference.
	 */
	public function test_orphan_owner_row_is_not_a_reference(): void {
		global $wpdb;

		$id = $this->attachment();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Writes an orphan row that the public API would refuse.
		$wpdb->insert(
			$wpdb->postmeta,
			array(
				'post_id'    => 99999999,
				'meta_key'   => '_rj_cover_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Deliberate orphan fixture row for ReferenceGuard testing.
				'meta_value' => (string) $id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Deliberate orphan fixture row for ReferenceGuard testing.
			)
		);

		$this->assertFalse( $this->guard->find( $id )->is_referenced() );
	}

	/**
	 * A scan error blocks the delete and is logged.
	 */
	public function test_scan_failure_blocks_delete_and_is_logged(): void {
		$id = $this->attachment();

		add_filter(
			'query',
			static fn ( string $sql ): string => str_contains( $sql, 'meta_key' ) ? 'SELECT * FROM rj_table_that_does_not_exist' : $sql
		);

		$this->assertTrue( $this->blocked( $id ) );
		$this->assertNotEmpty( $this->log );
	}

	/**
	 * The block leaves a report for the later admin screen.
	 */
	public function test_block_keeps_a_report(): void {
		$id    = $this->attachment();
		$owner = $this->owner( 'rj_collection' );

		update_post_meta( $owner, '_rj_cover_id', $id );
		wp_delete_attachment( $id, true );

		$this->assertTrue( $this->guard->last_report( $id )?->is_referenced() ?? false );
		$this->assertNull( $this->guard->last_report( 99999999 ) );
	}

	/**
	 * A scan changes no owner data.
	 */
	public function test_scanning_mutates_nothing(): void {
		$id    = $this->attachment();
		$owner = $this->owner( 'rj_collection' );

		update_post_meta( $owner, '_rj_cover_id', $id );

		$meta    = get_post_meta( $owner );
		$options = wp_load_alloptions( true );

		$this->guard->find( $id );

		$this->assertSame( $meta, get_post_meta( $owner ) );
		$this->assertSame( $options, wp_load_alloptions( true ) );
	}

	/**
	 * A non-positive ID is refused.
	 */
	public function test_invalid_attachment_id_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );

		$this->guard->find( 0 );
	}
}
