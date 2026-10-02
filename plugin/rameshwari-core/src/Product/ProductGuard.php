<?php
/**
 * Product write and read guard.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Product;

use Rameshwari\Core\Data\Meta;

/**
 * WordPress adapter that catches product writes made outside ProductService
 * and enforces public-read visibility. It holds no rules of its own: every
 * check calls ProductService, and category rules reach the Stage 5
 * AssignmentGuard through it.
 *
 * REST gets a pre-write veto. Direct meta writes are short-circuited.
 * Classic saves are checked after saving and an invalid publish is reverted
 * to draft. Term writes cannot be vetoed by WordPress, so they are reverted.
 * Public reads (REST collection, REST single, native queries) exclude every
 * product that is not published and public.
 */
final class ProductGuard {

	/**
	 * Whether the guard is mid-write, to prevent re-entrancy.
	 *
	 * @var bool
	 */
	private static bool $busy = false;

	/**
	 * Products that moved to publish before their meta and terms were saved.
	 * Their marker is written only once the save proves them valid.
	 *
	 * @var array<int, bool>
	 */
	private static array $pending = array();

	/**
	 * Products being hard-deleted. Core removes term relationships before the
	 * row, so a removal guard must not restore terms on a product that is going.
	 *
	 * @var array<int, bool>
	 */
	private static array $deleting = array();

	/**
	 * Taxonomies whose terms a published product depends on.
	 */
	private const GUARDED_TAXONOMIES = array( 'rj_metal', 'rj_purity', 'rj_category' );

	/**
	 * Hooks every interception point.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'rest_pre_insert_rj_product', array( self::class, 'guard_rest' ), 5, 2 );
		add_action( 'rest_after_insert_rj_product', array( self::class, 'after_rest' ), 10, 1 );
		add_filter( 'rest_rj_product_query', array( self::class, 'public_rest_query' ), 10, 1 );
		add_filter( 'rest_request_before_callbacks', array( self::class, 'guard_rest_read' ), 10, 3 );
		add_action( 'pre_get_posts', array( self::class, 'public_query' ) );
		add_action( 'transition_post_status', array( self::class, 'on_transition' ), 10, 3 );

		foreach ( array( 'add_post_metadata', 'update_post_metadata', 'delete_post_metadata' ) as $filter ) {
			add_filter( $filter, array( self::class, 'guard_meta' ), 5, 4 );
		}

		add_action( 'save_post_rj_product', array( self::class, 'after_save' ), 20, 1 );
		add_action( 'save_post_rj_product', array( self::class, 'recheck_code' ), 25, 1 );
		add_action( 'set_object_terms', array( self::class, 'guard_terms' ), 10, 6 );
		add_action( 'before_delete_post', array( self::class, 'mark_deleting' ), 1, 1 );
		add_action( 'deleted_term_relationships', array( self::class, 'guard_term_removal' ), 10, 3 );
		add_action( 'admin_notices', array( self::class, 'notices' ) );
	}

	/**
	 * Runs a callback with system write permission for the publication marker.
	 *
	 * @param callable $callback Callback.
	 * @return mixed
	 */
	public static function as_system( callable $callback ): mixed {
		return ProductSystemScope::run( $callback );
	}

	/**
	 * Whether the current user may read non-public products (editors, admins).
	 *
	 * @return bool
	 */
	private static function can_read_all(): bool {
		$type = get_post_type_object( ProductRepository::TYPE );

		return $type instanceof \WP_Post_Type && current_user_can( (string) $type->cap->edit_others_posts );
	}

	/**
	 * Restricts the public REST collection to public products.
	 *
	 * @param array<string, mixed> $args Query args.
	 * @return array<string, mixed>
	 */
	public static function public_rest_query( array $args ): array {
		return self::can_read_all() ? $args : self::with_public_clause( $args );
	}

	/**
	 * Restricts native product queries (archive, search, single) for the public.
	 * is_admin() is not an exemption: it is also true for admin-ajax.php, which
	 * anonymous visitors can reach. Only users who can edit others' products
	 * (see can_read_all) are unfiltered.
	 *
	 * @param \WP_Query $query Query.
	 * @return void
	 */
	public static function public_query( \WP_Query $query ): void {
		$type = $query->get( 'post_type' );

		if ( true === $query->get( 'rj_internal' ) || self::can_read_all() ) {
			return;
		}

		if ( ProductRepository::TYPE === $type || array( ProductRepository::TYPE ) === $type ) {
			$query->query_vars = self::with_public_clause( $query->query_vars );

			return;
		}

		if ( ! self::targets_products( $query, $type ) ) {
			return;
		}

		// Broad query (taxonomy archive, search, any, mixed types): exclude only
		// non-public products by ID, so other post types and pagination are untouched.
		$excluded = array_map( 'intval', (array) $query->get( 'post__not_in' ) );
		$query->set( 'post__not_in', array_values( array_unique( array_merge( $excluded, self::non_public_ids() ) ) ) );
	}

	/**
	 * Whether a non-explicit query can return products.
	 *
	 * @param \WP_Query $query Query.
	 * @param mixed     $type  The post_type query var.
	 * @return bool
	 */
	private static function targets_products( \WP_Query $query, mixed $type ): bool {
		if ( is_array( $type ) ) {
			return in_array( ProductRepository::TYPE, $type, true ) || in_array( 'any', $type, true );
		}

		if ( is_string( $type ) && '' !== $type ) {
			return 'any' === $type;
		}

		return $query->is_tax() || $query->is_search();
	}

	/**
	 * IDs of products whose visibility is not public (hidden, archived, invalid).
	 *
	 * @return array<int, int>
	 */
	private static function non_public_ids(): array {
		$lookup = new \WP_Query(
			array(
				'post_type'      => ProductRepository::TYPE,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'rj_internal'    => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Canonical _rj_visibility state; selects the few non-public products to exclude from broad public reads (H-1).
				'meta_query'     => array(
					array(
						'key'     => '_rj_visibility',
						'value'   => 'public',
						'compare' => '!=',
					),
				),
			)
		);

		return array_map( 'intval', $lookup->posts );
	}

	/**
	 * A single-product REST read of a non-public product is a 404 for the public.
	 *
	 * @param mixed            $response Response so far.
	 * @param mixed            $handler  Route handler.
	 * @param \WP_REST_Request $request  Request.
	 * @return mixed
	 */
	public static function guard_rest_read( mixed $response, mixed $handler, \WP_REST_Request $request ): mixed {
		$type = get_post_type_object( ProductRepository::TYPE );

		if ( 'GET' !== $request->get_method() || ! $type instanceof \WP_Post_Type || self::can_read_all() ) {
			return $response;
		}

		$base = is_string( $type->rest_base ) && '' !== $type->rest_base ? $type->rest_base : $type->name;

		if ( 1 !== preg_match( '#^/wp/v2/' . preg_quote( $base, '#' ) . '/(\d+)$#', $request->get_route(), $match ) ) {
			return $response;
		}

		return ProductModule::service()->is_public( (int) $match[1] )
			? $response
			: new \WP_Error( 'rest_post_invalid_id', 'Invalid post ID.', array( 'status' => 404 ) );
	}

	/**
	 * Adds the canonical public clause once.
	 *
	 * @param array<string, mixed> $args Query args.
	 * @return array<string, mixed>
	 */
	private static function with_public_clause( array $args ): array {
		$meta = isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ? $args['meta_query'] : array();

		if ( isset( $meta['rj_public'] ) ) {
			return $args;
		}

		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- _rj_visibility is the canonical Stage 6 visibility state; scoped to rj_product public reads only. Dropping it would break H-1 (hidden and archived products must not be public). No global suppression.
		$args['meta_query'] = array(
			'relation'  => 'AND',
			'rj_prior'  => $meta,
			'rj_public' => ProductModule::service()->public_visibility_clause(),
		);

		return $args;
	}

	/**
	 * Pre-write veto for REST product writes.
	 *
	 * @param mixed            $prepared Prepared post or error.
	 * @param \WP_REST_Request $request  Request.
	 * @return mixed
	 */
	public static function guard_rest( mixed $prepared, \WP_REST_Request $request ): mixed {
		if ( $prepared instanceof \WP_Error ) {
			return $prepared;
		}

		$service = ProductModule::service();
		$id      = (int) $request->get_param( 'id' );
		$id      = $id > 0 ? $id : null;
		$meta    = $request->get_param( 'meta' );
		$meta    = is_array( $meta ) ? $meta : array();

		if ( array_key_exists( Meta::PUBLICATION_MARKER, $meta ) ) {
			return new \WP_Error( 'rj_forbidden', 'The publication marker is system-owned.', array( 'status' => 403 ) );
		}

		if ( isset( $meta['_rj_visibility'] ) && ! in_array( $meta['_rj_visibility'], ProductData::VISIBILITIES, true ) ) {
			return new \WP_Error( 'rj_invalid', 'Invalid visibility: use public, hidden or archived.', array( 'status' => 400 ) );
		}

		if ( isset( $meta['_rj_code'] ) ) {
			$error = $service->code_conflict( (string) $meta['_rj_code'], $id );

			if ( null !== $error ) {
				return $error;
			}
		}

		$params = array(
			'metal'      => $request->get_param( 'metals' ),
			'purity'     => $request->get_param( 'purity' ),
			'categories' => $request->get_param( 'rj-categories' ),
		);

		foreach ( array( 'metal', 'purity' ) as $facet ) {
			if ( is_array( $params[ $facet ] ) && count( $params[ $facet ] ) > 1 ) {
				return new \WP_Error( 'rj_conflict', "A product may have at most one {$facet}.", array( 'status' => 409 ) );
			}
		}

		$repository = new ProductRepository();
		$stored     = null === $id ? null : $repository->find( $id );
		$status     = $request->get_param( 'status' );
		$effective  = is_string( $status ) && '' !== $status ? $status : (string) ( $stored['status'] ?? 'draft' );

		if ( 'publish' !== $effective ) {
			return $prepared;
		}

		$state = (array) $stored;

		foreach ( $params as $key => $value ) {
			if ( is_array( $value ) ) {
				$state[ $key ] = array_map( 'absint', $value );
			}
		}

		foreach ( array(
			'code'    => '_rj_code',
			'name_en' => '_rj_name_en',
			'name_hi' => '_rj_name_hi',
		) as $key => $meta_key ) {
			if ( isset( $meta[ $meta_key ] ) ) {
				$state[ $key ] = 'code' === $key ? ProductCode::normalise( (string) $meta[ $meta_key ] ) : (string) $meta[ $meta_key ];
			}
		}

		$gaps = $service->missing_for_publication( $state );

		return array() === $gaps ? $prepared : new \WP_Error( 'rj_invalid', 'Cannot publish: needs ' . implode( ', ', $gaps ) . '.', array( 'status' => 400 ) );
	}

	/**
	 * After a REST write the meta and terms exist: sync the title and
	 * recheck the Product Code claim.
	 *
	 * @param \WP_Post $post Product.
	 * @return void
	 */
	public static function after_rest( \WP_Post $post ): void {
		self::sync_title( $post->ID );
		self::recheck( $post->ID );
		self::finalize_publication( $post->ID );
	}

	/**
	 * Short-circuits meta writes that would break a product rule.
	 *
	 * @param mixed  $check      Short-circuit value.
	 * @param int    $object_id  Post ID.
	 * @param string $meta_key   Key.
	 * @param mixed  $meta_value Value.
	 * @return mixed False to refuse the write.
	 */
	public static function guard_meta( mixed $check, int $object_id, string $meta_key, mixed $meta_value = null ): mixed {
		if ( Meta::PUBLICATION_MARKER === $meta_key ) {
			return ProductSystemScope::active() ? $check : false;
		}

		if ( $object_id < 1 || ProductRepository::TYPE !== get_post_type( $object_id ) ) {
			return $check;
		}

		$service = ProductModule::service();
		$delete  = 'delete_post_metadata' === current_filter();
		$value   = $delete ? '' : (string) ( is_scalar( $meta_value ) ? $meta_value : '' );

		if ( '_rj_code' === $meta_key ) {
			return ProductSystemScope::active() || null === $service->code_conflict( $value, $object_id ) ? $check : false;
		}

		if ( '_rj_visibility' === $meta_key ) {
			return $delete || in_array( $value, ProductData::VISIBILITIES, true ) ? $check : false;
		}

		if ( in_array( $meta_key, array( '_rj_name_en', '_rj_name_hi' ), true ) ) {
			return 'publish' === get_post_status( $object_id ) && '' === trim( $value ) ? false : $check;
		}

		if ( '_rj_primary_term' === $meta_key && absint( $meta_value ) > 0 ) {
			return array() === $service->category_violations( array(), absint( $meta_value ) ) ? $check : false;
		}

		return $check;
	}

	/**
	 * Post-save check for classic and programmatic saves: title sync, and an
	 * incomplete publish (including an edit that broke a published product)
	 * goes back to draft. REST is vetoed earlier.
	 *
	 * @param int $post_id Product ID.
	 * @return void
	 */
	public static function after_save( int $post_id ): void {
		if ( self::$busy || wp_is_post_revision( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		self::sync_title( $post_id );

		if ( 'publish' !== get_post_status( $post_id ) ) {
			unset( self::$pending[ $post_id ] );

			return;
		}

		$gaps = ProductModule::service()->publication_gaps( $post_id );

		if ( array() === $gaps ) {
			self::finalize_publication( $post_id );

			return;
		}

		unset( self::$pending[ $post_id ] );

		self::with_busy(
			static function () use ( $post_id ): void {
				wp_update_post(
					array(
						'ID'          => $post_id,
						'post_status' => 'draft',
					)
				);
			}
		);

		set_transient( 'rj_product_errors_' . get_current_user_id(), 'Not published: needs ' . implode( ', ', $gaps ) . '.', 60 );
	}

	/**
	 * Records first publication at the transition to publish, once the product
	 * is known to be valid. A product whose meta and terms are saved after the
	 * transition (classic save, REST create) is held as pending and finalized
	 * when that save has passed validation; an invalid publish that is reverted
	 * to draft never gets a marker. Add-if-absent: never replaced or cleared.
	 *
	 * @param string   $new_status New status.
	 * @param string   $old_status Old status.
	 * @param \WP_Post $post       Post.
	 * @return void
	 */
	public static function on_transition( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( self::$busy || 'publish' !== $new_status || 'publish' === $old_status || ProductRepository::TYPE !== $post->post_type ) {
			return;
		}

		$service = ProductModule::service();

		if ( array() === $service->publication_gaps( $post->ID ) ) {
			$service->record_first_publication( $post->ID );

			return;
		}

		self::$pending[ $post->ID ] = true;
	}

	/**
	 * Writes the held marker once the product has proven valid and published.
	 *
	 * @param int $post_id Product ID.
	 * @return void
	 */
	private static function finalize_publication( int $post_id ): void {
		if ( ! isset( self::$pending[ $post_id ] ) ) {
			return;
		}

		unset( self::$pending[ $post_id ] );

		$service = ProductModule::service();

		if ( 'publish' === get_post_status( $post_id ) && array() === $service->publication_gaps( $post_id ) ) {
			$service->record_first_publication( $post_id );
		}
	}

	/**
	 * Post-write Product Code recheck for classic and programmatic saves.
	 *
	 * @param int $post_id Product ID.
	 * @return void
	 */
	public static function recheck_code( int $post_id ): void {
		if ( ! self::$busy && ! wp_is_post_revision( $post_id ) ) {
			self::recheck( $post_id );
		}
	}

	/**
	 * Reverts a term write that breaks a product rule, including one that
	 * would leave a published product publication-invalid.
	 *
	 * @param int          $object_id Product ID.
	 * @param array<mixed> $terms     Terms as passed.
	 * @param array<int>   $tt_ids    New term-taxonomy IDs.
	 * @param string       $taxonomy  Taxonomy.
	 * @param bool         $append    Whether appended.
	 * @param array<int>   $old_tt    Previous term-taxonomy IDs.
	 * @return void
	 */
	public static function guard_terms( int $object_id, array $terms, array $tt_ids, string $taxonomy, bool $append, array $old_tt ): void {
		if ( self::$busy || ProductRepository::TYPE !== get_post_type( $object_id ) || ! in_array( $taxonomy, array( 'rj_metal', 'rj_purity', 'rj_category' ), true ) ) {
			return;
		}

		if ( self::inside( array( 'wp_delete_term' ) ) ) {
			return;
		}

		$service = ProductModule::service();
		$new     = array_map( 'intval', (array) wp_get_object_terms( $object_id, $taxonomy, array( 'fields' => 'ids' ) ) );
		$bad     = 'rj_category' === $taxonomy
			? array() !== $service->category_violations( $new )
			: count( $new ) > 1;

		if ( ! $bad && 'publish' === get_post_status( $object_id ) ) {
			$bad = array() !== $service->publication_gaps( $object_id );
		}

		if ( ! $bad ) {
			return;
		}

		$previous = array();

		foreach ( $old_tt as $tt_id ) {
			$term = get_term_by( 'term_taxonomy_id', (int) $tt_id, $taxonomy );

			if ( $term instanceof \WP_Term ) {
				$previous[] = $term->term_id;
			}
		}

		self::with_busy(
			static function () use ( $object_id, $previous, $taxonomy ): void {
				wp_set_object_terms( $object_id, $previous, $taxonomy );
			}
		);
	}

	/**
	 * Notes that a product is being hard-deleted.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function mark_deleting( int $post_id ): void {
		if ( ProductRepository::TYPE === get_post_type( $post_id ) ) {
			self::$deleting[ $post_id ] = true;
		}
	}

	/**
	 * Restores terms removed from a published product when the removal would
	 * leave it publication-invalid. wp_remove_object_terms() does not fire
	 * set_object_terms, so removals need their own guard. Replacing or adding
	 * terms is handled by guard_terms(); both use the same publication rule.
	 *
	 * @param int          $object_id Product ID.
	 * @param array<mixed> $tt_ids    Removed term-taxonomy IDs.
	 * @param string       $taxonomy  Taxonomy.
	 * @return void
	 */
	public static function guard_term_removal( int $object_id, array $tt_ids, string $taxonomy ): void {
		if ( self::$busy || isset( self::$deleting[ $object_id ] ) || ! in_array( $taxonomy, self::GUARDED_TAXONOMIES, true ) ) {
			return;
		}

		if ( ProductRepository::TYPE !== get_post_type( $object_id ) || 'publish' !== get_post_status( $object_id ) ) {
			return;
		}

		// Replacement is judged on its final state by guard_terms(); a term being
		// deleted must never get a relationship restored to a row about to go.
		if ( self::inside( array( 'wp_set_object_terms', 'wp_delete_term' ) ) ) {
			return;
		}

		clean_object_term_cache( $object_id, ProductRepository::TYPE );

		if ( array() === ProductModule::service()->publication_gaps( $object_id ) ) {
			return;
		}

		$removed = array();

		foreach ( $tt_ids as $tt_id ) {
			$term = get_term_by( 'term_taxonomy_id', (int) $tt_id, $taxonomy );

			if ( $term instanceof \WP_Term ) {
				$removed[] = $term->term_id;
			}
		}

		if ( array() === $removed ) {
			return;
		}

		self::with_busy(
			static function () use ( $object_id, $removed, $taxonomy ): void {
				wp_set_object_terms( $object_id, $removed, $taxonomy, true );
			}
		);
	}

	/**
	 * Shows and clears the pending product notice.
	 *
	 * @return void
	 */
	public static function notices(): void {
		$key     = 'rj_product_errors_' . get_current_user_id();
		$message = get_transient( $key );

		if ( is_string( $message ) && '' !== $message ) {
			delete_transient( $key );
			echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
		}
	}

	/**
	 * Rechecks the Product Code claim and tells the user if it was lost.
	 *
	 * @param int $post_id Product ID.
	 * @return void
	 */
	private static function recheck( int $post_id ): void {
		if ( ProductModule::service()->resolve_code_race( $post_id ) ) {
			set_transient( 'rj_product_errors_' . get_current_user_id(), 'That Product Code was claimed by another product and has been cleared.', 60 );
		}
	}

	/**
	 * Whether any of the named core functions is on the call stack. Core gives
	 * no hook at the start of wp_set_object_terms() or wp_delete_term(), and a
	 * flag set by a hook could stick if core returns early, so the stack is the
	 * stateless signal.
	 *
	 * @param array<int, string> $functions Function names.
	 * @return bool
	 */
	private static function inside( array $functions ): bool {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Stateless detection of core term operations; no output is produced.
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 25 ) as $frame ) {
			if ( in_array( $frame['function'], $functions, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Runs a write with the re-entrancy flag set. The flag always clears.
	 *
	 * @param callable $callback Callback.
	 * @return void
	 */
	private static function with_busy( callable $callback ): void {
		$previous   = self::$busy;
		self::$busy = true;

		try {
			$callback();
		} finally {
			self::$busy = $previous;
		}
	}

	/**
	 * Keeps post_title equal to the English name.
	 *
	 * @param int $post_id Product ID.
	 * @return void
	 */
	private static function sync_title( int $post_id ): void {
		$name = (string) get_post_meta( $post_id, '_rj_name_en', true );

		if ( self::$busy || '' === $name || get_post_field( 'post_title', $post_id ) === $name ) {
			return;
		}

		self::with_busy(
			static function () use ( $post_id, $name ): void {
				wp_update_post(
					array(
						'ID'         => $post_id,
						'post_title' => $name,
					)
				);
			}
		);
	}
}
