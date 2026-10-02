<?php
/**
 * Stage 6 correction pass: M-4a (term removal on a published product) and
 * M-6 (admin-ajax style queries must obey H-1).
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Integration;

use Rameshwari\Core\Data\Capabilities;
use Rameshwari\Core\Product\ProductData;
use Rameshwari\Core\Product\ProductModule;
use WP_Query;
use WP_UnitTestCase;

/**
 * Each test runs the real WordPress term and query APIs.
 */
final class ProductMutationAndAjaxTest extends WP_UnitTestCase {

	/**
	 * Restores the plugin registry for each test.
	 */
	public function set_up(): void {
		parent::set_up();
		do_action( 'init' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Exercises the plugin's normal init registration.
		Capabilities::apply();
		rest_get_server();
		wp_set_current_user( 0 );
	}

	/**
	 * A fresh term.
	 *
	 * @param string $taxonomy    Taxonomy.
	 * @param int    $parent_term Parent term.
	 * @return int
	 */
	private function term( string $taxonomy, int $parent_term = 0 ): int {
		return (int) self::factory()->term->create(
			array(
				'taxonomy' => $taxonomy,
				'name'     => 'T' . wp_generate_password( 8, false ),
				'parent'   => $parent_term,
			)
		);
	}

	/**
	 * A complete product with the given number of categories.
	 *
	 * @param string $code       Product Code.
	 * @param int    $categories Number of editorial categories.
	 * @param string $visibility public, hidden or archived.
	 * @param int    $category   Shared category term, or 0 to create new ones.
	 * @param string $status     draft or publish.
	 * @return array{id:int,metal:int,purity:int,cats:array<int,int>}
	 */
	private function make( string $code, int $categories = 1, string $visibility = 'public', int $category = 0, string $status = 'publish' ): array {
		$metal  = $this->term( 'rj_metal' );
		$purity = $this->term( 'rj_purity' );
		$cats   = array();

		if ( $category > 0 ) {
			$cats[] = $category;
		} else {
			for ( $i = 0; $i < $categories; $i++ ) {
				$cats[] = $this->term( 'rj_category', $this->term( 'rj_category' ) );
			}
		}

		$id = ProductModule::service()->save(
			ProductData::from_array(
				array(
					'code'       => $code,
					'name_en'    => 'Ring ' . $code,
					'name_hi'    => 'अंगूठी',
					'metal'      => array( $metal ),
					'purity'     => array( $purity ),
					'categories' => $cats,
					'visibility' => $visibility,
					'status'     => $status,
				)
			)
		);
		$this->assertIsInt( $id );

		return array(
			'id'     => $id,
			'metal'  => $metal,
			'purity' => $purity,
			'cats'   => $cats,
		);
	}

	/**
	 * Term IDs currently assigned to a product.
	 *
	 * @param int    $id       Product ID.
	 * @param string $taxonomy Taxonomy.
	 * @return array<int, int>
	 */
	private function assigned( int $id, string $taxonomy ): array {
		return array_map( 'intval', (array) wp_get_object_terms( $id, $taxonomy, array( 'fields' => 'ids' ) ) );
	}

	/**
	 * M-4a: the only Metal of a published product cannot be removed.
	 */
	public function test_removing_the_only_metal_of_a_published_product_is_reverted(): void {
		$p = $this->make( 'M4A-METAL' );

		wp_remove_object_terms( $p['id'], $p['metal'], 'rj_metal' );

		$this->assertSame( array( $p['metal'] ), $this->assigned( $p['id'], 'rj_metal' ) );
		$this->assertSame( 'publish', get_post_status( $p['id'] ) );
		$this->assertSame( array(), ProductModule::service()->publication_gaps( $p['id'] ) );
	}

	/**
	 * M-4a: the only Purity of a published product cannot be removed.
	 */
	public function test_removing_the_only_purity_of_a_published_product_is_reverted(): void {
		$p = $this->make( 'M4A-PURITY' );

		wp_remove_object_terms( $p['id'], $p['purity'], 'rj_purity' );

		$this->assertSame( array( $p['purity'] ), $this->assigned( $p['id'], 'rj_purity' ) );
		$this->assertSame( 'publish', get_post_status( $p['id'] ) );
	}

	/**
	 * M-4a: the last valid editorial category cannot be removed.
	 */
	public function test_removing_the_last_category_of_a_published_product_is_reverted(): void {
		$p = $this->make( 'M4A-CAT' );

		wp_remove_object_terms( $p['id'], $p['cats'], 'rj_category' );

		$this->assertSame( $p['cats'], $this->assigned( $p['id'], 'rj_category' ) );
		$this->assertSame( 'publish', get_post_status( $p['id'] ) );
	}

	/**
	 * M-4a: removing one of two valid categories is allowed.
	 */
	public function test_removing_one_of_two_categories_is_allowed(): void {
		$p = $this->make( 'M4A-TWO', 2 );

		wp_remove_object_terms( $p['id'], $p['cats'][0], 'rj_category' );

		$this->assertSame( array( $p['cats'][1] ), $this->assigned( $p['id'], 'rj_category' ) );
		$this->assertSame( 'publish', get_post_status( $p['id'] ) );
	}

	/**
	 * M-4a: a draft product may lose its terms; only published products are held to M-4.
	 */
	public function test_removing_terms_from_a_draft_product_is_allowed(): void {
		$p = $this->make( 'M4A-DRAFT', 1, 'public', 0, 'draft' );

		wp_remove_object_terms( $p['id'], $p['metal'], 'rj_metal' );

		$this->assertSame( array(), $this->assigned( $p['id'], 'rj_metal' ) );
		$this->assertSame( 'draft', get_post_status( $p['id'] ) );
	}

	/**
	 * M-4a: the removal guard does not resurrect relationships on a hard delete.
	 */
	public function test_hard_delete_leaves_no_orphan_term_relationships(): void {
		$p = $this->make( 'M4A-DEL' );

		wp_delete_post( $p['id'], true );

		$this->assertNull( get_post( $p['id'] ) );
		$this->assertSame( array(), $this->assigned( $p['id'], 'rj_metal' ) );
		$this->assertSame( array(), $this->assigned( $p['id'], 'rj_purity' ) );
		$this->assertSame( array(), $this->assigned( $p['id'], 'rj_category' ) );
	}

	/**
	 * Product IDs returned by a query scoped to one fixture category.
	 *
	 * @param string|array<int, string> $post_type Post type argument.
	 * @param int                       $category  Category term.
	 * @return array<int, int>
	 */
	private function ids( string|array $post_type, int $category ): array {
		$query = new WP_Query(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Test scopes the query to one fixture category.
				'tax_query'      => array(
					array(
						'taxonomy' => 'rj_category',
						'field'    => 'term_id',
						'terms'    => array( $category ),
					),
				),
			)
		);

		return array_map( 'intval', $query->posts );
	}

	/**
	 * M-6: an anonymous request that WordPress treats as admin (admin-ajax.php)
	 * still obeys H-1 for explicit, any and mixed post-type queries.
	 */
	public function test_anonymous_admin_context_query_excludes_hidden_and_archived(): void {
		$category = $this->term( 'rj_category', $this->term( 'rj_category' ) );
		$public   = $this->make( 'M6-PUB', 1, 'public', $category )['id'];
		$hidden   = $this->make( 'M6-HID', 1, 'hidden', $category )['id'];
		$archived = $this->make( 'M6-ARC', 1, 'archived', $category )['id'];

		set_current_screen( 'dashboard' );

		try {
			$this->assertTrue( is_admin(), 'The test must simulate an admin-context request.' );

			foreach ( array( 'rj_product', 'any', array( 'post', 'rj_product' ) ) as $type ) {
				$ids = $this->ids( $type, $category );

				$this->assertContains( $public, $ids );
				$this->assertNotContains( $hidden, $ids );
				$this->assertNotContains( $archived, $ids );
			}
		} finally {
			set_current_screen( 'front' );
		}
	}

	/**
	 * M-6: a logged-in user without edit access is filtered in an admin context too.
	 */
	public function test_low_privilege_admin_context_query_excludes_hidden(): void {
		$category = $this->term( 'rj_category', $this->term( 'rj_category' ) );
		$hidden   = $this->make( 'M6-LOW', 1, 'hidden', $category )['id'];

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		set_current_screen( 'dashboard' );

		try {
			$this->assertNotContains( $hidden, $this->ids( 'rj_product', $category ) );
		} finally {
			set_current_screen( 'front' );
		}
	}

	/**
	 * M-6: privileged readers keep seeing every product in an admin context.
	 */
	public function test_administrator_in_admin_context_still_sees_hidden_and_archived(): void {
		$category = $this->term( 'rj_category', $this->term( 'rj_category' ) );
		$hidden   = $this->make( 'M6-ADM-H', 1, 'hidden', $category )['id'];
		$archived = $this->make( 'M6-ADM-A', 1, 'archived', $category )['id'];

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		set_current_screen( 'dashboard' );

		try {
			$ids = $this->ids( 'rj_product', $category );

			$this->assertContains( $hidden, $ids );
			$this->assertContains( $archived, $ids );
		} finally {
			set_current_screen( 'front' );
		}
	}

	/**
	 * Finding 1: replacing the only Metal with another is a valid replacement.
	 */
	public function test_replacing_the_only_metal_succeeds(): void {
		$p     = $this->make( 'R-METAL' );
		$other = $this->term( 'rj_metal' );

		wp_set_object_terms( $p['id'], array( $other ), 'rj_metal', false );

		$this->assertSame( array( $other ), $this->assigned( $p['id'], 'rj_metal' ) );
		$this->assertSame( 'publish', get_post_status( $p['id'] ) );
		$this->assertSame( array(), ProductModule::service()->publication_gaps( $p['id'] ) );
	}

	/**
	 * Finding 1: replacing the only Purity with another is a valid replacement.
	 */
	public function test_replacing_the_only_purity_succeeds(): void {
		$p     = $this->make( 'R-PURITY' );
		$other = $this->term( 'rj_purity' );

		wp_set_object_terms( $p['id'], array( $other ), 'rj_purity', false );

		$this->assertSame( array( $other ), $this->assigned( $p['id'], 'rj_purity' ) );
		$this->assertSame( 'publish', get_post_status( $p['id'] ) );
		$this->assertSame( array(), ProductModule::service()->publication_gaps( $p['id'] ) );
	}

	/**
	 * Finding 1: replacing the only category with another valid one succeeds.
	 */
	public function test_replacing_the_only_category_succeeds(): void {
		$p     = $this->make( 'R-CAT' );
		$other = $this->term( 'rj_category', $this->term( 'rj_category' ) );

		wp_set_object_terms( $p['id'], array( $other ), 'rj_category', false );

		$this->assertSame( array( $other ), $this->assigned( $p['id'], 'rj_category' ) );
		$this->assertSame( 'publish', get_post_status( $p['id'] ) );
	}

	/**
	 * Finding 1: replacing with nothing is still refused (final state is invalid).
	 */
	public function test_replacing_the_only_metal_with_nothing_is_reverted(): void {
		$p = $this->make( 'R-EMPTY' );

		wp_set_object_terms( $p['id'], array(), 'rj_metal', false );

		$this->assertSame( array( $p['metal'] ), $this->assigned( $p['id'], 'rj_metal' ) );
		$this->assertSame( 'publish', get_post_status( $p['id'] ) );
	}

	/**
	 * Finding 2: deleting a taxonomy term leaves no relationship row pointing at it.
	 */
	public function test_deleting_a_metal_term_leaves_no_orphan_relationship(): void {
		global $wpdb;

		$p  = $this->make( 'D-TERM' );
		$tt = (int) get_term( $p['metal'], 'rj_metal' )->term_taxonomy_id;

		wp_delete_term( $p['metal'], 'rj_metal' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Test inspects the relationship table directly to prove no orphan row remains.
		$rows = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d", $tt ) );

		$this->assertSame( 0, $rows );
		$this->assertSame( array(), $this->assigned( $p['id'], 'rj_metal' ) );
	}
}
