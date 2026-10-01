<?php
/**
 * Category repository.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Category;

/**
 * The only place that reads or writes rj_category terms for the Category Engine.
 *
 * Lookup walks the path from the root, one parent at a time, so a category is
 * found by its full path and never by name or by a runtime term ID.
 */
final class CategoryRepository {

	public const TAXONOMY = 'rj_category';

	/**
	 * Term ID for a path, or null when no such category exists.
	 *
	 * @param CategoryPath $path Full path.
	 * @return int|null
	 */
	public function find( CategoryPath $path ): ?int {
		$parent = 0;

		foreach ( $path->segments() as $segment ) {
			$ids = get_terms(
				array(
					'taxonomy'   => self::TAXONOMY,
					'slug'       => $segment,
					'parent'     => $parent,
					'hide_empty' => false,
					'fields'     => 'ids',
					'number'     => 1,
				)
			);

			if ( ! is_array( $ids ) || array() === $ids ) {
				return null;
			}

			$parent = (int) $ids[0];
		}

		return $parent;
	}

	/**
	 * Creates a category under a parent.
	 *
	 * @param string $name      Visible name.
	 * @param string $slug      Latin slug.
	 * @param int    $parent_id Parent term ID, 0 for a root.
	 * @return int New term ID.
	 * @throws \RuntimeException When WordPress refuses the term.
	 */
	public function create( string $name, string $slug, int $parent_id ): int {
		$result = wp_insert_term(
			$name,
			self::TAXONOMY,
			array(
				'slug'   => $slug,
				'parent' => $parent_id,
			)
		);

		if ( is_wp_error( $result ) ) {
			throw new \RuntimeException( esc_html( $result->get_error_message() ) );
		}

		return (int) $result['term_id'];
	}

	/**
	 * Renames a category; identity and position are unchanged.
	 *
	 * Does nothing when the name is unchanged. wp_update_term() is not used:
	 * it looks slugs up per taxonomy and rejects any slug an older term also
	 * holds, but the same slug under different parents is valid here, so the
	 * name is written directly and the term cache is cleared.
	 *
	 * @param int    $term_id Term ID.
	 * @param string $name    Visible name.
	 * @return void
	 * @throws \RuntimeException When the term is missing or the write fails.
	 */
	public function rename( int $term_id, string $name ): void {
		$term = get_term( $term_id, self::TAXONOMY );

		if ( ! $term instanceof \WP_Term ) {
			throw new \RuntimeException( 'Category term not found.' );
		}

		$name = (string) sanitize_term_field( 'name', $name, $term_id, self::TAXONOMY, 'db' );

		if ( $term->name === $name ) {
			return;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- wp_update_term() rejects shared slugs; the term cache is cleared below.
		$updated = $wpdb->update( $wpdb->terms, array( 'name' => $name ), array( 'term_id' => $term_id ) );

		if ( false === $updated ) {
			throw new \RuntimeException( 'Category rename failed.' );
		}

		clean_term_cache( $term_id, self::TAXONOMY );
	}

	/**
	 * Writes one registered term meta value.
	 *
	 * @param int    $term_id Term ID.
	 * @param string $key     Meta key.
	 * @param mixed  $value   Value.
	 * @return void
	 */
	public function set_meta( int $term_id, string $key, mixed $value ): void {
		update_term_meta( $term_id, $key, $value );
	}

	/**
	 * All category term IDs.
	 *
	 * @return array<int, int>
	 */
	public function all_ids(): array {
		$ids = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
				'fields'     => 'ids',
			)
		);

		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}

	/**
	 * One term meta value.
	 *
	 * @param int    $term_id Term ID.
	 * @param string $key     Meta key.
	 * @return mixed
	 */
	public function get_meta( int $term_id, string $key ): mixed {
		return get_term_meta( $term_id, $key, true );
	}

	/**
	 * Parent term ID, or null when the term is not a category.
	 *
	 * @param int $term_id Term ID.
	 * @return int|null
	 */
	public function parent_of( int $term_id ): ?int {
		$term = get_term( $term_id, self::TAXONOMY );

		return $term instanceof \WP_Term ? (int) $term->parent : null;
	}

	/**
	 * Term ID of a facet term, or null when it does not exist.
	 *
	 * @param string $taxonomy Facet taxonomy.
	 * @param string $slug     Term slug.
	 * @return int|null
	 */
	public function facet_term_id( string $taxonomy, string $slug ): ?int {
		$term = get_term_by( 'slug', $slug, $taxonomy );

		return $term instanceof \WP_Term ? $term->term_id : null;
	}

	/**
	 * The slash-joined slug path of a category, root first; empty when not a category.
	 *
	 * @param int $term_id Term ID.
	 * @return string
	 */
	public function path_of( int $term_id ): string {
		$term = get_term( $term_id, self::TAXONOMY );

		if ( ! $term instanceof \WP_Term ) {
			return '';
		}

		$slugs = array( $term->slug );

		foreach ( get_ancestors( $term_id, self::TAXONOMY, 'taxonomy' ) as $ancestor ) {
			$parent = get_term( (int) $ancestor, self::TAXONOMY );

			if ( $parent instanceof \WP_Term ) {
				array_unshift( $slugs, $parent->slug );
			}
		}

		return implode( '/', $slugs );
	}

	/**
	 * Every category term.
	 *
	 * @return array<int,\WP_Term>
	 */
	public function all_terms(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
				'orderby'    => 'term_id',
			)
		);

		return is_array( $terms ) ? array_values( array_filter( $terms, static fn( mixed $term ): bool => $term instanceof \WP_Term ) ) : array();
	}

	/**
	 * Whether a category is visible. A category with no stored value is visible.
	 *
	 * @param int $term_id Term ID.
	 * @return bool
	 */
	public function is_visible( int $term_id ): bool {
		if ( ! metadata_exists( 'term', $term_id, '_rj_visible' ) ) {
			return true;
		}

		$value = get_term_meta( $term_id, '_rj_visible', true );

		return ! in_array( $value, array( '', '0', 0, false ), true );
	}

	/**
	 * Whether a category carries a resolver value.
	 *
	 * @param int $term_id Term ID.
	 * @return bool
	 */
	public function has_resolver( int $term_id ): bool {
		return ! in_array( get_term_meta( $term_id, '_rj_resolver', true ), array( '', array(), null, false ), true );
	}
}
