<?php
/**
 * Rewrite tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Integration;

use Rameshwari\Core\Data\PostTypes;
use Rameshwari\Core\Data\Rewrites;
use Rameshwari\Core\Data\Taxonomies;
use WP_UnitTestCase;

/**
 * The two custom routes, and deactivation.
 */
final class RewritesTest extends WP_UnitTestCase {

	/**
	 * Pretty permalinks with the plugin's rules in place.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' );
		Rewrites::flush();
	}

	/**
	 * Re-registers anything a test removed.
	 */
	public function tear_down(): void {
		PostTypes::register_all();
		Taxonomies::register_all();
		Rewrites::add_rules();
		parent::tear_down();
	}

	/**
	 * Both rules and both query variables are registered.
	 */
	public function test_rules_and_query_vars(): void {
		global $wp_rewrite;

		foreach ( array_keys( Rewrites::rules() ) as $pattern ) {
			$this->assertArrayHasKey( $pattern, $wp_rewrite->extra_rules_top, $pattern );
		}

		        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core hook.
		$vars = apply_filters( 'query_vars', array() );

		$this->assertContains( Rewrites::CODE_VAR, $vars );
		$this->assertContains( Rewrites::ACCOUNT_VAR, $vars );
	}

	/**
	 * /p/{code}/ sets the code query variable.
	 */
	public function test_short_code_url(): void {
		$this->go_to( home_url( '/p/RJ-001/' ) );

		$this->assertSame( 'RJ-001', get_query_var( Rewrites::CODE_VAR ) );
	}

	/**
	 * /account/{screen}/ sets the account query variable; unknown screens do not match.
	 */
	public function test_account_url(): void {
		$this->go_to( home_url( '/account/wishlist/' ) );
		$this->assertSame( 'wishlist', get_query_var( Rewrites::ACCOUNT_VAR ) );

		$this->go_to( home_url( '/account/bogus/' ) );
		$this->assertSame( '', get_query_var( Rewrites::ACCOUNT_VAR ) );
	}

	/**
	 * Flushing is never hooked to an ordinary request.
	 */
	public function test_flush_not_hooked(): void {
		foreach ( array( 'init', 'wp_loaded', 'wp', 'template_redirect' ) as $hook ) {
			$this->assertFalse( has_action( $hook, array( Rewrites::class, 'flush' ) ), $hook );
			$this->assertFalse( has_action( $hook, 'flush_rewrite_rules' ), $hook );
		}
	}

	/**
	 * Deactivation unregisters the post types and taxonomies; stored posts and terms stay.
	 */
	public function test_remove_keeps_data(): void {
		$post = self::factory()->post->create( array( 'post_type' => 'rj_product' ) );
		$term = self::factory()->term->create( array( 'taxonomy' => 'rj_category' ) );

		Rewrites::remove();

		$this->assertFalse( post_type_exists( 'rj_product' ) );
		$this->assertFalse( taxonomy_exists( 'rj_category' ) );
		$this->assertSame( 'rj_product', get_post( $post )->post_type );

		PostTypes::register_all();
		Taxonomies::register_all();

		$this->assertSame( $term, get_term( $term, 'rj_category' )->term_id );
	}
}
