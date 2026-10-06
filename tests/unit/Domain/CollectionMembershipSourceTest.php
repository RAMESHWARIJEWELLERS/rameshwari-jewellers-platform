<?php
/**
 * Collection membership source checks.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;

/**
 * The membership class must not store anything and must use taxonomy APIs.
 */
final class CollectionMembershipSourceTest extends TestCase {

	/**
	 * Source text of the class.
	 *
	 * @return string
	 */
	private function source(): string {
		$path = dirname( __DIR__, 3 ) . '/plugin/rameshwari-core/src/Domain/Collection/CollectionMembership.php';

		$this->assertFileExists( $path );

		return (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source file; no remote URL is involved.
	}

	/**
	 * Nothing is persisted outside the taxonomy.
	 */
	public function test_nothing_is_stored(): void {
		$code = $this->source();

		foreach ( array( 'update_post_meta(', 'add_post_meta(', 'delete_post_meta(', 'update_term_meta(', 'add_term_meta(', 'update_option(', 'add_option(', 'set_transient(', 'dbDelta(', '$wpdb', 'register_post_meta(', 'register_taxonomy(', 'register_post_type(', 'add_cap(' ) as $forbidden ) {
			$this->assertStringNotContainsString( $forbidden, $code, $forbidden );
		}
	}

	/**
	 * Membership is read and written through taxonomy APIs, never meta.
	 */
	public function test_membership_uses_taxonomy_apis(): void {
		$code = $this->source();

		foreach ( array( 'wp_set_object_terms(', 'wp_remove_object_terms(', 'has_term(', "'tax_query'", 'rj_collection_tax' ) as $required ) {
			$this->assertStringContainsString( $required, $code, $required );
		}

		$this->assertStringNotContainsString( 'meta_query', $code );
		$this->assertStringNotContainsString( 'get_post_meta(', $code );
	}

	/**
	 * There is no reel-type taxonomy and no REST, admin or theme code.
	 */
	public function test_no_forbidden_scope(): void {
		$code = $this->source();

		foreach ( array( 'rj_reel_type', 'register_rest_route', 'add_menu_page', 'add_action(', 'add_filter(', 'Elementor' ) as $forbidden ) {
			$this->assertStringNotContainsString( $forbidden, $code, $forbidden );
		}
	}

	/**
	 * Only products and reels can be members.
	 */
	public function test_supported_types_are_fixed(): void {
		$this->assertSame( array( 'rj_product', 'rj_reel' ), \Rameshwari\Core\Domain\Collection\CollectionMembership::TYPES );
	}
}
