<?php
/**
 * Category picker meta box.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Category;

use Rameshwari\Core\Container;
use Rameshwari\Core\Data\Taxonomies;
use Rameshwari\Core\Module;

/**
 * Puts the category tree picker on the product editor and saves the choice.
 *
 * Saving checks the nonce and the user's right to edit that product, then
 * hands the submitted paths to CategoryPicker, which re-checks them with
 * AssignmentGuard. A rejected choice assigns nothing.
 */
final class CategoryPickerBox implements Module {

	private const NONCE_ACTION = 'rj_category_picker';

	private const NONCE_FIELD = 'rj_category_picker_nonce';

	private const FIELD = 'rj_category_paths';

	/**
	 * Module id.
	 *
	 * @return string
	 */
	public static function id(): string {
		return 'category-picker';
	}

	/**
	 * Needs the taxonomies.
	 *
	 * @return array<int,string>
	 */
	public static function requires(): array {
		return array( Taxonomies::id() );
	}

	/**
	 * Adds the hooks.
	 *
	 * @param Container $container Shared container.
	 * @return void
	 */
	public function register( Container $container ): void {
		add_action( 'add_meta_boxes_rj_product', array( self::class, 'add_box' ) );
		add_action( 'save_post_rj_product', array( self::class, 'save' ) );
		add_action( 'admin_notices', array( self::class, 'notices' ) );
	}

	/**
	 * Adds the meta box.
	 *
	 * @return void
	 */
	public static function add_box(): void {
		add_meta_box( 'rj-category-picker', 'Categories', array( self::class, 'render_box' ), 'rj_product', 'side', 'default' );
	}

	/**
	 * Prints the meta box.
	 *
	 * @param \WP_Post $post Product.
	 * @return void
	 */
	public static function render_box( \WP_Post $post ): void {
		$repository = new CategoryRepository();
		$ids        = wp_get_object_terms( $post->ID, CategoryRepository::TAXONOMY, array( 'fields' => 'ids' ) );
		$selected   = array();

		foreach ( is_array( $ids ) ? $ids : array() as $id ) {
			$selected[] = $repository->path_of( (int) $id );
		}

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		echo self::render( self::picker( $repository ), $selected ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from esc_html() and esc_attr() parts.
	}

	/**
	 * The picker markup. Every value is escaped; roots and navigation terms have no checkbox.
	 *
	 * @param CategoryPicker    $picker   Picker.
	 * @param array<int,string> $selected Canonical paths already assigned.
	 * @return string
	 */
	public static function render( CategoryPicker $picker, array $selected ): string {
		$html = '<ul class="rj-category-picker" style="list-style:none;margin:0;padding:0">';

		foreach ( $picker->options() as $option ) {
			$indent = esc_attr( (string) ( ( $option['depth'] - 1 ) * 16 ) );
			$label  = esc_html( $option['label'] );

			if ( $option['selectable'] ) {
				$checked = in_array( $option['path'], $selected, true ) ? ' checked="checked"' : '';
				$html   .= '<li style="margin-left:' . $indent . 'px"><label title="' . esc_attr( $option['trail'] ) . '">'
					. '<input type="checkbox" name="' . esc_attr( self::FIELD ) . '[]" value="' . esc_attr( $option['path'] ) . '"' . $checked . ' /> '
					. $label . '</label></li>';
			} else {
				$html .= '<li style="margin-left:' . $indent . 'px"><strong>' . $label . '</strong></li>';
			}
		}

		return $html . '</ul>';
	}

	/**
	 * Saves the choice from the product editor.
	 *
	 * @param int $post_id Product ID.
	 * @return void
	 */
	public static function save( int $post_id ): void {
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		$nonce = isset( $_POST[ self::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$raw = isset( $_POST[ self::FIELD ] ) && is_array( $_POST[ self::FIELD ] ) ? wp_unslash( $_POST[ self::FIELD ] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each path is sanitised on the next line and validated by CategoryPicker.

		$violations = self::apply( $post_id, array_map( 'sanitize_text_field', array_map( 'strval', $raw ) ) );

		if ( array() !== $violations ) {
			set_transient( 'rj_picker_errors_' . get_current_user_id(), $violations, 60 );
		}
	}

	/**
	 * Assigns the chosen categories, or assigns nothing and returns why not.
	 *
	 * @param int               $post_id Product ID.
	 * @param array<int,string> $paths   Canonical paths.
	 * @return array<int,string> Violations; empty when saved.
	 */
	public static function apply( int $post_id, array $paths ): array {
		$result = self::picker( new CategoryRepository() )->resolve( $paths );

		if ( array() !== $result['violations'] ) {
			return $result['violations'];
		}

		wp_set_object_terms( $post_id, $result['ids'], CategoryRepository::TAXONOMY );

		return array();
	}

	/**
	 * Shows a rejected choice once.
	 *
	 * @return void
	 */
	public static function notices(): void {
		$key    = 'rj_picker_errors_' . get_current_user_id();
		$errors = get_transient( $key );

		if ( ! is_array( $errors ) ) {
			return;
		}

		delete_transient( $key );

		foreach ( $errors as $error ) {
			echo '<div class="notice notice-error"><p>' . esc_html( (string) $error ) . '</p></div>';
		}
	}

	/**
	 * A picker over a repository.
	 *
	 * @param CategoryRepository $repository Repository.
	 * @return CategoryPicker
	 */
	private static function picker( CategoryRepository $repository ): CategoryPicker {
		return new CategoryPicker( $repository, new AssignmentGuard( $repository, new ResolverEngine( $repository ) ) );
	}
}
