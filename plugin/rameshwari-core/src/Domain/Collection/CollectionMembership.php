<?php
/**
 * Collection membership.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Domain\Collection;

/**
 * Manual membership of products and reels in a collection link term.
 *
 * Membership is plain rj_collection_tax taxonomy membership. Nothing here writes to
 * the collection post, its meta, an option or a table, and no member list is stored
 * anywhere. Which link term belongs to which rj_collection post is not defined by the
 * architecture, so every method takes the link term ID from the caller. Automatic
 * rules (_rj_auto_rule) are not applied here.
 */
final class CollectionMembership {

	public const TAXONOMY = 'rj_collection_tax';

	public const MAX_PAGE = 100;

	/**
	 * Post types that can be members.
	 *
	 * @var array<int,string>
	 */
	public const TYPES = array( 'rj_product', 'rj_reel' );

	/**
	 * Puts an object in a collection link term. Repeating it changes nothing.
	 *
	 * @param int $object_id Product or reel ID.
	 * @param int $term_id   rj_collection_tax term ID.
	 * @return bool Whether the object is a member afterwards.
	 */
	public function assign( int $object_id, int $term_id ): bool {
		$this->check( $object_id, $term_id );

		$result = wp_set_object_terms( $object_id, array( $term_id ), self::TAXONOMY, true );

		return ! is_wp_error( $result ) && $this->is_member( $object_id, $term_id );
	}

	/**
	 * Takes an object out of a collection link term. Removing a non-member changes nothing.
	 *
	 * @param int $object_id Product or reel ID.
	 * @param int $term_id   rj_collection_tax term ID.
	 * @return bool Whether the object is not a member afterwards.
	 */
	public function remove( int $object_id, int $term_id ): bool {
		$this->check( $object_id, $term_id );

		$result = wp_remove_object_terms( $object_id, $term_id, self::TAXONOMY );

		return ! is_wp_error( $result ) && ! $this->is_member( $object_id, $term_id );
	}

	/**
	 * Whether an object is in a link term. Anything unsupported or unknown is simply not a member.
	 *
	 * @param int $object_id Object ID.
	 * @param int $term_id   rj_collection_tax term ID.
	 * @return bool
	 */
	public function is_member( int $object_id, int $term_id ): bool {
		if ( ! $this->supported( (string) get_post_type( $object_id ) ) || ! $this->term_exists( $term_id ) ) {
			return false;
		}

		return (bool) has_term( $term_id, self::TAXONOMY, $object_id );
	}

	/**
	 * Published members of one type, oldest ID first, in a bounded page.
	 *
	 * @param int    $term_id rj_collection_tax term ID.
	 * @param string $type    rj_product or rj_reel.
	 * @param int    $limit   Page size, 1 to MAX_PAGE.
	 * @param int    $offset  Members to skip.
	 * @return array<int,int>
	 */
	public function members( int $term_id, string $type, int $limit = self::MAX_PAGE, int $offset = 0 ): array {
		if ( $limit < 1 || $offset < 0 || ! $this->usable( $term_id, $type ) ) {
			return array();
		}

		$query = $this->query( $term_id, $type, min( $limit, self::MAX_PAGE ), $offset, true );

		return array_values( array_filter( $query->posts, 'is_int' ) );
	}

	/**
	 * How many published members of one type a link term has.
	 *
	 * @param int    $term_id rj_collection_tax term ID.
	 * @param string $type    rj_product or rj_reel.
	 * @return int
	 */
	public function count( int $term_id, string $type ): int {
		if ( ! $this->usable( $term_id, $type ) ) {
			return 0;
		}

		return (int) $this->query( $term_id, $type, 1, 0, false )->found_posts;
	}

	/**
	 * Refuses unsupported objects and unknown or foreign terms before anything is written.
	 *
	 * @param int $object_id Object ID.
	 * @param int $term_id   Term ID.
	 * @return void
	 * @throws \InvalidArgumentException When the object or the term cannot take part.
	 */
	private function check( int $object_id, int $term_id ): void {
		if ( ! $this->supported( (string) get_post_type( $object_id ) ) ) {
			throw new \InvalidArgumentException( 'Only a product or a reel can be a collection member.' );
		}

		if ( ! $this->term_exists( $term_id ) ) {
			throw new \InvalidArgumentException( 'The collection link term does not exist.' );
		}
	}

	/**
	 * Whether a post type can be a member.
	 *
	 * @param string $type Post type.
	 * @return bool
	 */
	private function supported( string $type ): bool {
		return in_array( $type, self::TYPES, true );
	}

	/**
	 * Whether the type is supported and the term is a real collection link term.
	 *
	 * @param int    $term_id Term ID.
	 * @param string $type    Post type.
	 * @return bool
	 */
	private function usable( int $term_id, string $type ): bool {
		return $this->supported( $type ) && $this->term_exists( $term_id );
	}

	/**
	 * Whether the ID is a term of the collection link taxonomy.
	 *
	 * @param int $term_id Term ID.
	 * @return bool
	 */
	private function term_exists( int $term_id ): bool {
		return $term_id > 0 && get_term( $term_id, self::TAXONOMY ) instanceof \WP_Term;
	}

	/**
	 * The taxonomy query that backs both listing and counting.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $type     Post type.
	 * @param int    $per_page Page size.
	 * @param int    $offset   Offset.
	 * @param bool   $ids_only Whether the totals are skipped.
	 * @return \WP_Query
	 */
	private function query( int $term_id, string $type, int $per_page, int $offset, bool $ids_only ): \WP_Query {
		$taxonomy = array(
			array(
				'taxonomy' => self::TAXONOMY,
				'field'    => 'term_id',
				'terms'    => array( $term_id ),
			),
		);

		return new \WP_Query(
			array(
				'post_type'           => $type,
				'post_status'         => 'publish',
				'fields'              => 'ids',
				'orderby'             => 'ID',
				'order'               => 'ASC',
				'posts_per_page'      => $per_page,
				'offset'              => $offset,
				'no_found_rows'       => $ids_only,
				'ignore_sticky_posts' => true,
				'tax_query'           => $taxonomy, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Membership is taxonomy membership; this query is the intended, bounded way to read it.
			)
		);
	}
}
