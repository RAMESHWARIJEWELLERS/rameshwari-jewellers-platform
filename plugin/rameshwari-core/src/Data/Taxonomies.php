<?php
/**
 * Taxonomy registry.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Data;

use Rameshwari\Core\Container;
use Rameshwari\Core\Module;

/**
 * Registers the seven taxonomies of Development Blueprint §7.1 on init
 * Priority 5, after the post types.
 *
 * The rj_category has no core meta box and no quick edit: its tree manager and
 * picker arrive in Stage 5. Until then terms are managed from the term
 * screen.
 */
final class Taxonomies implements Module {

	/**
	 * The approved registry, keyed by taxonomy.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function definitions(): array {
		$catalogue = array(
			'manage_terms' => 'rj_manage_catalogue',
			'edit_terms'   => 'rj_manage_catalogue',
			'delete_terms' => 'rj_manage_catalogue',
			'assign_terms' => 'rj_manage_catalogue',
		);

		return array(
			'rj_category'       => array(
				'objects' => array( 'rj_product', 'rj_reel' ),
				'args'    => array(
					'labels'             => self::labels( 'Categories', 'Category' ),
					'hierarchical'       => true,
					'public'             => true,
					'show_in_rest'       => true,
					'rest_base'          => 'rj-categories',
					'show_admin_column'  => true,
					'show_in_quick_edit' => false,
					'meta_box_cb'        => false,
					'sort'               => true,
					'rewrite'            => array(
						'slug'         => 'c',
						'hierarchical' => true,
						'with_front'   => false,
					),
					'capabilities'       => array(
						'manage_terms' => 'rj_manage_categories',
						'edit_terms'   => 'rj_manage_categories',
						'delete_terms' => 'rj_manage_categories',
						'assign_terms' => 'rj_manage_catalogue',
					),
				),
			),
			'rj_metal'          => self::flat( array( 'rj_product' ), 'Metals', 'Metal', 'metal', 'metals', $catalogue ),
			'rj_purity'         => self::flat( array( 'rj_product' ), 'Purity', 'Purity', 'purity', 'purity', $catalogue ),
			'rj_occasion'       => self::flat( array( 'rj_product', 'rj_reel', 'rj_collection' ), 'Occasions', 'Occasion', 'occasion', 'occasions', $catalogue ),
			'rj_audience'       => self::flat( array( 'rj_product' ), 'Audience', 'Audience', 'for', 'audience', $catalogue ),
			'rj_collection_tax' => array(
				'objects' => array( 'rj_product', 'rj_reel' ),
				'args'    => array(
					'labels'            => self::labels( 'Collection links', 'Collection link' ),
					'hierarchical'      => false,
					'public'            => false,
					'show_ui'           => false,
					'show_in_rest'      => true,
					'rest_base'         => 'collection-links',
					'rewrite'           => false,
					'show_admin_column' => false,
					'capabilities'      => $catalogue,
				),
			),
			'rj_tag'            => self::flat( array( 'rj_product', 'rj_reel' ), 'Jewellery tags', 'Jewellery tag', 'tag-j', 'jewellery-tags', $catalogue ),
		);
	}

	/**
	 * Module id.
	 *
	 * @return string
	 */
	public static function id(): string {
		return 'taxonomies';
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
	 * Hooks registration to init, after the post types at the same priority.
	 *
	 * @param Container $container Shared container.
	 * @return void
	 */
	public function register( Container $container ): void {
		add_action( 'init', array( self::class, 'register_all' ), PostTypes::PRIORITY );
		add_action( 'created_rj_category', array( self::class, 'set_category_visible_default' ), 10, 3 );
	}

	/**
	 * Gives newly created categories their approved visible-by-default value.
	 *
	 * @param int                  $term_id Term ID.
	 * @param int                  $tt_id   Term taxonomy ID.
	 * @param array<string, mixed> $args    Arguments passed to wp_insert_term().
	 * @return void
	 */
	public static function set_category_visible_default( int $term_id, int $tt_id, array $args ): void {
		if ( array_key_exists( '_rj_visible', $args ) ) {
			update_term_meta( $term_id, '_rj_visible', $args['_rj_visible'] );
			return;
		}

		if ( isset( $args['meta_input'] ) && is_array( $args['meta_input'] ) && array_key_exists( '_rj_visible', $args['meta_input'] ) ) {
			update_term_meta( $term_id, '_rj_visible', $args['meta_input']['_rj_visible'] );
			return;
		}

		if ( ! metadata_exists( 'term', $term_id, '_rj_visible' ) ) {
			add_term_meta( $term_id, '_rj_visible', true, true );
		}
	}

	/**
	 * Registers every taxonomy not already registered.
	 *
	 * @return void
	 */
	public static function register_all(): void {
		foreach ( self::definitions() as $taxonomy => $definition ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				register_taxonomy( $taxonomy, $definition['objects'], $definition['args'] );
			}
		}
	}

	/**
	 * A standard flat taxonomy.
	 *
	 * @param array<int, string>    $objects      Post types.
	 * @param string                $plural       Plural label.
	 * @param string                $singular     Singular label.
	 * @param string                $slug         Rewrite slug.
	 * @param string                $rest_base    REST base.
	 * @param array<string, string> $capabilities Term capabilities.
	 * @return array<string, mixed>
	 */
	private static function flat( array $objects, string $plural, string $singular, string $slug, string $rest_base, array $capabilities ): array {
		return array(
			'objects' => $objects,
			'args'    => array(
				'labels'            => self::labels( $plural, $singular ),
				'hierarchical'      => false,
				'public'            => true,
				'show_in_rest'      => true,
				'rest_base'         => $rest_base,
				'show_admin_column' => true,
				'rewrite'           => array(
					'slug'       => $slug,
					'with_front' => false,
				),
				'capabilities'      => $capabilities,
			),
		);
	}

	/**
	 * Plural and singular labels.
	 *
	 * @param string $plural   Plural.
	 * @param string $singular Singular.
	 * @return array<string, string>
	 */
	private static function labels( string $plural, string $singular ): array {
		return array(
			'name'          => $plural,
			'singular_name' => $singular,
		);
	}
}
