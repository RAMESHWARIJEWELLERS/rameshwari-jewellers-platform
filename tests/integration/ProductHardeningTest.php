<?php
/**
 * Public-read visibility, published-product mutation, marker transition,
 * visibility validation, Product Code race recheck and guard re-entrancy.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Integration;

use Rameshwari\Core\Data\Capabilities;
use Rameshwari\Core\Product\ProductData;
use Rameshwari\Core\Product\ProductGuard;
use Rameshwari\Core\Product\ProductModule;
use Rameshwari\Core\Product\ProductRepository;
use Rameshwari\Core\Product\ProductSystemScope;
use WP_Query;
use WP_UnitTestCase;

/**
 * Stage 6 correction pass: H-1, M-1 to M-4, L-1 and L-4.
 */
final class ProductHardeningTest extends WP_UnitTestCase {

	private const MARKER = '_rj_first_published_at';

	/**
	 * Restores the plugin registry for each test.
	 */
	public function set_up(): void {
		parent::set_up();
		do_action( 'init' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Exercises the plugin's normal init registration.
		Capabilities::apply();
		rest_get_server();
		wp_set_current_user( 0 );
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
	 * A complete product, saved through the service.
	 *
	 * @param string $code       Product Code.
	 * @param string $status     draft or publish.
	 * @param string $visibility public, hidden or archived.
	 * @return int
	 */
	private function make( string $code, string $status = 'publish', string $visibility = 'public' ): int {
		$root = $this->term( 'rj_category' );
		$id   = ProductModule::service()->save(
			ProductData::from_array(
				array(
					'code'       => $code,
					'name_en'    => 'Ring ' . $code,
					'name_hi'    => 'अंगूठी',
					'metal'      => array( $this->term( 'rj_metal' ) ),
					'purity'     => array( $this->term( 'rj_purity' ) ),
					'categories' => array( $this->term( 'rj_category', $root ) ),
					'visibility' => $visibility,
					'status'     => $status,
				)
			)
		);
		$this->assertIsInt( $id );

		return $id;
	}

	/**
	 * Product IDs in a REST collection response.
	 *
	 * @param \WP_REST_Response $response Response.
	 * @return array<int, int>
	 */
	private function ids( \WP_REST_Response $response ): array {
		return array_map( 'intval', array_column( (array) $response->get_data(), 'id' ) );
	}

	/**
	 * The public product collection.
	 *
	 * @return \WP_REST_Response
	 */
	private function collection(): \WP_REST_Response {
		return rest_do_request( new \WP_REST_Request( 'GET', '/wp/v2/products' ) );
	}

	/**
	 * H-1: the public REST collection holds only published, public products.
	 */
	public function test_public_rest_collection_excludes_non_public_products(): void {
		$public   = $this->make( 'H1-PUB' );
		$hidden   = $this->make( 'H1-HID', 'publish', 'hidden' );
		$archived = $this->make( 'H1-ARC', 'publish', 'archived' );
		$draft    = $this->make( 'H1-DRF', 'draft' );
		$trashed  = $this->make( 'H1-TRS' );
		( new ProductRepository() )->trash( $trashed );

		$ids = $this->ids( $this->collection() );

		$this->assertContains( $public, $ids );

		foreach ( array( $hidden, $archived, $draft, $trashed ) as $excluded ) {
			$this->assertNotContains( $excluded, $ids );
		}
	}

	/**
	 * H-1: a single non-public product is a 404 for the public, readable by an editor.
	 */
	public function test_single_product_read_is_404_for_public_and_open_to_admin(): void {
		$public = $this->make( 'H1S-PUB' );
		$hidden = $this->make( 'H1S-HID', 'publish', 'hidden' );

		$this->assertSame( 200, rest_do_request( new \WP_REST_Request( 'GET', "/wp/v2/products/{$public}" ) )->get_status() );
		$this->assertSame( 404, rest_do_request( new \WP_REST_Request( 'GET', "/wp/v2/products/{$hidden}" ) )->get_status() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertSame( 200, rest_do_request( new \WP_REST_Request( 'GET', "/wp/v2/products/{$hidden}" ) )->get_status() );
		$this->assertContains( $hidden, $this->ids( $this->collection() ) );
	}

	/**
	 * H-1: native queries for the public exclude hidden and archived products,
	 * while internal lookups (Product Code identity) still see them.
	 */
	public function test_native_query_excludes_non_public_but_code_lookup_does_not(): void {
		$public   = $this->make( 'H1Q-PUB' );
		$hidden   = $this->make( 'H1Q-HID', 'publish', 'hidden' );
		$archived = $this->make( 'H1Q-ARC', 'publish', 'archived' );

		$query = new WP_Query(
			array(
				'post_type'   => 'rj_product',
				'post_status' => 'publish',
				'fields'      => 'ids',
			)
		);
		$ids   = array_map( 'intval', $query->posts );

		$this->assertContains( $public, $ids );
		$this->assertNotContains( $hidden, $ids );
		$this->assertNotContains( $archived, $ids );
		$this->assertTrue( ( new ProductRepository() )->code_exists( 'H1Q-HID', null ) );
	}

	/**
	 * M-4: a published product cannot lose a required field through the service.
	 */
	public function test_published_product_cannot_lose_required_fields_through_service(): void {
		$id      = $this->make( 'M4-SVC' );
		$service = ProductModule::service();

		foreach ( array(
			array( 'name_en' => '' ),
			array( 'name_hi' => '' ),
			array( 'metal' => array() ),
			array( 'purity' => array() ),
			array( 'categories' => array() ),
		) as $change ) {
			$result = $service->save( ProductData::from_array( $change ), $id );

			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertSame( 'rj_invalid', $result->get_error_code() );
		}

		$changed = $service->save( ProductData::from_array( array( 'code' => 'M4-OTHER' ) ), $id );

		$this->assertInstanceOf( \WP_Error::class, $changed );
		$this->assertSame( 'rj_conflict', $changed->get_error_code() );
	}

	/**
	 * M-4: direct meta and term writes cannot invalidate a published product.
	 */
	public function test_direct_writes_cannot_invalidate_a_published_product(): void {
		$id = $this->make( 'M4-DIR' );

		$this->assertFalse( update_post_meta( $id, '_rj_name_en', '' ) );
		$this->assertFalse( delete_post_meta( $id, '_rj_name_hi' ) );
		$this->assertSame( 'अंगूठी', get_post_meta( $id, '_rj_name_hi', true ) );

		wp_set_object_terms( $id, array(), 'rj_metal' );
		wp_set_object_terms( $id, array(), 'rj_purity' );
		wp_set_object_terms( $id, array(), 'rj_category' );

		$this->assertCount( 1, wp_get_object_terms( $id, 'rj_metal' ) );
		$this->assertCount( 1, wp_get_object_terms( $id, 'rj_purity' ) );
		$this->assertCount( 1, wp_get_object_terms( $id, 'rj_category' ) );
		$this->assertSame( 'publish', get_post_status( $id ) );
	}

	/**
	 * M-4: a REST update that does not repeat the status still re-checks publication.
	 */
	public function test_rest_update_of_published_product_rechecks_publication(): void {
		$id = $this->make( 'M4-REST' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$request = new \WP_REST_Request( 'POST', "/wp/v2/products/{$id}" );
		$request->set_param( 'metals', array() );
		$response = rest_do_request( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertCount( 1, wp_get_object_terms( $id, 'rj_metal' ) );
	}

	/**
	 * M-3: the marker is recorded by the transition itself, once, and survives later moves.
	 */
	public function test_marker_is_recorded_on_transition_and_survives_later_moves(): void {
		$id = $this->make( 'M3-TR', 'draft' );

		$this->assertSame( '', (string) get_post_meta( $id, self::MARKER, true ) );

		wp_transition_post_status( 'publish', 'draft', get_post( $id ) ); // No save_post fires here.
		$first = (string) get_post_meta( $id, self::MARKER, true );

		$this->assertNotSame( '', $first );

		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'draft',
			)
		);
		wp_trash_post( $id );
		wp_untrash_post( $id );
		wp_transition_post_status( 'publish', 'draft', get_post( $id ) );

		$this->assertSame( $first, (string) get_post_meta( $id, self::MARKER, true ) );
	}

	/**
	 * M-3: a rejected classic publish leaves no marker; the later valid publish
	 * sets it exactly once and a repeated publish never changes it.
	 */
	public function test_invalid_classic_publish_leaves_no_marker_then_valid_publish_sets_it_once(): void {
		$id = (int) wp_insert_post(
			array(
				'post_type'   => 'rj_product',
				'post_status' => 'publish',
				'post_title'  => 'Bare',
			)
		);

		$this->assertSame( 'draft', get_post_status( $id ) );
		$this->assertSame( '', (string) get_post_meta( $id, self::MARKER, true ) );
		$this->assertFalse( ProductModule::service()->has_ever_been_published( $id ) );

		$root   = $this->term( 'rj_category' );
		$result = ProductModule::service()->save(
			ProductData::from_array(
				array(
					'code'       => 'M3-FIRST',
					'name_en'    => 'Ring M3',
					'name_hi'    => 'अंगूठी',
					'metal'      => array( $this->term( 'rj_metal' ) ),
					'purity'     => array( $this->term( 'rj_purity' ) ),
					'categories' => array( $this->term( 'rj_category', $root ) ),
					'status'     => 'publish',
				)
			),
			$id
		);

		$this->assertSame( $id, $result );
		$first = (string) get_post_meta( $id, self::MARKER, true );

		$this->assertNotSame( '', $first );

		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'draft',
			)
		);
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'publish',
			)
		);

		$this->assertSame( $first, (string) get_post_meta( $id, self::MARKER, true ) );
	}

	/**
	 * M-2: invalid visibility is rejected, never coerced to public.
	 */
	public function test_invalid_visibility_is_rejected(): void {
		$id = $this->make( 'M2-VIS', 'draft' );

		foreach ( ProductData::VISIBILITIES as $valid ) {
			$this->assertTrue( ProductData::from_array( array( 'visibility' => $valid ) )->has( 'visibility' ) );
		}

		$this->assertFalse( update_post_meta( $id, '_rj_visibility', 'secret' ) );
		$this->assertNotSame( 'secret', (string) get_post_meta( $id, '_rj_visibility', true ) );

		$this->expectException( \InvalidArgumentException::class );
		ProductData::from_array( array( 'visibility' => 'secret' ) );
	}

	/**
	 * M-1: when two writes both passed the pre-write check, the lowest ID keeps
	 * the code. This interleaving is simulated deterministically; it proves the
	 * recheck resolves a duplicate, not that true parallel requests are safe.
	 */
	public function test_code_race_is_resolved_by_lowest_id(): void {
		$winner = $this->make( 'M1-WIN' );
		$loser  = $this->make( 'M1-LOSE', 'draft' );
		$code   = (string) get_post_meta( $winner, '_rj_code', true );

		ProductSystemScope::run( static fn() => update_post_meta( $loser, '_rj_code', $code ) );

		$service = ProductModule::service();

		$this->assertFalse( $service->resolve_code_race( $winner ) );
		$this->assertTrue( $service->resolve_code_race( $loser ) );
		$this->assertSame( $code, (string) get_post_meta( $winner, '_rj_code', true ) );
		$this->assertSame( '', (string) get_post_meta( $loser, '_rj_code', true ) );
		$this->assertFalse( $service->resolve_code_race( $loser ) );
	}

	/**
	 * L-1: the re-entrancy flag clears even when the callback throws.
	 */
	public function test_busy_flag_clears_after_an_exception(): void {
		$method = new \ReflectionMethod( ProductGuard::class, 'with_busy' );

		try {
			$method->invoke(
				null,
				static function (): void {
					throw new \RuntimeException( 'boom' );
				}
			);
			$this->fail( 'The exception should propagate.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'boom', $e->getMessage() );
		}

		$property = new \ReflectionProperty( ProductGuard::class, 'busy' );

		$this->assertFalse( $property->getValue() );
	}

	/**
	 * L-4: a REST client cannot write the marker, and reads never show it.
	 * WordPress may reject the unregistered REST field before the guard runs;
	 * the assertion is that the write fails and nothing is stored.
	 */
	public function test_rest_cannot_write_or_read_the_marker(): void {
		$id = $this->make( 'L4-REST' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$before = (string) get_post_meta( $id, self::MARKER, true );

		$request = new \WP_REST_Request( 'POST', "/wp/v2/products/{$id}" );
		$request->set_param( 'meta', array( self::MARKER => '2000-01-01 00:00:00' ) );
		$write = rest_do_request( $request );

		$this->assertGreaterThanOrEqual( 400, $write->get_status() );
		$this->assertSame( $before, (string) get_post_meta( $id, self::MARKER, true ) );

		$read = (array) rest_do_request( new \WP_REST_Request( 'GET', "/wp/v2/products/{$id}" ) )->get_data();

		$this->assertArrayNotHasKey( self::MARKER, (array) ( $read['meta'] ?? array() ) );
	}
}
