<?php
/**
 * Product persistence.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Product;

/**
 * Persistence only. No category rules, no HTML, no REST formatting and no
 * hard delete. Rules live in ProductService; WordPress write interception
 * lives in ProductGuard.
 *
 * @phpstan-type ProductState array{id: int, status: string, code: string, name_en: string, name_hi: string, visibility: string, metal: array<int,int>, purity: array<int,int>, categories: array<int,int>, primary: int}
 */
final class ProductRepository {

	public const TYPE = 'rj_product';

	private const META = array(
		'code'          => '_rj_code',
		'name_en'       => '_rj_name_en',
		'name_hi'       => '_rj_name_hi',
		'weight'        => '_rj_weight',
		'weight_unit'   => '_rj_weight_unit',
		'visibility'    => '_rj_visibility',
		'featured'      => '_rj_featured',
		'stone_details' => '_rj_stone_details',
		'gallery'       => '_rj_gallery',
		'primary_term'  => '_rj_primary_term',
	);

	private const TAXONOMY = array(
		'categories' => 'rj_category',
		'metal'      => 'rj_metal',
		'purity'     => 'rj_purity',
	);

	/**
	 * Inserts a product as a draft. Publication is a later update, after
	 * its terms and meta exist, so no half-built product is ever published.
	 *
	 * @param ProductData $data Input.
	 * @return int|\WP_Error
	 */
	public function create( ProductData $data ): int|\WP_Error {
		$id = wp_insert_post(
			array(
				'post_type'   => self::TYPE,
				'post_status' => 'draft',
				'post_title'  => (string) $data->get( 'title', '' ),
			),
			true
		);

		if ( $id instanceof \WP_Error ) {
			return $id;
		}

		$this->write( $id, $data );

		return $id;
	}

	/**
	 * Updates only the supplied fields.
	 *
	 * @param int         $id   Product ID.
	 * @param ProductData $data Input.
	 * @return true|\WP_Error
	 */
	public function update( int $id, ProductData $data ): bool|\WP_Error {
		$post = array( 'ID' => $id );

		if ( $data->has( 'title' ) ) {
			$post['post_title'] = (string) $data->get( 'title' );
		}

		if ( $data->has( 'status' ) ) {
			$post['post_status'] = (string) $data->get( 'status' );
		}

		$this->write( $id, $data );

		if ( count( $post ) > 1 ) {
			$result = wp_update_post( $post, true );

			if ( $result instanceof \WP_Error ) {
				return $result;
			}
		}

		return true;
	}

	/**
	 * The product, or null.
	 *
	 * @param int $id Product ID.
	 * @return array<string, mixed>|null ProductState shape.
	 */
	public function find( int $id ): ?array {
		$post = get_post( $id );

		if ( ! $post instanceof \WP_Post || self::TYPE !== $post->post_type ) {
			return null;
		}

		$visibility = (string) get_post_meta( $id, '_rj_visibility', true );

		return array(
			'id'         => $id,
			'status'     => $post->post_status,
			'code'       => (string) get_post_meta( $id, '_rj_code', true ),
			'name_en'    => (string) get_post_meta( $id, '_rj_name_en', true ),
			'name_hi'    => (string) get_post_meta( $id, '_rj_name_hi', true ),
			'visibility' => '' === $visibility ? 'public' : $visibility,
			'metal'      => $this->assigned_categories( $id, 'rj_metal' ),
			'purity'     => $this->assigned_categories( $id, 'rj_purity' ),
			'categories' => $this->assigned_categories( $id ),
			'primary'    => (int) get_post_meta( $id, '_rj_primary_term', true ),
		);
	}

	/**
	 * The product with this code in any status, trash included.
	 *
	 * @param string $code Raw or normalised code.
	 * @return array<string, mixed>|null
	 */
	public function find_by_code( string $code ): ?array {
		$ids = $this->ids_for_code( ProductCode::normalise( $code ), null );

		return array() === $ids ? null : $this->find( $ids[0] );
	}

	/**
	 * The lowest product ID holding the code in any status, or null.
	 *
	 * @param string $code Raw or normalised code.
	 * @return int|null
	 */
	public function first_id_for_code( string $code ): ?int {
		$ids = $this->ids_for_code( ProductCode::normalise( $code ), null );

		return array() === $ids ? null : $ids[0];
	}

	/**
	 * Whether another product holds the code, in any status.
	 *
	 * @param string   $code   Code.
	 * @param int|null $except Product to ignore.
	 * @return bool
	 */
	public function code_exists( string $code, ?int $except = null ): bool {
		$normal = ProductCode::normalise( $code );

		return '' !== $normal && array() !== $this->ids_for_code( $normal, $except );
	}

	/**
	 * Moves to trash. Trash does not release the code.
	 *
	 * @param int $id Product ID.
	 * @return bool
	 */
	public function trash( int $id ): bool {
		wp_trash_post( $id );

		return 'trash' === get_post_status( $id );
	}

	/**
	 * Writes _rj_visibility.
	 *
	 * @param int    $id         Product ID.
	 * @param string $visibility public, hidden or archived.
	 * @return void
	 */
	public function set_visibility( int $id, string $visibility ): void {
		update_post_meta( $id, '_rj_visibility', $visibility );
	}

	/**
	 * Term IDs assigned in a taxonomy.
	 *
	 * @param int    $id       Product ID.
	 * @param string $taxonomy Taxonomy.
	 * @return array<int, int>
	 */
	public function assigned_categories( int $id, string $taxonomy = 'rj_category' ): array {
		$ids = wp_get_object_terms( $id, $taxonomy, array( 'fields' => 'ids' ) );

		return $ids instanceof \WP_Error ? array() : array_map( 'intval', $ids );
	}

	/**
	 * Replaces the terms in a taxonomy. Validation happens before this call.
	 *
	 * @param int        $id       Product ID.
	 * @param array<int> $term_ids Term IDs.
	 * @param string     $taxonomy Taxonomy.
	 * @return void
	 */
	public function set_categories( int $id, array $term_ids, string $taxonomy = 'rj_category' ): void {
		wp_set_object_terms( $id, $term_ids, $taxonomy );
	}

	/**
	 * Writes the supplied meta and terms.
	 *
	 * @param int         $id   Product ID.
	 * @param ProductData $data Input.
	 * @return void
	 */
	private function write( int $id, ProductData $data ): void {
		foreach ( self::META as $field => $key ) {
			if ( $data->has( $field ) ) {
				update_post_meta( $id, $key, $data->get( $field ) );
			}
		}

		foreach ( self::TAXONOMY as $field => $taxonomy ) {
			if ( $data->has( $field ) ) {
				$this->set_categories( $id, array_map( 'intval', (array) $data->get( $field ) ), $taxonomy );
			}
		}
	}

	/**
	 * Product IDs holding a normalised code, in any status.
	 *
	 * @param string   $code   Normalised code.
	 * @param int|null $except Product to ignore.
	 * @return array<int, int>
	 */
	private function ids_for_code( string $code, ?int $except ): array {
		if ( '' === $code ) {
			return array();
		}

		$ids = get_posts(
			array(
				'post_type'      => self::TYPE,
				'post_status'    => array( 'publish', 'future', 'draft', 'pending', 'private', 'trash' ),
				'fields'         => 'ids',
				'posts_per_page' => 2,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'rj_internal'    => true,
				'post__not_in'   => null === $except ? array() : array( $except ),
				'meta_key'       => '_rj_code', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Identity lookup by Product Code.
				'meta_value'     => $code, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Identity lookup by Product Code.
			)
		);

		return array_map( 'intval', $ids );
	}
}
