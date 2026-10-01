<?php
/**
 * Custom rewrite rules.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Data;

use Rameshwari\Core\Container;
use Rameshwari\Core\Module;

/**
 * The two custom routes of Development Blueprint §2.4: short product codes
 * and the account screens. Stage 4 registers the rules and query variables
 * only; the stages that own product lookup and the account area handle
 * the requests.
 *
 * Rules are flushed only on activation, deactivation and an explicit Tools
 * action (flush()), never on an ordinary request.
 */
final class Rewrites implements Module {

	public const CODE_VAR = 'rj_code';

	public const ACCOUNT_VAR = 'rj_account';

	public const ACCOUNT_SCREENS = array( 'dashboard', 'wishlist', 'viewed', 'collections', 'profile', 'notifications', 'privacy', 'login' );

	/**
	 * Rule patterns and their targets.
	 *
	 * @return array<string, string>
	 */
	public static function rules(): array {
		return array(
			'^p/([A-Za-z0-9-]+)/?$' => 'index.php?' . self::CODE_VAR . '=$matches[1]',
			'^account(?:/(' . implode( '|', self::ACCOUNT_SCREENS ) . '))?/?$' => 'index.php?' . self::ACCOUNT_VAR . '=$matches[1]',
		);
	}

	/**
	 * Module id.
	 *
	 * @return string
	 */
	public static function id(): string {
		return 'rewrites';
	}

	/**
	 * Needs the post types first.
	 *
	 * @return array<int, string>
	 */
	public static function requires(): array {
		return array( PostTypes::id() );
	}

	/**
	 * Hooks the rules and query variables.
	 *
	 * @param Container $container Shared container.
	 * @return void
	 */
	public function register( Container $container ): void {
		add_action( 'init', array( self::class, 'add_rules' ), PostTypes::PRIORITY );
		add_filter( 'query_vars', array( self::class, 'query_vars' ) );
	}

	/**
	 * Adds the rules.
	 *
	 * @return void
	 */
	public static function add_rules(): void {
		foreach ( self::rules() as $pattern => $target ) {
			add_rewrite_rule( $pattern, $target, 'top' );
		}
	}

	/**
	 * Adds the query variables.
	 *
	 * @param array<int, string> $vars Public query variables.
	 * @return array<int, string>
	 */
	public static function query_vars( array $vars ): array {
		$vars[] = self::CODE_VAR;
		$vars[] = self::ACCOUNT_VAR;

		return $vars;
	}

	/**
	 * Registers everything that owns a URL, then rebuilds the rules. For
	 * activation and the Tools action.
	 *
	 * @return void
	 */
	public static function flush(): void {
		PostTypes::register_all();
		Taxonomies::register_all();
		self::add_rules();
		flush_rewrite_rules( false );
	}

	/**
	 * Removes this plugin's post types, taxonomies and rules, then rebuilds
	 * the rules without them. For deactivation. Stored data is untouched.
	 *
	 * @return void
	 */
	public static function remove(): void {
		global $wp_rewrite;

		foreach ( array_keys( Taxonomies::definitions() ) as $taxonomy ) {
			if ( taxonomy_exists( $taxonomy ) ) {
				unregister_taxonomy( $taxonomy );
			}
		}

		foreach ( array_keys( PostTypes::definitions() ) as $post_type ) {
			if ( post_type_exists( $post_type ) ) {
				unregister_post_type( $post_type );
			}
		}

		foreach ( array_keys( self::rules() ) as $pattern ) {
			unset( $wp_rewrite->extra_rules_top[ $pattern ] );
		}

		flush_rewrite_rules( false );
	}
}
