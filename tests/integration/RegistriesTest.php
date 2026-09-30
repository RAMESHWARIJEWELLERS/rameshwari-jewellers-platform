<?php
/**
 * Post type and taxonomy registry tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Integration;

use Rameshwari\Core\Data\Meta;
use Rameshwari\Core\Data\Options;
use Rameshwari\Core\Data\PostTypes;
use Rameshwari\Core\Data\Rewrites;
use Rameshwari\Core\Data\Taxonomies;
use Rameshwari\Core\ModuleRegistry;
use WP_UnitTestCase;

/**
 * Development Blueprint §6 and §7 against what WordPress holds.
 */
final class RegistriesTest extends WP_UnitTestCase {

	private const POST_TYPES = array( 'rj_product', 'rj_reel', 'rj_collection', 'rj_showroom', 'rj_testimonial', 'rj_page_section' );

	private const TAXONOMIES = array( 'rj_category', 'rj_metal', 'rj_purity', 'rj_occasion', 'rj_audience', 'rj_collection_tax', 'rj_tag' );

	/**
	 * Exactly six rj_ post types exist.
	 */
	public function test_exactly_six_post_types(): void {
		$this->assertSame( self::POST_TYPES, array_keys( PostTypes::definitions() ) );

		foreach ( self::POST_TYPES as $post_type ) {
			$this->assertTrue( post_type_exists( $post_type ), $post_type );
		}
	}

	/**
	 * Exactly seven rj_ taxonomies exist, on the approved object types.
	 */
	public function test_exactly_seven_taxonomies(): void {
		$expected = array(
			'rj_category'       => array( 'rj_product', 'rj_reel' ),
			'rj_metal'          => array( 'rj_product' ),
			'rj_purity'         => array( 'rj_product' ),
			'rj_occasion'       => array( 'rj_product', 'rj_reel', 'rj_collection' ),
			'rj_audience'       => array( 'rj_product' ),
			'rj_collection_tax' => array( 'rj_product', 'rj_reel' ),
			'rj_tag'            => array( 'rj_product', 'rj_reel' ),
		);

		$this->assertSame( self::TAXONOMIES, array_keys( Taxonomies::definitions() ) );

		foreach ( $expected as $taxonomy => $objects ) {
			$this->assertEqualsCanonicalizing( $objects, get_taxonomy( $taxonomy )->object_type, $taxonomy );
		}
	}

	/**
	 * The approved post type arguments of §6.1 and §6.2.
	 */
	public function test_post_type_arguments(): void {
		$spec = array(
			'rj_product'      => array( true, 'jewellery', 'product', 'products', 'rj_product' ),
			'rj_reel'         => array( true, 'reels', 'reel', 'reels', 'rj_reel' ),
			'rj_collection'   => array( true, 'collections', 'collection', 'collections', 'rj_collection' ),
			'rj_showroom'     => array( true, 'showrooms', 'showroom', 'showrooms', 'rj_showroom' ),
			'rj_testimonial'  => array( false, false, null, 'testimonials', 'rj_testimonial' ),
			'rj_page_section' => array( false, false, null, 'page-sections', 'rj_section' ),
		);

		foreach ( $spec as $name => list( $public, $archive, $slug, $rest_base, $cap_type ) ) {
			$type = get_post_type_object( $name );

			$this->assertSame( $public, $type->public, $name );
			$this->assertSame( $archive, $type->has_archive, $name );
			$this->assertSame( $slug, is_array( $type->rewrite ) ? $type->rewrite['slug'] : null, $name );
			$this->assertTrue( $type->show_in_rest, $name );
			$this->assertSame( $rest_base, $type->rest_base, $name );
			$this->assertSame( $cap_type, $type->capability_type, $name );
			$this->assertTrue( $type->map_meta_cap, $name );
			$this->assertFalse( $type->hierarchical, $name );
			$this->assertTrue( $type->show_ui, $name );
		}

		$this->assertTrue( post_type_supports( 'rj_product', 'page-attributes' ) );
		$this->assertFalse( post_type_supports( 'rj_reel', 'editor' ) );
		$this->assertFalse( get_post_type_object( 'rj_page_section' )->show_in_nav_menus );
	}

	/**
	 * Primitive capabilities map onto the existing capability map.
	 */
	public function test_capabilities_use_existing_map(): void {
		$expected = array(
			'rj_product'      => array( 'rj_manage_catalogue', 'manage_options' ),
			'rj_reel'         => array( 'rj_manage_reels', 'rj_manage_reels' ),
			'rj_collection'   => array( 'rj_manage_catalogue', 'rj_manage_catalogue' ),
			'rj_showroom'     => array( 'rj_manage_showrooms', 'manage_options' ),
			'rj_testimonial'  => array( 'rj_manage_catalogue', 'rj_manage_catalogue' ),
			'rj_page_section' => array( 'rj_manage_settings', 'rj_manage_settings' ),
		);

		foreach ( $expected as $name => list( $manage, $delete ) ) {
			$cap = get_post_type_object( $name )->cap;

			$this->assertSame( $manage, $cap->edit_posts, $name );
			$this->assertSame( $manage, $cap->publish_posts, $name );
			$this->assertSame( $manage, $cap->create_posts, $name );
			$this->assertSame( $delete, $cap->delete_posts, $name );
		}
	}

	/**
	 * Rj_category: hierarchical, no core meta box, no quick edit, approved rewrite and capabilities.
	 */
	public function test_category_arguments(): void {
		$tax = get_taxonomy( 'rj_category' );

		$this->assertTrue( $tax->hierarchical );
		$this->assertTrue( $tax->public );
		$this->assertTrue( $tax->show_in_rest );
		$this->assertSame( 'rj-categories', $tax->rest_base );
		$this->assertTrue( $tax->show_admin_column );
		$this->assertFalse( $tax->show_in_quick_edit );
		$this->assertFalse( $tax->meta_box_cb );
		$this->assertSame( 'c', $tax->rewrite['slug'] );
		$this->assertTrue( $tax->rewrite['hierarchical'] );
		$this->assertFalse( $tax->rewrite['with_front'] );
		$this->assertSame( 'rj_manage_categories', $tax->cap->manage_terms );
		$this->assertSame( 'rj_manage_categories', $tax->cap->delete_terms );
		$this->assertSame( 'rj_manage_catalogue', $tax->cap->assign_terms );
	}

	/**
	 * Flat taxonomies: slugs, REST bases, and a hidden collection link.
	 */
	public function test_flat_taxonomy_arguments(): void {
		$spec = array(
			'rj_metal'    => array( 'metal', 'metals' ),
			'rj_purity'   => array( 'purity', 'purity' ),
			'rj_occasion' => array( 'occasion', 'occasions' ),
			'rj_audience' => array( 'for', 'audience' ),
			'rj_tag'      => array( 'tag-j', 'jewellery-tags' ),
		);

		foreach ( $spec as $name => list( $slug, $rest_base ) ) {
			$tax = get_taxonomy( $name );

			$this->assertFalse( $tax->hierarchical, $name );
			$this->assertSame( $slug, $tax->rewrite['slug'], $name );
			$this->assertSame( $rest_base, $tax->rest_base, $name );
			$this->assertSame( 'rj_manage_catalogue', $tax->cap->manage_terms, $name );
		}

		$links = get_taxonomy( 'rj_collection_tax' );

		$this->assertFalse( $links->public );
		$this->assertFalse( $links->show_ui );
		$this->assertFalse( $links->rewrite );
		$this->assertSame( 'collection-links', $links->rest_base );
	}

	/**
	 * Registration runs on init priority 5, and running it again changes nothing.
	 */
	public function test_registered_on_init_priority_five_and_idempotent(): void {
		$this->assertSame( 5, has_action( 'init', array( PostTypes::class, 'register_all' ) ) );
		$this->assertSame( 5, has_action( 'init', array( Taxonomies::class, 'register_all' ) ) );
		$this->assertSame( 5, has_action( 'init', array( Meta::class, 'register_all' ) ) );

		$before = get_post_type_object( 'rj_product' );
		PostTypes::register_all();
		Taxonomies::register_all();

		$this->assertSame( $before, get_post_type_object( 'rj_product' ) );
	}

	/**
	 * The registry orders the five modules by their dependencies.
	 */
	public function test_module_dependency_order(): void {
		$registry = new ModuleRegistry();

		foreach ( array( Rewrites::class, Meta::class, Taxonomies::class, Options::class, PostTypes::class ) as $module ) {
			$registry->add( $module );
		}

		$order = $registry->sorted();

		$this->assertCount( 5, $order );
		$this->assertLessThan( array_search( Taxonomies::class, $order, true ), array_search( PostTypes::class, $order, true ) );
		$this->assertLessThan( array_search( Meta::class, $order, true ), array_search( Taxonomies::class, $order, true ) );
		$this->assertLessThan( array_search( Meta::class, $order, true ), array_search( Options::class, $order, true ) );
		$this->assertLessThan( array_search( Rewrites::class, $order, true ), array_search( PostTypes::class, $order, true ) );
	}

	/**
	 * Decision A: rj_category's native REST route is /wp/v2/rj-categories and
	 * serves only rj_category; core's /wp/v2/categories stays core's. Public
	 * term links keep the /c/ rewrite.
	 */
	public function test_category_rest_route_is_rj_categories(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->set_permalink_structure( '/%postname%/' );
		// Register this taxonomy against the active pretty-permalink structure.
		unregister_taxonomy( 'rj_category' );
		Taxonomies::register_all();

		$ours = self::factory()->term->create(
			array(
				'taxonomy' => 'rj_category',
				'name'     => 'Probe RJ',
			)
		);
		self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'Probe Core',
			)
		);

		$this->assertArrayHasKey( '/wp/v2/rj-categories', rest_get_server()->get_routes() );

		$request = new \WP_REST_Request( 'GET', '/wp/v2/rj-categories' );
		$request->set_param( 'search', 'Probe' );
		$ours_list = (array) rest_do_request( $request )->get_data();

		$request = new \WP_REST_Request( 'GET', '/wp/v2/categories' );
		$request->set_param( 'search', 'Probe' );
		$core_list = (array) rest_do_request( $request )->get_data();

		$this->assertSame( array( 'rj_category' ), array_values( array_unique( array_column( $ours_list, 'taxonomy' ) ) ) );
		$this->assertSame( array( 'category' ), array_values( array_unique( array_column( $core_list, 'taxonomy' ) ) ) );
		$this->assertStringContainsString( '/c/', (string) get_term_link( $ours, 'rj_category' ) );
	}

	/**
	 * Every post type and taxonomy has its native wp/v2 route.
	 */
	public function test_native_rest_routes(): void {
		$routes = rest_get_server()->get_routes();

		foreach ( array( 'products', 'reels', 'collections', 'showrooms', 'testimonials', 'page-sections', 'rj-categories', 'metals', 'purity', 'occasions', 'audience', 'collection-links', 'jewellery-tags' ) as $base ) {
			$this->assertArrayHasKey( '/wp/v2/' . $base, $routes, $base );
		}

		$custom = array_filter( array_keys( $routes ), static fn( string $route ): bool => str_starts_with( $route, '/rj/' ) );

		$this->assertSame( array(), array_values( $custom ), 'Stage 4 adds no custom routes.' );
	}
}
