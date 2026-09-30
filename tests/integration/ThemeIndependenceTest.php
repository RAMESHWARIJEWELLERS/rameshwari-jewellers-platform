<?php
/**
 * Theme-independence test 1 of 5.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Integration;

use Rameshwari\Core\Data\Capabilities;
use Rameshwari\Core\Data\PostTypes;
use Rameshwari\Core\Data\Taxonomies;
use WP_UnitTestCase;

/**
 * Stock theme, core plugin only: every entity is creatable and readable
 * over wp/v2. Regression 5 for the two creation paths that exist in Stage 4.
 */
final class ThemeIndependenceTest extends WP_UnitTestCase {

	/**
	 * A stock theme and an administrator holding the platform capabilities.
	 */
	public function set_up(): void {
		parent::set_up();
		// Re-run the normal registration lifecycle after WP_UnitTestCase clears meta.
		do_action( 'init' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Exercises the plugin's normal WordPress init registration path.
		switch_theme( WP_DEFAULT_THEME );
		Capabilities::apply();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Each of the six post types is created and read back over REST.
	 */
	public function test_every_post_type_rest_creatable_and_readable(): void {
		$this->assertSame( WP_DEFAULT_THEME, get_stylesheet() );

		foreach ( PostTypes::definitions() as $post_type => $args ) {
			$route   = '/wp/v2/' . $args['rest_base'];
			$request = new \WP_REST_Request( 'POST', $route );
			$request->set_body_params(
				array(
					'title'  => 'Theme check',
					'status' => 'publish',
				)
			);

			$created = rest_do_request( $request );

			$this->assertSame( 201, $created->get_status(), $post_type );

			$read = rest_do_request( new \WP_REST_Request( 'GET', $route . '/' . $created->get_data()['id'] ) );

			$this->assertSame( 200, $read->get_status(), $post_type );
			$this->assertSame( $post_type, $read->get_data()['type'] );
		}
	}

	/**
	 * Each of the seven taxonomies accepts a term and returns it over REST.
	 */
	public function test_every_taxonomy_rest_creatable_and_readable(): void {
		foreach ( Taxonomies::definitions() as $taxonomy => $definition ) {
			$route   = '/wp/v2/' . $definition['args']['rest_base'];
			$request = new \WP_REST_Request( 'POST', $route );
			$request->set_body_params( array( 'name' => 'Theme check ' . $taxonomy ) );

			$created = rest_do_request( $request );

			$this->assertSame( 201, $created->get_status(), $taxonomy );
			$this->assertSame( $taxonomy, $created->get_data()['taxonomy'] );
		}
	}

	/**
	 * Regression 5: a category created by direct insert or over REST is visible.
	 * The tree manager (UI) and importer paths arrive in Stages 5 and 18.
	 */
	public function test_category_visible_by_default(): void {
		$direct = wp_insert_term( 'Direct', 'rj_category' );

		$this->assertTrue( (bool) get_term_meta( $direct['term_id'], '_rj_visible', true ) );

		$explicit_false = wp_insert_term( 'Explicitly hidden', 'rj_category', array( 'meta_input' => array( '_rj_visible' => false ) ) );
		$explicit_true  = wp_insert_term( 'Explicitly shown', 'rj_category', array( 'meta_input' => array( '_rj_visible' => true ) ) );

		$this->assertFalse( (bool) get_term_meta( $explicit_false['term_id'], '_rj_visible', true ) );
		$this->assertTrue( (bool) get_term_meta( $explicit_true['term_id'], '_rj_visible', true ) );

		$request = new \WP_REST_Request( 'POST', '/wp/v2/rj-categories' );
		$request->set_body_params( array( 'name' => 'Over REST' ) );
		$created = rest_do_request( $request )->get_data();

		$this->assertSame( 'rj_category', $created['taxonomy'] );
		$this->assertTrue( $created['meta']['_rj_visible'] );
	}
}
