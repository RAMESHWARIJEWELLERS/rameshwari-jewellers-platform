<?php
/**
 * Post type registry.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Data;

use Rameshwari\Core\Container;
use Rameshwari\Core\Module;

/**
 * Registers the six post types of Development Blueprint §6.1 and §6.2 on
 * init priority 5, before anything that queries them.
 *
 * Every post type keeps its approved capability_type and map_meta_cap, and
 * maps the primitive capabilities WordPress derives from it onto the
 * existing capability map (Platform Architecture §1.2), so no new
 * capability is created. Hard delete is administrator-only where §6.1 says
 * so.
 */
final class PostTypes implements Module {

	public const PRIORITY = 5;

	/**
	 * The approved registry, keyed by post type.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function definitions(): array {
		return array(
			'rj_product'      => array(
				'labels'            => self::labels( 'Jewellery', 'Product' ),
				'public'            => true,
				'has_archive'       => 'jewellery',
				'rewrite'           => array(
					'slug'       => 'product',
					'with_front' => false,
				),
				'hierarchical'      => false,
				'show_in_rest'      => true,
				'rest_base'         => 'products',
				'capability_type'   => array( 'rj_product', 'rj_products' ),
				'map_meta_cap'      => true,
				'capabilities'      => self::caps( 'rj_manage_catalogue', 'manage_options' ),
				'menu_icon'         => 'dashicons-awards',
				'show_in_nav_menus' => true,
				'supports'          => array( 'title', 'editor', 'thumbnail', 'excerpt', 'revisions', 'custom-fields', 'page-attributes' ),
			),
			'rj_reel'         => array(
				'labels'            => self::labels( 'Reels', 'Reel' ),
				'public'            => true,
				'has_archive'       => 'reels',
				'rewrite'           => array(
					'slug'       => 'reel',
					'with_front' => false,
				),
				'hierarchical'      => false,
				'show_in_rest'      => true,
				'rest_base'         => 'reels',
				'capability_type'   => array( 'rj_reel', 'rj_reels' ),
				'map_meta_cap'      => true,
				'capabilities'      => self::caps( 'rj_manage_reels', 'rj_manage_reels' ),
				'menu_icon'         => 'dashicons-video-alt3',
				'show_in_nav_menus' => true,
				'supports'          => array( 'title', 'thumbnail', 'excerpt', 'revisions', 'custom-fields' ),
			),
			'rj_collection'   => array(
				'labels'            => self::labels( 'Collections', 'Collection' ),
				'public'            => true,
				'has_archive'       => 'collections',
				'rewrite'           => array(
					'slug'       => 'collection',
					'with_front' => false,
				),
				'hierarchical'      => false,
				'show_in_rest'      => true,
				'rest_base'         => 'collections',
				'capability_type'   => array( 'rj_collection', 'rj_collections' ),
				'map_meta_cap'      => true,
				'capabilities'      => self::caps( 'rj_manage_catalogue', 'rj_manage_catalogue' ),
				'menu_icon'         => 'dashicons-portfolio',
				'show_in_nav_menus' => true,
				'supports'          => array( 'title', 'editor', 'thumbnail', 'revisions', 'custom-fields' ),
			),
			'rj_showroom'     => array(
				'labels'            => self::labels( 'Showrooms', 'Showroom' ),
				'public'            => true,
				'has_archive'       => 'showrooms',
				'rewrite'           => array(
					'slug'       => 'showroom',
					'with_front' => false,
				),
				'hierarchical'      => false,
				'show_in_rest'      => true,
				'rest_base'         => 'showrooms',
				'capability_type'   => array( 'rj_showroom', 'rj_showrooms' ),
				'map_meta_cap'      => true,
				'capabilities'      => self::caps( 'rj_manage_showrooms', 'manage_options' ),
				'menu_icon'         => 'dashicons-store',
				'show_in_nav_menus' => true,
				'supports'          => array( 'title', 'editor', 'thumbnail', 'revisions', 'custom-fields' ),
			),
			'rj_testimonial'  => array(
				'labels'            => self::labels( 'Testimonials', 'Testimonial' ),
				'public'            => false,
				'show_ui'           => true,
				'has_archive'       => false,
				'rewrite'           => false,
				'hierarchical'      => false,
				'show_in_rest'      => true,
				'rest_base'         => 'testimonials',
				'capability_type'   => array( 'rj_testimonial', 'rj_testimonials' ),
				'map_meta_cap'      => true,
				'capabilities'      => self::caps( 'rj_manage_catalogue', 'rj_manage_catalogue' ),
				'menu_icon'         => 'dashicons-format-quote',
				'show_in_nav_menus' => false,
				'supports'          => array( 'title', 'editor', 'thumbnail', 'custom-fields' ),
			),
			'rj_page_section' => array(
				'labels'            => self::labels( 'Page Sections', 'Page Section' ),
				'public'            => false,
				'show_ui'           => true,
				'has_archive'       => false,
				'rewrite'           => false,
				'hierarchical'      => false,
				'show_in_rest'      => true,
				'rest_base'         => 'page-sections',
				'capability_type'   => array( 'rj_section', 'rj_sections' ),
				'map_meta_cap'      => true,
				'capabilities'      => self::caps( 'rj_manage_settings', 'rj_manage_settings' ),
				'menu_icon'         => 'dashicons-cover-image',
				'show_in_nav_menus' => false,
				'supports'          => array( 'title', 'thumbnail', 'revisions', 'custom-fields', 'page-attributes' ),
			),
		);
	}

	/**
	 * Module id.
	 *
	 * @return string
	 */
	public static function id(): string {
		return 'post_types';
	}

	/**
	 * No dependencies.
	 *
	 * @return array<int, string>
	 */
	public static function requires(): array {
		return array();
	}

	/**
	 * Hooks registration to init.
	 *
	 * @param Container $container Shared container.
	 * @return void
	 */
	public function register( Container $container ): void {
		add_action( 'init', array( self::class, 'register_all' ), self::PRIORITY );
	}

	/**
	 * Registers every post type not already registered.
	 *
	 * @return void
	 */
	public static function register_all(): void {
		foreach ( self::definitions() as $post_type => $args ) {
			if ( ! post_type_exists( $post_type ) ) {
				register_post_type( $post_type, $args );
			}
		}
	}

	/**
	 * Plural and singular labels. WordPress derives the rest.
	 *
	 * @param string $plural   Admin label.
	 * @param string $singular Singular name.
	 * @return array<string, string>
	 */
	private static function labels( string $plural, string $singular ): array {
		return array(
			'name'          => $plural,
			'singular_name' => $singular,
			'menu_name'     => $plural,
		);
	}

	/**
	 * Maps the primitive capabilities onto the existing capability map.
	 *
	 * @param string $manage Capability that edits and publishes.
	 * @param string $delete Capability that deletes.
	 * @return array<string, string>
	 */
	private static function caps( string $manage, string $delete ): array {
		return array(
			'create_posts'           => $manage,
			'edit_posts'             => $manage,
			'edit_others_posts'      => $manage,
			'edit_private_posts'     => $manage,
			'edit_published_posts'   => $manage,
			'publish_posts'          => $manage,
			'read_private_posts'     => $manage,
			'delete_posts'           => $delete,
			'delete_others_posts'    => $delete,
			'delete_private_posts'   => $delete,
			'delete_published_posts' => $delete,
			'read'                   => 'read',
		);
	}
}
