<?php
/**
 * The write-path guard: marker protection, direct writes, REST and publication.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Integration;

use Rameshwari\Core\Data\Capabilities;
use Rameshwari\Core\Data\Meta;
use Rameshwari\Core\Data\Schema;
use Rameshwari\Core\Product\ProductCode;
use Rameshwari\Core\Product\ProductData;
use Rameshwari\Core\Product\ProductGuard;
use Rameshwari\Core\Product\ProductModule;
use Rameshwari\Core\Product\ProductRepository;
use WP_UnitTestCase;

/**
 * The write-path guard: marker protection, direct writes, REST and publication.
 */
final class ProductGuardTest extends WP_UnitTestCase {

	private const MARKER = '_rj_first_published_at';

	/**
	 * Restores the plugin registry for each test, as MetaTest does.
	 */
	public function set_up(): void {
		parent::set_up();
		do_action( 'init' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Exercises the plugin's normal init registration.
		Capabilities::apply();
	}

	/**
	 * A fresh term.
	 *
	 * @param string $taxonomy    Taxonomy.
	 * @param int    $parent_term Parent term.
	 * @return int
	 */
	private function term( string $taxonomy, int $parent_term = 0 ): int {
		return (int) self::factory()->term->create(
			array(
				'taxonomy' => $taxonomy,
				'name'     => 'T' . wp_generate_password( 8, false ),
				'parent'   => $parent_term,
			)
		);
	}

	/**
	 * A complete field set, optionally overridden.
	 *
	 * @param string               $code      Product Code.
	 * @param array<string, mixed> $overrides Overrides.
	 * @return array<string, mixed>
	 */
	private function fields( string $code, array $overrides = array() ): array {
		$root = $this->term( 'rj_category' );

		return array_merge(
			array(
				'code'       => $code,
				'name_en'    => 'Ring ' . $code,
				'name_hi'    => 'अंगूठी',
				'metal'      => array( $this->term( 'rj_metal' ) ),
				'purity'     => array( $this->term( 'rj_purity' ) ),
				'categories' => array( $this->term( 'rj_category', $root ) ),
			),
			$overrides
		);
	}

	/**
	 * Saves through the service.
	 *
	 * @param array<string, mixed> $fields Fields.
	 * @param int|null             $id     Product or null.
	 * @return int|\WP_Error
	 */
	private function save( array $fields, ?int $id = null ): int|\WP_Error {
		return ProductModule::service()->save( ProductData::from_array( $fields ), $id );
	}

	/**
	 * A saved product ID.
	 *
	 * @param string $code   Code.
	 * @param string $status draft or publish.
	 * @return int
	 */
	private function make( string $code, string $status = 'draft' ): int {
		$id = $this->save( $this->fields( $code, array( 'status' => $status ) ) );
		$this->assertIsInt( $id );

		return $id;
	}

	/**
	 * Marker is private and unwritable.
	 */
	public function test_marker_is_private_and_unwritable(): void {
		$id       = $this->make( 'GM-1', 'publish' );
		$original = (string) get_post_meta( $id, self::MARKER, true );

		$this->assertFalse( update_post_meta( $id, self::MARKER, '2001-01-01 00:00:00' ) );
		$this->assertFalse( add_post_meta( $id, self::MARKER, '2001-01-01 00:00:00' ) );
		$this->assertFalse( delete_post_meta( $id, self::MARKER ) );
		$this->assertFalse( delete_post_meta_by_key( self::MARKER ) );
		$this->assertSame( $original, (string) get_post_meta( $id, self::MARKER, true ) );

		$registered = get_registered_meta_keys( 'post', 'rj_product' )[ self::MARKER ];
		$this->assertFalse( $registered['show_in_rest'] );
		$this->assertFalse( $registered['auth_callback']( true, self::MARKER, $id ) );
	}

	/**
	 * Marker absent from rest and param refused.
	 */
	public function test_marker_absent_from_rest_and_param_refused(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		Capabilities::apply();
		$id   = $this->make( 'GR-1', 'publish' );
		$data = rest_do_request( new \WP_REST_Request( 'GET', '/wp/v2/products/' . $id ) )->get_data();
		$this->assertArrayNotHasKey( self::MARKER, $data['meta'] );

		$request = new \WP_REST_Request( 'POST', '/wp/v2/products/' . $id );
		$request->set_param( 'meta', array( self::MARKER => '2001-01-01 00:00:00' ) );
		$error = ProductGuard::guard_rest( null, $request );
		$this->assertSame( 403, $error->get_error_data()['status'] );
	}

	/**
	 * Direct duplicate code write is blocked.
	 */
	public function test_direct_duplicate_code_write_is_blocked(): void {
		$a = $this->make( 'GD-1' );
		$b = $this->make( 'GD-2' );
		$this->assertFalse( update_post_meta( $b, '_rj_code', 'GD-1' ) );
		$this->assertSame( 'GD-2', get_post_meta( $b, '_rj_code', true ) );
		$this->assertNotSame( $a, $b );
	}

	/**
	 * Direct term writes are reverted.
	 */
	public function test_direct_term_writes_are_reverted(): void {
		$id    = $this->make( 'GT-1' );
		$repo  = new ProductRepository();
		$metal = $repo->assigned_categories( $id, 'rj_metal' );
		wp_set_object_terms( $id, array( $this->term( 'rj_metal' ), $this->term( 'rj_metal' ) ), 'rj_metal' );
		$this->assertSame( $metal, $repo->assigned_categories( $id, 'rj_metal' ) );

		$before = $repo->assigned_categories( $id );
		wp_set_object_terms( $id, array( $this->term( 'rj_category' ) ), 'rj_category' );
		$this->assertSame( $before, $repo->assigned_categories( $id ) );
	}

	/**
	 * Classic publish without requirements returns to draft.
	 */
	public function test_classic_publish_without_requirements_returns_to_draft(): void {
		$id = wp_insert_post(
			array(
				'post_type'   => 'rj_product',
				'post_status' => 'publish',
				'post_title'  => 'Bare',
			)
		);
		$this->assertSame( 'draft', get_post_status( $id ) );
		$this->assertSame( '', get_post_meta( $id, self::MARKER, true ) );
	}

	/**
	 * Wp update post and scheduled publication set the marker.
	 */
	public function test_wp_update_post_and_scheduled_publication_set_the_marker(): void {
		$a = $this->make( 'GP-1' );
		wp_update_post(
			array(
				'ID'          => $a,
				'post_status' => 'publish',
			)
		);
		$this->assertNotSame( '', get_post_meta( $a, self::MARKER, true ) );

		$b = $this->make( 'GP-2' );
		wp_update_post(
			array(
				'ID'            => $b,
				'post_status'   => 'future',
				'post_date'     => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
				'edit_date'     => true,
			)
		);
		$this->assertSame( '', get_post_meta( $b, self::MARKER, true ) );
		wp_publish_post( $b );
		$this->assertNotSame( '', get_post_meta( $b, self::MARKER, true ) );
	}

	/**
	 * Rest duplicate code is 409 rj conflict.
	 */
	public function test_rest_duplicate_code_is_409_rj_conflict(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		Capabilities::apply();
		$this->make( 'GC-1' );

		$request = new \WP_REST_Request( 'POST', '/wp/v2/products' );
		$request->set_param( 'title', 'x' );
		$request->set_param( 'status', 'draft' );
		$request->set_param( 'meta', array( '_rj_code' => 'gc-1' ) );
		$response = rest_do_request( $request );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'rj_conflict', $response->get_data()['code'] );
	}

	/**
	 * Hard delete removes product and marker.
	 */
	public function test_hard_delete_removes_product_and_marker(): void {
		$id = $this->make( 'GH-1', 'publish' );
		wp_delete_post( $id, true );
		$this->assertNull( get_post( $id ) );
		$this->assertSame( '', get_post_meta( $id, self::MARKER, true ) );
		$this->assertFalse( ( new ProductRepository() )->code_exists( 'GH-1' ) );
	}

	/**
	 * Product code has no raw database writes.
	 */
	public function test_product_code_has_no_raw_database_writes(): void {
		$dir = dirname( ( new \ReflectionClass( ProductRepository::class ) )->getFileName() );

		global $wp_filesystem;
		require_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();

		foreach ( (array) glob( $dir . '/*.php' ) as $file ) {
			$this->assertStringNotContainsString( '$wpdb', (string) $wp_filesystem->get_contents( $file ), basename( $file ) );
		}
	}

	/**
	 * Registry contracts are unchanged.
	 */
	public function test_registry_contracts_are_unchanged(): void {
		$this->assertCount( 15, array_filter( array_keys( get_registered_meta_keys( 'post', 'rj_product' ) ), static fn( string $key ): bool => str_starts_with( $key, '_rj_' ) ) );
		$this->assertTrue( taxonomy_exists( 'rj_category' ) );
		$this->assertTrue( post_type_exists( 'rj_product' ) );
		$this->assertSame( 'rj-categories', get_taxonomy( 'rj_category' )->rest_base );
	}
}
