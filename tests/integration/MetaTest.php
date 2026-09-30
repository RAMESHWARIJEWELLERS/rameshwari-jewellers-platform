<?php
/**
 * Meta registry tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Integration;

use Rameshwari\Core\Data\Capabilities;
use Rameshwari\Core\Data\Meta;
use Rameshwari\Core\Data\Options;
use WP_UnitTestCase;

/**
 * Development Blueprint §9 against the registered meta.
 */
final class MetaTest extends WP_UnitTestCase {

	/**
	 * Roles are needed for the permission tests.
	 */
	public function set_up(): void {
		parent::set_up();
		// WP_UnitTestCase unregisters registered meta between tests. Re-run the normal
		// init lifecycle so the plugin's priority-5 callback restores its registry.
		do_action( 'init' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Exercises the plugin's normal WordPress init registration path.
		Capabilities::apply();
	}

	/**
	 * Runtime registrations: 56 post, 63 term, 10 user (129 total).
	 */
	public function test_registration_counts(): void {
		$post = array(
			'rj_product'      => 14,
			'rj_reel'         => 9,
			'rj_collection'   => 4,
			'rj_showroom'     => 11,
			'rj_testimonial'  => 6,
			'rj_page_section' => 12, // Eleven §9.6 keys plus the reused _rj_order key.
		);

		foreach ( $post as $type => $count ) {
			$this->assertCount( $count, array_filter( get_registered_meta_keys( 'post', $type ), static fn( array $args, string $key ): bool => str_starts_with( $key, '_rj_' ), ARRAY_FILTER_USE_BOTH ), $type );
		}

		$this->assertCount( 16, get_registered_meta_keys( 'term', 'rj_category' ) );
		$this->assertArrayHasKey( '_rj_resolver', get_registered_meta_keys( 'term', 'rj_category' ) );

		foreach ( Meta::FLAT_TERM_TAXONOMIES as $taxonomy ) {
			$this->assertCount( 11, get_registered_meta_keys( 'term', $taxonomy ), $taxonomy );
			$this->assertArrayNotHasKey( '_rj_resolver', get_registered_meta_keys( 'term', $taxonomy ), $taxonomy );
		}

		$this->assertSame(
			array( '_rj_name_hi', '_rj_name_en', '_rj_seo_title' ),
			array_keys( get_registered_meta_keys( 'term', 'rj_tag' ) )
		);
		$this->assertCount( 11, array_filter( array_keys( get_registered_meta_keys( 'post', 'rj_page_section' ) ), static fn( string $key ): bool => '_rj_order' !== $key ) );

		$this->assertSame( array(), get_registered_meta_keys( 'term', 'rj_collection_tax' ) );
		$this->assertCount( 10, array_filter( array_keys( get_registered_meta_keys( 'user' ) ), static fn( string $key ): bool => str_starts_with( $key, '_rj_' ) ) );
	}

	/**
	 * Types, defaults and schema bounds come from §9.
	 */
	public function test_types_defaults_and_schema(): void {
		$product = get_registered_meta_keys( 'post', 'rj_product' );

		$this->assertSame( 'number', $product['_rj_weight']['type'] );
		$this->assertEquals( 0, $product['_rj_weight']['show_in_rest']['schema']['minimum'] );
		$this->assertEquals( 99999, $product['_rj_weight']['show_in_rest']['schema']['maximum'] );
		$this->assertSame( array( 'g', 'tola', 'carat' ), $product['_rj_weight_unit']['show_in_rest']['schema']['enum'] );
		$this->assertSame( 12, $product['_rj_gallery']['show_in_rest']['schema']['maxItems'] );
		$this->assertTrue( $product['_rj_code']['single'] );

		$category = get_registered_meta_keys( 'term', 'rj_category' );

		$this->assertTrue( $category['_rj_visible']['default'] );
		$this->assertSame( 4, $category['_rj_menu_columns']['default'] );
		$this->assertSame( '', $category['_rj_resolver']['default'] );
		$this->assertIsArray( $category['_rj_resolver']['show_in_rest'] );
		$this->assertSame( 'object', $category['_rj_resolver']['type'] );
		$this->assertArrayHasKey( 'properties', $category['_rj_resolver']['show_in_rest']['schema'] );

		$section = get_registered_meta_keys( 'post', 'rj_page_section' );

		$this->assertFalse( $section['_rj_active']['default'] );
		$this->assertSame( 'hero', $section['_rj_section_key']['default'] );
	}

	/**
	 * Private keys never appear in REST.
	 */
	public function test_private_keys_not_in_rest(): void {
		$product  = get_registered_meta_keys( 'post', 'rj_product' );
		$category = get_registered_meta_keys( 'term', 'rj_category' );

		foreach ( array( '_rj_making_note', '_rj_whatsapp_message', '_rj_view_count', '_rj_enquiry_count' ) as $key ) {
			$this->assertFalse( $product[ $key ]['show_in_rest'], $key );
		}

		$this->assertFalse( $category['_rj_depth_cache']['show_in_rest'] );
		$this->assertFalse( $category['_rj_path_cache']['show_in_rest'] );
		$this->assertFalse( $category['_rj_resolver']['auth_callback']( true, '_rj_resolver', 0 ) );
		$this->assertFalse( get_registered_meta_keys( 'post', 'rj_collection' )['_rj_auto_rule']['show_in_rest'] );

		foreach ( get_registered_meta_keys( 'user' ) as $key => $args ) {
			if ( str_starts_with( $key, '_rj_' ) ) {
				$this->assertFalse( $args['show_in_rest'], $key );
			}
		}
	}

	/**
	 * The three derived category caches refuse every writer, the administrator included.
	 */
	public function test_derived_caches_write_blocked(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$term = self::factory()->term->create( array( 'taxonomy' => 'rj_category' ) );

		foreach ( array( '_rj_depth_cache', '_rj_path_cache', '_rj_count_deep' ) as $key ) {
			$this->assertFalse( current_user_can( 'edit_term_meta', $term, $key ), $key );
		}

		$this->assertTrue( current_user_can( 'edit_term_meta', $term, '_rj_name_hi' ) );
	}

	/** Resolver is public to read, but only the category manager may write it. */
	public function test_resolver_read_only_rest_and_capability(): void {
		$term = self::factory()->term->create( array( 'taxonomy' => 'rj_category' ) );
		$meta = get_registered_meta_keys( 'term', 'rj_category' )['_rj_resolver'];
		$this->assertNotFalse( $meta['show_in_rest'] );
		wp_set_current_user( 0 );
		$this->assertFalse( $meta['auth_callback']( true, '_rj_resolver', $term ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		Capabilities::apply();
		$this->assertTrue( current_user_can( 'edit_term_meta', $term, '_rj_resolver' ) );

		$request = new \WP_REST_Request( 'POST', '/wp/v2/rj-categories/' . $term );
		$request->set_param(
			'meta',
			array(
				'_rj_resolver' => array(
					'tax'  => 'rj_metal',
					'slug' => 'gold',
				),
			)
		);
		$rejected = Meta::reject_resolver_rest_write( $term, $request );
		$this->assertWPError( $rejected );
		$this->assertSame( 403, $rejected->get_error_data()['status'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertFalse( current_user_can( 'edit_term_meta', $term, '_rj_resolver' ) );
	}

	/** Stage 4 registers empty resolver storage and seeds no Stage 5 mappings. */
	public function test_resolver_mapping_is_not_seeded(): void {
		$term = self::factory()->term->create( array( 'taxonomy' => 'rj_category' ) );
		$this->assertSame( '', get_term_meta( $term, '_rj_resolver', true ) );
		$this->assertSame(
			'',
			Meta::sanitize(
				'resolver',
				array(
					'tax'  => 'rj_category',
					'slug' => 'anything',
				)
			)
		);
	}

	/** Only the two approved resolver payload shapes are accepted. */
	public function test_resolver_accepts_only_locked_shapes(): void {
		$this->assertSame(
			array(
				'tax'  => 'rj_metal',
				'slug' => 'gold',
			),
			Meta::sanitize(
				'resolver',
				array(
					'tax'  => 'rj_metal',
					'slug' => 'gold',
				)
			)
		);
		$this->assertSame(
			array(
				'terms' => array( 10, 11 ),
				'paths' => array( 'jewellery/gold/a', 'jewellery/silver/b' ),
			),
			Meta::sanitize(
				'resolver',
				array(
					'terms' => array( 10, 11 ),
					'paths' => array( 'jewellery/gold/a', 'jewellery/silver/b' ),
				)
			)
		);
		$this->assertSame(
			'',
			Meta::sanitize(
				'resolver',
				array(
					'tax'  => 'rj_tag',
					'slug' => 'gold',
				)
			)
		);
		$this->assertSame(
			'',
			Meta::sanitize(
				'resolver',
				array(
					'tax'   => 'rj_metal',
					'slug'  => 'gold',
					'terms' => array(),
				)
			)
		);
	}

	/**
	 * An enquiry agent cannot write product meta; a catalogue manager can.
	 */
	public function test_product_meta_auth(): void {
		$product = self::factory()->post->create( array( 'post_type' => 'rj_product' ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'rj_enquiry_agent' ) ) );
		$this->assertFalse( current_user_can( 'edit_post_meta', $product, '_rj_code' ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'rj_catalogue_manager' ) ) );
		$this->assertTrue( current_user_can( 'edit_post_meta', $product, '_rj_code' ) );
	}

	/**
	 * Sanitisers enforce the §9 validation rules.
	 */
	public function test_sanitizers(): void {
		$this->assertSame( 'AB-12X', Meta::sanitize( 'code', ' ab-12 x!' ) );
		$this->assertSame( '302012', Meta::sanitize( 'pincode', '302 012' ) );
		$this->assertSame( '', Meta::sanitize( 'pincode', '3020' ) );
		$this->assertSame( 'public', Meta::sanitize( 'enum:public|hidden|archived', 'deleted' ) );
		$this->assertSame( 5, Meta::sanitize( 'int:0:5', 9 ) );
		$this->assertSame( '911234567890', Meta::sanitize( 'phone', '+91 12345 67890' ) );
		$this->assertSame( '911234567890', Meta::sanitize( 'phone', '1234567890' ) );
		$this->assertSame( '911234567890', Meta::sanitize( 'phone', '091234567890' ) );
		$this->assertSame( '911234567890', Meta::sanitize( 'phone', '911234567890' ) );
		$this->assertSame( '', Meta::sanitize( 'phone', '+44 20 7946 0958' ) );
		$this->assertSame( '', Meta::sanitize( 'phone', '12345' ) );
		$this->assertSame( '', Meta::sanitize( 'datetime', '2026-02-30 10:00:00' ) );
		$this->assertSame( '2026-10-20 09:00:00', Meta::sanitize( 'datetime', '2026-10-20 09:00:00' ) );
		$this->assertSame( 'whatsapp:bridal', Meta::sanitize( 'cta', 'whatsapp:bridal' ) );
		$this->assertSame( '', Meta::sanitize( 'cta', 'https://elsewhere.example/offer' ) );
		$this->assertSame( array(), Meta::sanitize( 'attachments:12', array( 999999 ) ) );
		$this->assertSame(
			array(
				'mon' => array(
					'open'  => '10:00',
					'close' => '20:00',
				),
				'sun' => array( 'closed' => true ),
			),
			Meta::sanitize(
				'timings',
				array(
					'mon' => array(
						'open'  => '10:00',
						'close' => '20:00',
					),
					'tue' => array( 'open' => '25:00' ),
					'sun' => array( 'closed' => true ),
				)
			)
		);
	}

	/**
	 * Weight leaves public product responses while the switch is off, and returns when it is on.
	 */
	public function test_weight_follows_public_switch(): void {
		$product = self::factory()->post->create(
			array(
				'post_type'   => 'rj_product',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $product, '_rj_weight', 12.5 );

		wp_set_current_user( 0 );
		$off = rest_do_request( new \WP_REST_Request( 'GET', '/wp/v2/products/' . $product ) )->get_data();

		$this->assertArrayNotHasKey( '_rj_weight', $off['meta'] );
		$this->assertArrayNotHasKey( '_rj_weight_unit', $off['meta'] );

		$this->assertTrue( Options::save( 'rj_display', array( 'show_weight_publicly' => true ) ) );
		$on = rest_do_request( new \WP_REST_Request( 'GET', '/wp/v2/products/' . $product ) )->get_data();

		$this->assertEquals( 12.5, $on['meta']['_rj_weight'] );
	}
}
