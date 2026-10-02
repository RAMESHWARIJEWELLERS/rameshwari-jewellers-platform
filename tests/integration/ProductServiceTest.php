<?php
/**
 * Product Code, publication marker, names, facets, publication and lifecycle rules.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Integration;

use Rameshwari\Core\Data\Capabilities;
use Rameshwari\Core\Data\Meta;
use Rameshwari\Core\Data\Schema;
use Rameshwari\Core\Product\ProductCode;
use Rameshwari\Core\Product\ProductData;
use Rameshwari\Core\Product\ProductGuard;
use Rameshwari\Core\Product\ProductModule;
use Rameshwari\Core\Product\ProductRepository;
use WP_UnitTestCase;

/**
 * Product Code, publication marker, names, facets, publication and lifecycle rules.
 */
final class ProductServiceTest extends WP_UnitTestCase {

	private const MARKER = '_rj_first_published_at';

	/**
	 * Restores the plugin registry for each test, as MetaTest does.
	 */
	public function set_up(): void {
		parent::set_up();
		do_action( 'init' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Exercises the plugin's normal init registration.
		Capabilities::apply();
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
	 * A complete field set, optionally overridden.
	 *
	 * @param string               $code      Product Code.
	 * @param array<string, mixed> $overrides Overrides.
	 * @return array<string, mixed>
	 */
	private function fields( string $code, array $overrides = array() ): array {
		$root = $this->term( 'rj_category' );

		return array_merge(
			array(
				'code'       => $code,
				'name_en'    => 'Ring ' . $code,
				'name_hi'    => 'अंगूठी',
				'metal'      => array( $this->term( 'rj_metal' ) ),
				'purity'     => array( $this->term( 'rj_purity' ) ),
				'categories' => array( $this->term( 'rj_category', $root ) ),
			),
			$overrides
		);
	}

	/**
	 * Saves through the service.
	 *
	 * @param array<string, mixed> $fields Fields.
	 * @param int|null             $id     Product or null.
	 * @return int|\WP_Error
	 */
	private function save( array $fields, ?int $id = null ): int|\WP_Error {
		return ProductModule::service()->save( ProductData::from_array( $fields ), $id );
	}

	/**
	 * A saved product ID.
	 *
	 * @param string $code   Code.
	 * @param string $status draft or publish.
	 * @return int
	 */
	private function make( string $code, string $status = 'draft' ): int {
		$id = $this->save( $this->fields( $code, array( 'status' => $status ) ) );
		$this->assertIsInt( $id );

		return $id;
	}

	/**
	 * A fresh read of the publication marker. It reads mutable database state, so repeated calls are not interchangeable.
	 *
	 * @phpstan-impure
	 * @param int $id Product ID.
	 * @return string
	 */
	private function marker( int $id ): string {
		return (string) get_post_meta( $id, self::MARKER, true );
	}

	/**
	 * Asserts a 409 rj_conflict.
	 *
	 * @param mixed $result Result.
	 */
	private function assertConflict( mixed $result ): void {
		$this->assertWPError( $result );
		$this->assertSame( 'rj_conflict', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
	}

	/**
	 * Product code normalises.
	 */
	public function test_product_code_normalises(): void {
		$this->assertSame( 'AB-12X', ProductCode::normalise( ' ab-12 x!' ) );
		$this->assertSame( 64, strlen( ProductCode::normalise( str_repeat( 'a', 80 ) ) ) );
		$this->assertNull( ProductCode::from( '!!!' ) );
		$this->assertSame( 'RJ-1', ProductCode::from( 'rj-1' )->value() );
	}

	/**
	 * Product data allow list.
	 */
	public function test_product_data_allow_list(): void {
		foreach ( array( self::MARKER, 'title', 'post_status', 'unknown' ) as $key ) {
			try {
				ProductData::from_array( array( $key => 'x' ) );
				$this->fail( "{$key} should be rejected" );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertStringContainsString( $key, $e->getMessage() );
			}
		}

		$data = ProductData::from_array(
			array(
				'code'       => 'ab 1',
				'categories' => array( '4', 'x', 4 ),
			)
		);
		$this->assertSame( 'AB1', $data->get( 'code' ) );
		$this->assertSame( array( 4 ), $data->get( 'categories' ) );
	}

	/**
	 * Repository round trip.
	 */
	public function test_repository_round_trip(): void {
		$id   = $this->make( 'RT-1' );
		$repo = new ProductRepository();
		$this->assertSame( 'RT-1', $repo->find( $id )['code'] );
		$this->assertSame( $id, $repo->find_by_code( 'rt-1' )['id'] );
		$this->assertTrue( $repo->code_exists( 'RT-1' ) );
		$this->assertFalse( $repo->code_exists( 'RT-1', $id ) );
		$repo->set_visibility( $id, 'hidden' );
		$this->assertSame( 'hidden', $repo->find( $id )['visibility'] );
		$this->assertTrue( $repo->trash( $id ) );
		$this->assertSame( $id, $repo->find_by_code( 'RT-1' )['id'] );
	}

	/**
	 * Duplicate and case variant codes conflict.
	 */
	public function test_duplicate_and_case_variant_codes_conflict(): void {
		$this->make( 'DUP-1' );
		$this->assertConflict( $this->save( $this->fields( 'DUP-1' ) ) );
		$this->assertConflict( $this->save( $this->fields( 'dup-1' ) ) );
	}

	/**
	 * Trash keeps code and hard delete releases it.
	 */
	public function test_trash_keeps_code_and_hard_delete_releases_it(): void {
		$id = $this->make( 'TR-1' );
		ProductModule::service()->trash( $id );
		$this->assertConflict( $this->save( $this->fields( 'TR-1' ) ) );
		wp_delete_post( $id, true );
		$this->assertIsInt( $this->save( $this->fields( 'TR-1' ) ) );
	}

	/**
	 * Code editable before publication only.
	 */
	public function test_code_editable_before_publication_only(): void {
		$id = $this->make( 'ED-1' );
		$this->assertSame( $id, $this->save( array( 'code' => 'ED-2' ), $id ) );
		$this->assertSame( 'ED-2', ( new ProductRepository() )->find( $id )['code'] );
		$this->save( array( 'status' => 'publish' ), $id );
		$this->assertConflict( $this->save( array( 'code' => 'ED-3' ), $id ) );
	}

	/**
	 * Marker created once and survives draft and trash.
	 */
	public function test_marker_created_once_and_survives_draft_and_trash(): void {
		$id    = $this->make( 'MK-1', 'publish' );
		$first = $this->marker( $id );
		$this->assertNotSame( '', $first );

		$this->save( array( 'status' => 'publish' ), $id );
		$after_repeat = $this->marker( $id );
		$this->assertSame( $first, $after_repeat );

		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'draft',
			)
		);
		$after_draft = $this->marker( $id );
		$this->assertSame( $first, $after_draft );
		$this->assertConflict( $this->save( array( 'code' => 'MK-2' ), $id ) );

		ProductModule::service()->trash( $id );
		$after_trash = $this->marker( $id );
		$this->assertSame( $first, $after_trash );
		$this->assertConflict( $this->save( array( 'code' => 'MK-3' ), $id ) );

		wp_untrash_post( $id );
		$after_untrash = $this->marker( $id );
		$this->assertSame( $first, $after_untrash );
		$this->assertTrue( ProductModule::service()->has_ever_been_published( $id ) );
	}

	/**
	 * Legacy published product without marker is backfilled.
	 */
	public function test_legacy_published_product_without_marker_is_backfilled(): void {
		$id = $this->make( 'LG-1', 'publish' );
		ProductGuard::as_system( static fn() => delete_post_meta( $id, self::MARKER ) );
		$this->assertSame( '', get_post_meta( $id, self::MARKER, true ) );
		$this->assertTrue( ProductModule::service()->has_ever_been_published( $id ) );
		$this->assertNotSame( '', get_post_meta( $id, self::MARKER, true ) );
	}

	/**
	 * Title follows english name.
	 */
	public function test_title_follows_english_name(): void {
		$id = $this->make( 'TT-1' );
		$this->assertSame( 'Ring TT-1', get_the_title( $id ) );
		wp_update_post(
			array(
				'ID'         => $id,
				'post_title' => 'Conflicting',
			)
		);
		$stored = get_post( $id );
		$this->assertInstanceOf( \WP_Post::class, $stored );
		$this->assertSame( 'Ring TT-1', $stored->post_title );
	}

	/**
	 * Metal and purity cardinality.
	 */
	public function test_metal_and_purity_cardinality(): void {
		$draft = $this->save(
			$this->fields(
				'CD-1',
				array(
					'metal'  => array(),
					'purity' => array(),
				)
			)
		);
		$this->assertIsInt( $draft );
		$this->assertConflict( $this->save( $this->fields( 'CD-2', array( 'metal' => array( $this->term( 'rj_metal' ), $this->term( 'rj_metal' ) ) ) ) ) );
		$this->assertConflict( $this->save( $this->fields( 'CD-3', array( 'purity' => array( $this->term( 'rj_purity' ), $this->term( 'rj_purity' ) ) ) ) ) );
	}

	/**
	 * Publication requires every field and not weight.
	 */
	public function test_publication_requires_every_field_and_not_weight(): void {
		$cases = array(
			'code'       => '',
			'name_en'    => '',
			'name_hi'    => '',
			'metal'      => array(),
			'purity'     => array(),
			'categories' => array(),
		);

		foreach ( $cases as $key => $empty ) {
			$result = $this->save(
				$this->fields(
					'PB-' . $key,
					array(
						$key     => $empty,
						'status' => 'publish',
					)
				)
			);
			$this->assertWPError( $result, $key );
			$this->assertSame( 'rj_invalid', $result->get_error_code(), $key );
		}

		$this->assertIsInt( $this->save( $this->fields( 'PB-OK', array( 'status' => 'publish' ) ) ) );
	}

	/**
	 * Category rules reuse the stage 5 guard.
	 */
	public function test_category_rules_reuse_the_stage_5_guard(): void {
		$root     = $this->term( 'rj_category' );
		$resolver = $this->term( 'rj_category', $root );
		update_term_meta(
			$resolver,
			'_rj_resolver',
			array(
				'tax'  => 'rj_metal',
				'slug' => 'gold',
			)
		);

		$this->assertConflict( $this->save( $this->fields( 'CT-1', array( 'categories' => array( $root ) ) ) ) );
		$this->assertConflict( $this->save( $this->fields( 'CT-2', array( 'categories' => array( $resolver ) ) ) ) );
		$this->assertConflict( $this->save( $this->fields( 'CT-3', array( 'categories' => array( 999999 ) ) ) ) );

		$leaf = $this->term( 'rj_category', $root );
		$this->assertIsInt(
			$this->save(
				$this->fields(
					'CT-4',
					array(
						'categories'   => array( $leaf ),
						'primary_term' => $leaf,
					)
				)
			)
		);
		$this->assertConflict(
			$this->save(
				$this->fields(
					'CT-5',
					array(
						'categories'   => array( $leaf ),
						'primary_term' => $root,
					)
				)
			)
		);
		$this->assertConflict(
			$this->save(
				$this->fields(
					'CT-6',
					array(
						'categories'   => array( $leaf ),
						'primary_term' => $this->term( 'rj_category', $root ),
					)
				)
			)
		);
	}

	/**
	 * Status and visibility are independent.
	 */
	public function test_status_and_visibility_are_independent(): void {
		$service = ProductModule::service();
		$id      = $this->make( 'LC-1', 'publish' );
		$this->assertTrue( $service->is_public( $id ) );

		foreach ( array( 'hidden', 'archived' ) as $visibility ) {
			$this->save( array( 'visibility' => $visibility ), $id );
			$this->assertSame( 'publish', get_post_status( $id ) );
			$this->assertFalse( $service->is_public( $id ), $visibility );
		}

		$this->assertNotContains( 'archived', get_post_stati() );
	}

	/**
	 * No schema change and no hallmark.
	 */
	public function test_no_schema_change_and_no_hallmark(): void {
		$this->assertCount( 8, Schema::names() );
		$this->assertArrayNotHasKey( '_rj_hallmark', get_registered_meta_keys( 'post', 'rj_product' ) );
	}
}
