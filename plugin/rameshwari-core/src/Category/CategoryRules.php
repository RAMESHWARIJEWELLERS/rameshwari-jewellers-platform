<?php
/**
 * Category rules module.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Category;

use Rameshwari\Core\Container;
use Rameshwari\Core\Data\Meta;
use Rameshwari\Core\Data\Taxonomies;
use Rameshwari\Core\Module;

/**
 * Hooks the assignment guard to the server boundary.
 *
 * Today that boundary is the product REST write. The Product Domain
 * (Stage 6), the importer (Stage 19) and the admin editor (Stage 12) call
 * AssignmentGuard from their own entry points.
 */
final class CategoryRules implements Module {

	/**
	 * Module id.
	 *
	 * @return string
	 */
	public static function id(): string {
		return 'category-rules';
	}

	/**
	 * Needs the taxonomies and the meta registry.
	 *
	 * @return array<int,string>
	 */
	public static function requires(): array {
		return array( Taxonomies::id(), Meta::id() );
	}

	/**
	 * Hooks the guard to product writes.
	 *
	 * @param Container $container Shared container.
	 * @return void
	 */
	public function register( Container $container ): void {
		add_filter( 'rest_pre_insert_rj_product', array( self::class, 'guard_product_request' ), 10, 2 );
	}

	/**
	 * Rejects a product write that assigns a root or resolver term, or names one as primary.
	 *
	 * @param mixed            $prepared Prepared post or error.
	 * @param \WP_REST_Request $request  Request.
	 * @return mixed The prepared post, or a 409 rj_conflict error.
	 */
	public static function guard_product_request( mixed $prepared, \WP_REST_Request $request ): mixed {
		if ( $prepared instanceof \WP_Error ) {
			return $prepared;
		}

		$raw  = $request->get_param( 'rj-categories' );
		$ids  = is_array( $raw ) ? array_map( 'absint', $raw ) : array();
		$meta = $request->get_param( 'meta' );

		$primary = is_array( $meta ) && isset( $meta['_rj_primary_term'] ) ? absint( $meta['_rj_primary_term'] ) : 0;

		if ( array() === $ids && 0 === $primary ) {
			return $prepared;
		}

		$repository = new CategoryRepository();
		$guard      = new AssignmentGuard( $repository, new ResolverEngine( $repository ) );
		$violations = $guard->check( $ids, $primary );

		return array() === $violations ? $prepared : $guard->error( $violations );
	}
}
