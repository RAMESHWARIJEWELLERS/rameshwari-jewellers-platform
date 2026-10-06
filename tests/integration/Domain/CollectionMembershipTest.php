<?php
/**
 * Collection membership tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Integration\Domain;

use Rameshwari\Core\Domain\Collection\Collection;
use Rameshwari\Core\Domain\Collection\CollectionMembership;
use Rameshwari\Core\Data\Capabilities;
use Rameshwari\Core\Domain\Collection\CollectionRepository;
use Rameshwari\Core\Product\ProductData;
use Rameshwari\Core\Product\ProductModule;

/**
 * Membership against real posts and real rj_collection_tax terms.
 */
final class CollectionMembershipTest extends \WP_UnitTestCase {

	/**
	 * Counter that keeps Product Codes unique within a test.
	 *
	 * @var int
	 */
	private int $seq = 0;

	/**
	 * Shared Stage 6 terms: one metal, one purity, one category under a root.
	 *
	 * @var array<string,int>
	 */
	private array $shared = array();

	/**
	 * Restores the plugin registry for each test, as the Stage 6 product tests do.
	 */
	public function set_up(): void {
		parent::set_up();
		do_action( 'init' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Exercises the plugin's normal init registration.
		Capabilities::apply();

		$this->seq    = 0;
		$this->shared = array();
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
	 * The shared metal, purity and category terms every test product uses, created once per test.
	 *
	 * @return array<string,int>
	 */
	private function shared_terms(): array {
		if ( array() === $this->shared ) {
			$root         = $this->term( 'rj_category' );
			$this->shared = array(
				'metal'    => $this->term( 'rj_metal' ),
				'purity'   => $this->term( 'rj_purity' ),
				'category' => $this->term( 'rj_category', $root ),
			);
		}

		return $this->shared;
	}

	/**
	 * Creates a product through the real Stage 6 service, or another post type with the factory.
	 *
	 * A product is complete (code, both names, one metal, one purity, a category), so ProductGuard
	 * accepts its publication; nothing is bypassed or disabled.
	 *
	 * @param string $type   Post type.
	 * @param string $status Post status: publish or draft for a product.
	 * @return int
	 */
	private function item( string $type = 'rj_product', string $status = 'publish' ): int {
		if ( 'rj_product' !== $type ) {
			return (int) self::factory()->post->create(
				array(
					'post_type'   => $type,
					'post_status' => $status,
				)
			);
		}

		++$this->seq;

		$terms = $this->shared_terms();
		$code  = 'CM-' . $this->seq;
		$saved = ProductModule::service()->save(
			ProductData::from_array(
				array(
					'code'       => $code,
					'name_en'    => 'Ring ' . $code,
					'name_hi'    => 'अंगूठी',
					'metal'      => array( $terms['metal'] ),
					'purity'     => array( $terms['purity'] ),
					'categories' => array( $terms['category'] ),
					'status'     => 'draft' === $status ? 'draft' : 'publish',
				)
			),
			null
		);

		$this->assertIsInt( $saved, $saved instanceof \WP_Error ? $saved->get_error_message() : 'The product was not saved.' );
		$this->assertSame( 'draft' === $status ? 'draft' : 'publish', get_post_status( $saved ) );

		return $saved;
	}

	/**
	 * Creates a collection link term.
	 *
	 * @return int
	 */
	private function link(): int {
		return (int) self::factory()->term->create( array( 'taxonomy' => CollectionMembership::TAXONOMY ) );
	}

	/**
	 * The service.
	 *
	 * @return CollectionMembership
	 */
	private function service(): CollectionMembership {
		return new CollectionMembership();
	}

	/**
	 * Runs a call that must be refused and returns its message.
	 *
	 * @param callable $call The call.
	 * @return string
	 */
	private function refusal( callable $call ): string {
		try {
			$call();
		} catch ( \InvalidArgumentException $refused ) {
			return $refused->getMessage();
		}

		$this->fail( 'The call was not refused.' );
	}

	/**
	 * A product can be assigned.
	 */
	public function test_product_can_be_assigned(): void {
		$product = $this->item();
		$term    = $this->link();

		$this->assertTrue( $this->service()->assign( $product, $term ) );
		$this->assertContains( $term, wp_get_object_terms( $product, CollectionMembership::TAXONOMY, array( 'fields' => 'ids' ) ) );
	}

	/**
	 * A reel can be assigned.
	 */
	public function test_reel_can_be_assigned(): void {
		$reel = $this->item( 'rj_reel' );
		$term = $this->link();

		$this->assertTrue( $this->service()->assign( $reel, $term ) );
		$this->assertTrue( $this->service()->is_member( $reel, $term ) );
	}

	/**
	 * Membership of a product can be removed.
	 */
	public function test_product_membership_can_be_removed(): void {
		$product = $this->item();
		$term    = $this->link();

		$this->service()->assign( $product, $term );

		$this->assertTrue( $this->service()->remove( $product, $term ) );
		$this->assertFalse( $this->service()->is_member( $product, $term ) );
	}

	/**
	 * Membership of a reel can be removed.
	 */
	public function test_reel_membership_can_be_removed(): void {
		$reel = $this->item( 'rj_reel' );
		$term = $this->link();

		$this->service()->assign( $reel, $term );

		$this->assertTrue( $this->service()->remove( $reel, $term ) );
		$this->assertFalse( $this->service()->is_member( $reel, $term ) );
	}

	/**
	 * Lookup is true for an assigned object and false for an unassigned one.
	 */
	public function test_lookup_tells_members_from_others(): void {
		$member = $this->item();
		$other  = $this->item();
		$term   = $this->link();

		$this->service()->assign( $member, $term );

		$this->assertTrue( $this->service()->is_member( $member, $term ) );
		$this->assertFalse( $this->service()->is_member( $other, $term ) );
		$this->assertFalse( $this->service()->is_member( $member, $this->link() ) );
	}

	/**
	 * The member query returns only assigned objects of the asked type, oldest ID first.
	 */
	public function test_members_query_returns_only_matching_objects(): void {
		$term   = $this->link();
		$second = $this->item();
		$first  = $this->item();
		$reel   = $this->item( 'rj_reel' );
		$other  = $this->item();
		$draft  = $this->item( 'rj_product', 'draft' );

		foreach ( array( $second, $first, $reel, $draft ) as $id ) {
			$this->service()->assign( $id, $term );
		}

		$this->assertSame( array( min( $first, $second ), max( $first, $second ) ), $this->service()->members( $term, 'rj_product' ) );
		$this->assertSame( array( $reel ), $this->service()->members( $term, 'rj_reel' ) );
		$this->assertNotContains( $other, $this->service()->members( $term, 'rj_product' ) );
		$this->assertNotContains( $draft, $this->service()->members( $term, 'rj_product' ) );
	}

	/**
	 * Pages are bounded and deterministic.
	 */
	public function test_members_are_paged(): void {
		$term = $this->link();
		$ids  = array();

		for ( $i = 0; $i < 3; $i++ ) {
			$ids[] = $this->item();

			$this->service()->assign( $ids[ $i ], $term );
		}

		sort( $ids );

		$this->assertSame( array( $ids[0], $ids[1] ), $this->service()->members( $term, 'rj_product', 2 ) );
		$this->assertSame( array( $ids[2] ), $this->service()->members( $term, 'rj_product', 2, 2 ) );
		$this->assertSame( array(), $this->service()->members( $term, 'rj_product', 0 ) );
		$this->assertSame( array(), $this->service()->members( $term, 'rj_product', 5, -1 ) );
	}

	/**
	 * Counts follow taxonomy membership, per type.
	 */
	public function test_counts_reflect_membership(): void {
		$term    = $this->link();
		$product = $this->item();

		$this->assertSame( 0, $this->service()->count( $term, 'rj_product' ) );

		$this->service()->assign( $product, $term );
		$this->service()->assign( $this->item(), $term );
		$this->service()->assign( $this->item( 'rj_reel' ), $term );

		$this->assertSame( 2, $this->service()->count( $term, 'rj_product' ) );
		$this->assertSame( 1, $this->service()->count( $term, 'rj_reel' ) );

		$this->service()->remove( $product, $term );

		$this->assertSame( 1, $this->service()->count( $term, 'rj_product' ) );
	}

	/**
	 * Unsupported post types are refused and nothing is written.
	 */
	public function test_unsupported_post_type_is_rejected(): void {
		$term = $this->link();

		foreach ( array( 'post', 'page', 'rj_showroom', 'rj_testimonial', 'attachment' ) as $type ) {
			$id = $this->item( $type );

			$this->assertNotSame( '', $this->refusal( fn () => $this->service()->assign( $id, $term ) ), $type );
			$this->assertSame( array(), wp_get_object_terms( $id, CollectionMembership::TAXONOMY, array( 'fields' => 'ids' ) ), $type );
			$this->assertFalse( $this->service()->is_member( $id, $term ) );
		}

		$this->assertSame( 0, $this->service()->count( $term, 'post' ) );
		$this->assertSame( array(), $this->service()->members( $term, 'page' ) );
	}

	/**
	 * A missing object is refused.
	 */
	public function test_missing_object_is_rejected(): void {
		$term = $this->link();

		$this->assertNotSame( '', $this->refusal( fn () => $this->service()->assign( 99999999, $term ) ) );
		$this->assertNotSame( '', $this->refusal( fn () => $this->service()->remove( 99999999, $term ) ) );
	}

	/**
	 * A term that does not exist is refused and nothing is written.
	 */
	public function test_nonexistent_term_is_rejected(): void {
		$product = $this->item();

		foreach ( array( 99999999, 0, -3 ) as $term ) {
			$this->assertNotSame( '', $this->refusal( fn () => $this->service()->assign( $product, $term ) ), (string) $term );
			$this->assertNotSame( '', $this->refusal( fn () => $this->service()->remove( $product, $term ) ), (string) $term );
		}

		$this->assertSame( array(), wp_get_object_terms( $product, CollectionMembership::TAXONOMY, array( 'fields' => 'ids' ) ) );
		$this->assertSame( 0, $this->service()->count( 99999999, 'rj_product' ) );
	}

	/**
	 * A term of another taxonomy is refused, even when its ID exists.
	 */
	public function test_wrong_taxonomy_term_is_rejected(): void {
		$product = $this->item();
		$tag     = (int) self::factory()->term->create( array( 'taxonomy' => 'rj_tag' ) );

		$this->assertNotSame( '', $this->refusal( fn () => $this->service()->assign( $product, $tag ) ) );
		$this->assertFalse( $this->service()->is_member( $product, $tag ) );
		$this->assertSame( array(), wp_get_object_terms( $product, 'rj_tag', array( 'fields' => 'ids' ) ) );
		$this->assertSame( 0, $this->service()->count( $tag, 'rj_product' ) );
	}

	/**
	 * A collection post can never be a member itself.
	 */
	public function test_collection_post_is_not_a_member_object(): void {
		$collection = $this->item( 'rj_collection' );
		$term       = $this->link();

		$this->assertNotSame( '', $this->refusal( fn () => $this->service()->assign( $collection, $term ) ) );
		$this->assertSame( array(), wp_get_object_terms( $collection, CollectionMembership::TAXONOMY, array( 'fields' => 'ids' ) ) );
	}

	/**
	 * Assigning twice leaves exactly one membership.
	 */
	public function test_duplicate_assignment_is_idempotent(): void {
		$product = $this->item();
		$term    = $this->link();

		$this->assertTrue( $this->service()->assign( $product, $term ) );
		$this->assertTrue( $this->service()->assign( $product, $term ) );
		$this->assertSame( array( $term ), wp_get_object_terms( $product, CollectionMembership::TAXONOMY, array( 'fields' => 'ids' ) ) );
		$this->assertSame( 1, $this->service()->count( $term, 'rj_product' ) );
	}

	/**
	 * Removing a non-member is safe, and the other memberships stay.
	 */
	public function test_removing_a_non_member_is_safe(): void {
		$product = $this->item();
		$kept    = $this->link();

		$this->service()->assign( $product, $kept );

		$this->assertTrue( $this->service()->remove( $product, $this->link() ) );
		$this->assertTrue( $this->service()->remove( $product, $this->link() ) );
		$this->assertTrue( $this->service()->is_member( $product, $kept ) );
	}

	/**
	 * An object can be in several collections, and each removal is separate.
	 */
	public function test_object_can_belong_to_several_collections(): void {
		$product = $this->item();
		$one     = $this->link();
		$two     = $this->link();

		$this->service()->assign( $product, $one );
		$this->service()->assign( $product, $two );
		$this->service()->remove( $product, $one );

		$this->assertFalse( $this->service()->is_member( $product, $one ) );
		$this->assertTrue( $this->service()->is_member( $product, $two ) );
	}

	/**
	 * Saving, editing or hiding the collection post leaves every membership as it was.
	 */
	public function test_editing_the_collection_does_not_touch_memberships(): void {
		$collection = $this->item( 'rj_collection' );
		$term       = $this->link();
		$product    = $this->item();
		$reel       = $this->item( 'rj_reel' );

		$this->service()->assign( $product, $term );
		$this->service()->assign( $reel, $term );

		$repository = new CollectionRepository();

		$this->assertTrue( $repository->save( new Collection( $collection, 'publish', 0, 'New', 5, array() ) ) );

		wp_update_post(
			array(
				'ID'          => $collection,
				'post_title'  => 'Renamed',
				'post_status' => 'draft',
			)
		);
		wp_trash_post( $collection );

		$this->assertTrue( $this->service()->is_member( $product, $term ) );
		$this->assertTrue( $this->service()->is_member( $reel, $term ) );
		$this->assertSame( 'publish', get_post_status( $product ) );
		$this->assertSame( 1, $this->service()->count( $term, 'rj_product' ) );
	}

	/**
	 * Membership leaves no member IDs in the collection's meta.
	 */
	public function test_no_member_ids_are_stored_on_the_collection(): void {
		$collection = $this->item( 'rj_collection' );
		$term       = $this->link();
		$product    = $this->item();
		$before     = array_keys( get_post_meta( $collection ) );

		$this->service()->assign( $product, $term );
		$this->service()->assign( $this->item( 'rj_reel' ), $term );

		$this->assertEqualsCanonicalizing( $before, array_keys( get_post_meta( $collection ) ) );

		foreach ( get_post_meta( $collection ) as $values ) {
			$this->assertNotContains( (string) $product, array_map( 'strval', (array) maybe_unserialize( $values[0] ?? '' ) ) );
		}
	}

	/**
	 * No taxonomy, option or meta key is added by assigning and removing.
	 */
	public function test_nothing_new_is_registered_or_stored(): void {
		$product    = $this->item();
		$term       = $this->link();
		$taxonomies = get_taxonomies();
		$options    = wp_load_alloptions( true );
		$meta       = array_keys( get_post_meta( $product ) );
		$term_meta  = get_term_meta( $term );

		$this->service()->assign( $product, $term );
		$this->service()->count( $term, 'rj_product' );
		$this->service()->members( $term, 'rj_product' );
		$this->service()->remove( $product, $term );

		$this->assertSame( $taxonomies, get_taxonomies() );
		$this->assertSame( $options, wp_load_alloptions( true ) );
		$this->assertEqualsCanonicalizing( $meta, array_keys( get_post_meta( $product ) ) );
		$this->assertSame( $term_meta, get_term_meta( $term ) );
		$this->assertFalse( taxonomy_exists( 'rj_reel_type' ) );
	}

	/**
	 * Deleting a link term removes the memberships and leaves the products themselves.
	 */
	public function test_deleting_a_link_term_leaves_the_objects(): void {
		$product = $this->item();
		$term    = $this->link();

		$this->service()->assign( $product, $term );
		wp_delete_term( $term, CollectionMembership::TAXONOMY );

		$this->assertSame( 'publish', get_post_status( $product ) );
		$this->assertFalse( $this->service()->is_member( $product, $term ) );
		$this->assertSame( 0, $this->service()->count( $term, 'rj_product' ) );
	}

	/**
	 * Tear-down guard: every filter added by these tests is removed.
	 */
	public function tear_down(): void {
		remove_all_actions( 'set_object_terms' );
		remove_all_actions( 'deleted_term_relationships' );

		parent::tear_down();
	}

	/**
	 * Makes a membership vanish right after core writes it, as another plugin's hook could.
	 *
	 * The set_object_terms action fires inside wp_set_object_terms() after the relationship
	 * is written and before it returns, so assign() sees a write that did not stick.
	 *
	 * @param int $product Product ID.
	 * @param int $term    Link term ID.
	 * @return void
	 */
	private function drop_membership_after_write( int $product, int $term ): void {
		add_action(
			'set_object_terms',
			static function () use ( $product, $term ): void {
				wp_remove_object_terms( $product, $term, CollectionMembership::TAXONOMY );
			},
			10,
			0
		);
	}

	/**
	 * Makes a removal not stick, restoring the membership right after core deletes it.
	 *
	 * @param int $product Product ID.
	 * @param int $term    Link term ID.
	 * @return void
	 */
	private function restore_membership_after_delete( int $product, int $term ): void {
		add_action(
			'deleted_term_relationships',
			static function () use ( $product, $term ): void {
				wp_set_object_terms( $product, array( $term ), CollectionMembership::TAXONOMY, true );
			},
			10,
			0
		);
	}

	/**
	 * An assign whose write does not stick reports false and leaves no membership.
	 */
	public function test_assign_reports_false_when_the_membership_does_not_stick(): void {
		$product = $this->item();
		$term    = $this->link();

		$this->drop_membership_after_write( $product, $term );

		$result = $this->service()->assign( $product, $term );

		remove_all_actions( 'set_object_terms' );

		$this->assertFalse( $result );
		$this->assertSame( array(), wp_get_object_terms( $product, CollectionMembership::TAXONOMY, array( 'fields' => 'ids' ) ) );
		$this->assertFalse( $this->service()->is_member( $product, $term ) );
		$this->assertSame( 0, $this->service()->count( $term, 'rj_product' ) );
	}

	/**
	 * A failed assign leaves the object's other memberships untouched.
	 */
	public function test_failed_assign_keeps_existing_memberships(): void {
		$product = $this->item();
		$kept    = $this->link();
		$new     = $this->link();

		$this->service()->assign( $product, $kept );
		$this->drop_membership_after_write( $product, $new );

		$result = $this->service()->assign( $product, $new );

		remove_all_actions( 'set_object_terms' );

		$this->assertFalse( $result );
		$this->assertTrue( $this->service()->is_member( $product, $kept ) );
		$this->assertFalse( $this->service()->is_member( $product, $new ) );
		$this->assertSame( 1, $this->service()->count( $kept, 'rj_product' ) );
	}

	/**
	 * A retry after a failed assign ends in the same state as one clean run.
	 */
	public function test_retry_after_failed_assign_gives_the_clean_state(): void {
		$product = $this->item();
		$term    = $this->link();

		$this->drop_membership_after_write( $product, $term );

		$this->assertFalse( $this->service()->assign( $product, $term ) );

		remove_all_actions( 'set_object_terms' );

		$this->assertTrue( $this->service()->assign( $product, $term ) );
		$this->assertSame( array( $term ), wp_get_object_terms( $product, CollectionMembership::TAXONOMY, array( 'fields' => 'ids' ) ) );
		$this->assertSame( 1, $this->service()->count( $term, 'rj_product' ) );
	}

	/**
	 * A removal that does not stick reports false, and the membership is still there.
	 */
	public function test_remove_reports_false_when_the_membership_remains(): void {
		$product = $this->item();
		$kept    = $this->link();
		$term    = $this->link();

		$this->service()->assign( $product, $kept );
		$this->service()->assign( $product, $term );
		$this->restore_membership_after_delete( $product, $term );

		$result = $this->service()->remove( $product, $term );

		remove_all_actions( 'deleted_term_relationships' );

		$this->assertFalse( $result );
		$this->assertTrue( $this->service()->is_member( $product, $term ) );
		$this->assertTrue( $this->service()->is_member( $product, $kept ) );
		$this->assertSame( 1, $this->service()->count( $term, 'rj_product' ) );
	}

	/**
	 * After a failed removal, a clean retry removes the membership.
	 */
	public function test_retry_after_failed_removal_gives_the_clean_state(): void {
		$product = $this->item();
		$term    = $this->link();

		$this->service()->assign( $product, $term );
		$this->restore_membership_after_delete( $product, $term );

		$this->assertFalse( $this->service()->remove( $product, $term ) );

		remove_all_actions( 'deleted_term_relationships' );

		$this->assertTrue( $this->service()->remove( $product, $term ) );
		$this->assertFalse( $this->service()->is_member( $product, $term ) );
		$this->assertSame( 0, $this->service()->count( $term, 'rj_product' ) );
	}

	/**
	 * Page-size boundaries: 0 returns nothing, 1 returns one, 100 returns up to 100, 101 is capped at 100.
	 */
	public function test_page_size_boundaries(): void {
		$term = $this->link();
		$ids  = array();

		for ( $i = 0; $i < 101; $i++ ) {
			$ids[] = $this->item();

			$this->service()->assign( $ids[ $i ], $term );
		}

		sort( $ids );

		$this->assertSame( array(), $this->service()->members( $term, 'rj_product', 0 ) );
		$this->assertSame( array( $ids[0] ), $this->service()->members( $term, 'rj_product', 1 ) );
		$this->assertCount( CollectionMembership::MAX_PAGE, $this->service()->members( $term, 'rj_product', CollectionMembership::MAX_PAGE ) );
		$this->assertCount( CollectionMembership::MAX_PAGE, $this->service()->members( $term, 'rj_product', CollectionMembership::MAX_PAGE + 1 ) );
		$this->assertSame( array_slice( $ids, 0, CollectionMembership::MAX_PAGE ), $this->service()->members( $term, 'rj_product', CollectionMembership::MAX_PAGE + 1 ) );
		$this->assertSame( array( $ids[100] ), $this->service()->members( $term, 'rj_product', CollectionMembership::MAX_PAGE, 100 ) );
		$this->assertSame( 101, $this->service()->count( $term, 'rj_product' ) );
	}

	/**
	 * Empty and exhausted collections give empty pages.
	 */
	public function test_empty_collection_and_offset_past_the_end(): void {
		$term = $this->link();

		$this->assertSame( array(), $this->service()->members( $term, 'rj_product' ) );

		$this->service()->assign( $this->item(), $term );

		$this->assertSame( array(), $this->service()->members( $term, 'rj_product', 10, 5 ) );
	}
}
