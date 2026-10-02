<?php
/**
 * Permanent Stage 6 regression coverage: F-1 (public-read visibility on
 * category archives, search and broad queries) and F-2 (direct meta writes of
 * an invalid visibility).
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Integration;

use Rameshwari\Core\Data\Capabilities;
use Rameshwari\Core\Product\ProductData;
use Rameshwari\Core\Product\ProductModule;
use WP_Query;
use WP_UnitTestCase;

/**
 * Each test runs the real WordPress query or REST path and inspects the
 * returned results. None of them calls ProductService::is_public().
 */
final class ProductPublicReadRegressionTest extends WP_UnitTestCase {

	/**
	 * Restores the plugin registry and clean permalinks for each test.
	 */
	public function set_up(): void {
		parent::set_up();
		do_action( 'init' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Exercises the plugin's normal init registration.
		Capabilities::apply();
		$this->set_permalink_structure( '/%postname%/' );
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
	 * A complete product in a given category, saved through the service.
	 *
	 * @param int    $category   Editorial category term.
	 * @param string $code       Product Code.
	 * @param string $word       Unique search word placed in the English name.
	 * @param string $visibility public, hidden or archived.
	 * @return int
	 */
	private function make( int $category, string $code, string $word, string $visibility = 'public' ): int {
		$id = ProductModule::service()->save(
			ProductData::from_array(
				array(
					'code'       => $code,
					'name_en'    => "Ring {$word} {$code}",
					'name_hi'    => 'अंगूठी',
					'metal'      => array( $this->term( 'rj_metal' ) ),
					'purity'     => array( $this->term( 'rj_purity' ) ),
					'categories' => array( $category ),
					'visibility' => $visibility,
					'status'     => 'publish',
				)
			)
		);
		$this->assertIsInt( $id );

		return $id;
	}

	/**
	 * A category with one public, one hidden and one archived product.
	 *
	 * @param string $word Unique search word.
	 * @return array{category:int,public:int,hidden:int,archived:int}
	 */
	private function fixture( string $word ): array {
		$category = $this->term( 'rj_category', $this->term( 'rj_category' ) );

		return array(
			'category' => $category,
			'public'   => $this->make( $category, "{$word}-PUB", $word ),
			'hidden'   => $this->make( $category, "{$word}-HID", $word, 'hidden' ),
			'archived' => $this->make( $category, "{$word}-ARC", $word, 'archived' ),
		);
	}

	/**
	 * Post IDs found by the main query after go_to().
	 *
	 * @return array<int, int>
	 */
	private function main_query_ids(): array {
		$main = $GLOBALS['wp_query'];
		$this->assertInstanceOf( WP_Query::class, $main );

		return array_map(
			static fn( $post ): int => (int) ( $post instanceof \WP_Post ? $post->ID : $post ),
			$main->posts
		);
	}

	/**
	 * Loads the real category archive URL as the main query.
	 *
	 * @param int $category Category term.
	 * @return array<int, int>
	 */
	private function archive_ids( int $category ): array {
		$link = get_term_link( $category, 'rj_category' );
		$this->assertIsString( $link );
		$this->go_to( $link );

		return $this->main_query_ids();
	}

	/**
	 * F-1: the public category archive excludes hidden and archived products.
	 */
	public function test_public_category_archive_excludes_hidden_and_archived(): void {
		$set = $this->fixture( 'ARCH' );
		$ids = $this->archive_ids( $set['category'] );

		$this->assertContains( $set['public'], $ids, 'The archive must find the public product, or the probe proves nothing.' );
		$this->assertNotContains( $set['hidden'], $ids, 'Hidden product leaked into the category archive.' );
		$this->assertNotContains( $set['archived'], $ids, 'Archived product leaked into the category archive.' );
	}

	/**
	 * F-1: an editor still sees every product in the same archive.
	 */
	public function test_admin_category_archive_still_shows_all_products(): void {
		$set = $this->fixture( 'ADM' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$ids = $this->archive_ids( $set['category'] );

		$this->assertContains( $set['public'], $ids );
		$this->assertContains( $set['hidden'], $ids );
		$this->assertContains( $set['archived'], $ids );
	}

	/**
	 * F-1: the public site search excludes hidden and archived products.
	 */
	public function test_public_search_excludes_hidden_and_archived(): void {
		$set = $this->fixture( 'SRCHWORD' );
		$this->go_to( home_url( '/?s=SRCHWORD' ) );
		$ids  = $this->main_query_ids();
		$type = get_post_type_object( 'rj_product' );

		$this->assertInstanceOf( \WP_Post_Type::class, $type );
		$this->assertNotContains( $set['hidden'], $ids, 'Hidden product leaked into site search.' );
		$this->assertNotContains( $set['archived'], $ids, 'Archived product leaked into site search.' );

		if ( ! $type->exclude_from_search ) {
			$this->assertContains( $set['public'], $ids );
		}
	}

	/**
	 * F-1: broad and mixed post_type queries exclude non-public products.
	 */
	public function test_any_and_mixed_post_type_queries_exclude_non_public(): void {
		$set = $this->fixture( 'BROAD' );

		foreach ( array( 'any', array( 'post', 'rj_product' ) ) as $post_type ) {
			$query = new WP_Query(
				array(
					'post_type'   => $post_type,
					'post_status' => 'publish',
					'fields'      => 'ids',
					'tax_query'   => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Probe scoping the query to one fixture category.
						array(
							'taxonomy' => 'rj_category',
							'terms'    => array( $set['category'] ),
						),
					),
				)
			);
			$ids   = array_map( 'intval', $query->posts );
			$label = is_array( $post_type ) ? 'mixed' : (string) $post_type;

			$this->assertContains( $set['public'], $ids, "{$label}: public product missing." );
			$this->assertNotContains( $set['hidden'], $ids, "{$label}: hidden product leaked." );
			$this->assertNotContains( $set['archived'], $ids, "{$label}: archived product leaked." );
		}
	}

	/**
	 * F-1: the native REST search with subtype rj_product excludes non-public products.
	 */
	public function test_rest_search_excludes_hidden_and_archived(): void {
		$set     = $this->fixture( 'RESTSRCH' );
		$request = new \WP_REST_Request( 'GET', '/wp/v2/search' );
		$request->set_query_params(
			array(
				'search'  => 'RESTSRCH',
				'type'    => 'post',
				'subtype' => 'rj_product',
			)
		);
		$response = rest_do_request( $request );
		$ids      = 200 === $response->get_status() ? array_map( 'intval', array_column( (array) $response->get_data(), 'id' ) ) : array();

		$this->assertNotContains( $set['hidden'], $ids, 'Hidden product leaked into REST search.' );
		$this->assertNotContains( $set['archived'], $ids, 'Archived product leaked into REST search.' );

		if ( 200 === $response->get_status() ) {
			$this->assertContains( $set['public'], $ids );
		}
	}

	/**
	 * F-2: a direct update_post_meta with an invalid visibility is refused,
	 * never stored as public; valid values still work.
	 */
	public function test_direct_meta_write_of_invalid_visibility_is_refused(): void {
		$category = $this->term( 'rj_category', $this->term( 'rj_category' ) );
		$id       = $this->make( $category, 'F2-1', 'F2', 'hidden' );

		$this->assertSame( 'hidden', get_post_meta( $id, '_rj_visibility', true ) );

		$result = update_post_meta( $id, '_rj_visibility', 'hiden' );
		$stored = get_post_meta( $id, '_rj_visibility', true );

		$this->assertNotSame( 'public', $stored, 'Invalid visibility was coerced to public.' );
		$this->assertNotSame( 'hiden', $stored );
		$this->assertSame( 'hidden', $stored, 'The previous valid value must remain.' );
		$this->assertFalse( $result );

		update_post_meta( $id, '_rj_visibility', 'archived' );
		$this->assertSame( 'archived', get_post_meta( $id, '_rj_visibility', true ) );

		update_post_meta( $id, '_rj_visibility', 'public' );
		$this->assertSame( 'public', get_post_meta( $id, '_rj_visibility', true ) );
	}
}
