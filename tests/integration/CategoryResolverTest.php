<?php
/**
 * Resolver engine, assignment guard and rule module tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Integration;

use Rameshwari\Core\Category\AssignmentGuard;
use Rameshwari\Core\Category\CategoryImporter;
use Rameshwari\Core\Category\CategoryPath;
use Rameshwari\Core\Category\CategoryRepository;
use Rameshwari\Core\Category\CategoryRules;
use Rameshwari\Core\Category\ResolverDefinition;
use Rameshwari\Core\Category\ResolverEngine;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Runs the 22 locked mappings against the real imported Category Master.
 */
final class CategoryResolverTest extends WP_UnitTestCase {

	/**
	 * Repository.
	 *
	 * @var CategoryRepository
	 */
	private CategoryRepository $repository;

	/**
	 * Engine.
	 *
	 * @var ResolverEngine
	 */
	private ResolverEngine $engine;

	/**
	 * Guard.
	 *
	 * @var AssignmentGuard
	 */
	private AssignmentGuard $guard;

	/**
	 * Imports the master and creates the facet terms the Shape A mappings point at.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->repository = new CategoryRepository();
		$this->engine     = new ResolverEngine( $this->repository );
		$this->guard      = new AssignmentGuard( $this->repository, $this->engine );

		$records = $this->records();

		( new CategoryImporter( $this->repository ) )->import( $records );

		foreach ( $records as $record ) {
			$resolver = $record['resolver'] ?? null;

			if ( is_array( $resolver ) && isset( $resolver['tax'] ) ) {
				wp_insert_term( ucfirst( $resolver['slug'] ), $resolver['tax'], array( 'slug' => $resolver['slug'] ) );
			}
		}
	}

	/**
	 * The asset records.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function records(): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local Stage 5 test fixture; no remote URL is involved.
		$data = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/plugin/rameshwari-core/data/categories.json' ), true );

		$this->assertIsArray( $data );

		return $data;
	}

	/**
	 * Term ID of a category path.
	 *
	 * @param string $path Slug path.
	 * @return int
	 */
	private function id( string $path ): int {
		$id = $this->repository->find( CategoryPath::from_string( $path ) );

		$this->assertNotNull( $id, $path );

		return (int) $id;
	}

	/**
	 * Exactly 22 resolver terms exist, 19 facet and 3 union, and all resolve.
	 */
	public function test_exactly_22_mappings_all_resolve(): void {
		$report = $this->engine->verify_all();

		$this->assertSame( 22, $report['count'] );
		$this->assertSame( array(), $report['problems'] );

		$kinds = array();

		foreach ( $this->records() as $record ) {
			if ( ! empty( $record['resolver'] ) ) {
				$kinds[] = ResolverDefinition::parse( $record['resolver'] )->kind();
			}
		}

		$this->assertSame( 19, count( array_keys( $kinds, ResolverDefinition::FACET, true ) ) );
		$this->assertSame( 3, count( array_keys( $kinds, ResolverDefinition::UNION, true ) ) );
	}

	/**
	 * Shape A points at the current facet term.
	 */
	public function test_shape_a_resolves_to_the_facet_term(): void {
		$resolution = $this->engine->resolve( $this->id( 'metals/gold' ) );
		$facet      = get_term_by( 'slug', 'gold', 'rj_metal' );

		$this->assertTrue( $resolution->ok() );
		$this->assertSame( ResolverDefinition::FACET, $resolution->kind() );
		$this->assertInstanceOf( \WP_Term::class, $facet );
		$this->assertSame( $facet->term_id, $resolution->facet_term() );
	}

	/**
	 * The four occasion mappings and the eight audience mappings are exact.
	 */
	public function test_occasion_and_audience_mappings(): void {
		$expected = array(
			'wedding/bridal-set'       => array( 'rj_occasion', 'bridal' ),
			'wedding/engagement-rings' => array( 'rj_occasion', 'engagement' ),
			'wedding/anniversary'      => array( 'rj_occasion', 'anniversary' ),
			'wedding/couple-rings'     => array( 'rj_occasion', 'couple' ),
			'for/women'                => array( 'rj_audience', 'women' ),
			'for/groom'                => array( 'rj_audience', 'groom' ),
		);

		foreach ( $expected as $path => $target ) {
			$definition = $this->engine->definition( $this->id( $path ) );

			$this->assertNotNull( $definition, $path );
			$this->assertSame( $target, array( $definition->tax(), $definition->slug() ), $path );
		}
	}

	/**
	 * The three metal hierarchies: each union maps to three leaves, one per metal.
	 */
	public function test_union_mappings_have_three_leaves_each(): void {
		$expected = array(
			'wedding/mangalsutra' => 'neck-jewellery/mangalsutra',
			'wedding/bridal-nath' => 'nose-jewellery/nath',
			'wedding/maang-tikka' => 'sir-ke-abhushan/tika',
		);

		foreach ( $expected as $path => $tail ) {
			$definition = $this->engine->definition( $this->id( $path ) );

			$this->assertNotNull( $definition, $path );
			$this->assertSame( ResolverDefinition::UNION, $definition->kind(), $path );
			$this->assertCount( 3, $definition->paths(), $path );

			foreach ( array( 'gold-jewellery', 'silver-jewellery', 'black-polish-silver' ) as $metal ) {
				$this->assertContains( "jewellery/{$metal}/mahila-ke-abhushan/{$tail}", $definition->paths(), $path );
			}

			$this->assertCount( 3, array_unique( $this->engine->resolve( $this->id( $path ) )->categories() ), $path );
		}
	}

	/**
	 * Maang Tikka is under the head jewellery group, never under Neck.
	 */
	public function test_maang_tikka_is_never_under_neck(): void {
		$definition = $this->engine->definition( $this->id( 'wedding/maang-tikka' ) );

		$this->assertNotNull( $definition );

		foreach ( $definition->paths() as $path ) {
			$this->assertStringContainsString( '/sir-ke-abhushan/tika', $path );
			$this->assertStringNotContainsString( 'neck-jewellery', $path );
		}
	}

	/**
	 * Stored term IDs are derived: stale ones are reported and a rebuild re-derives them from the paths.
	 */
	public function test_runtime_ids_are_rederived_from_portable_paths(): void {
		$term_id    = $this->id( 'wedding/mangalsutra' );
		$definition = $this->engine->definition( $term_id );

		$this->assertNotNull( $definition );

		update_term_meta( $term_id, '_rj_resolver', $definition->with_terms( array( 900001, 900002, 900003 ) )->to_array() );

		$stale = $this->engine->resolve( $term_id );
		$this->assertFalse( $stale->ok() );
		$this->assertStringContainsString( 'rebuild', $stale->problems()[0] );

		$rebuilt = $this->engine->rebuild( $term_id );

		$this->assertSame( $this->engine->resolve( $term_id )->categories(), $rebuilt->terms() );
		$this->assertTrue( $this->engine->resolve( $term_id )->ok() );
		$this->assertSame( $definition->paths(), $rebuilt->paths() );
	}

	/**
	 * A path that no longer resolves is a reported problem, never a silent empty result.
	 */
	public function test_missing_path_is_reported_and_blocks_rebuild(): void {
		$term_id = $this->id( 'wedding/mangalsutra' );
		$leaf    = $this->id( 'jewellery/silver-jewellery/mahila-ke-abhushan/neck-jewellery/mangalsutra' );

		wp_delete_term( $leaf, 'rj_category' );

		$resolution = $this->engine->resolve( $term_id );

		$this->assertFalse( $resolution->ok() );
		$this->assertStringContainsString( 'silver-jewellery', $resolution->problems()[0] );
		$this->assertArrayHasKey( 'wedding/mangalsutra', $this->engine->verify_all()['problems'] );

		$this->expectException( \RuntimeException::class );
		$this->engine->rebuild( $term_id );
	}

	/**
	 * A missing facet term is a reported problem.
	 */
	public function test_missing_facet_term_is_reported(): void {
		$facet = get_term_by( 'slug', 'gold', 'rj_metal' );

		$this->assertInstanceOf( \WP_Term::class, $facet );
		wp_delete_term( $facet->term_id, 'rj_metal' );

		$resolution = $this->engine->resolve( $this->id( 'metals/gold' ) );

		$this->assertFalse( $resolution->ok() );
		$this->assertNull( $resolution->facet_term() );
		$this->assertArrayHasKey( 'metals/gold', $this->engine->verify_all()['problems'] );
	}

	/**
	 * An unreadable stored value is a problem on the term, not an exception.
	 */
	public function test_unreadable_stored_value_is_reported(): void {
		global $wpdb;

		$term_id = $this->id( 'metals/gold' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test writes an invalid stored value past the registered sanitiser.
		$wpdb->update(
			$wpdb->termmeta,
			array( 'meta_value' => maybe_serialize( array( 'tax' => 'rj_category' ) ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Intentional test fixture corruption to verify invalid _rj_resolver handling.
			array(
				'term_id'  => $term_id,
				'meta_key' => '_rj_resolver', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Intentional test fixture update targeting the registered _rj_resolver metadata key.
			)
		);
		wp_cache_flush();

		$resolution = $this->engine->resolve( $term_id );

		$this->assertFalse( $resolution->ok() );
		$this->assertTrue( $this->engine->is_resolver_term( $term_id ) );
	}

	/**
	 * Roots and resolver terms cannot be assigned; editorial leaves can.
	 */
	public function test_guard_rejects_roots_and_resolver_terms(): void {
		$root     = $this->id( 'jewellery' );
		$resolver = $this->id( 'metals/gold' );
		$union    = $this->id( 'wedding/maang-tikka' );
		$leaf     = $this->id( 'jewellery/silver-jewellery/mahila-ke-abhushan/sir-ke-abhushan/tika' );

		$this->assertSame( array(), $this->guard->check( array( $leaf ) ) );

		$root_violations = $this->guard->check( array( $root ) );
		$this->assertCount( 1, $root_violations );
		$this->assertStringContainsString( 'root category', $root_violations[0] );

		$facet_violations = $this->guard->check( array( $resolver ) );
		$this->assertStringContainsString( 'rj_metal', $facet_violations[0] );
		$this->assertStringContainsString( 'gold', $facet_violations[0] );

		$union_violations = $this->guard->check( array( $union ) );
		$this->assertStringContainsString( 'sir-ke-abhushan/tika', $union_violations[0] );

		$this->assertCount( 3, $this->guard->check( array( $leaf, $root, $resolver, $union ) ) );
	}

	/**
	 * The primary term is always an editorial category.
	 */
	public function test_primary_term_must_be_editorial(): void {
		$leaf = $this->id( 'jewellery/gold-jewellery/mahila-ke-abhushan/kalai-ke-abhushan/kada' );

		$this->assertSame( array(), $this->guard->check( array( $leaf ), $leaf ) );
		$this->assertNotSame( array(), $this->guard->check( array(), $this->id( 'metals/gold' ) ) );
		$this->assertNotSame( array(), $this->guard->check( array(), $this->id( 'for/women' ) ) );
		$this->assertNotSame( array(), $this->guard->check( array(), $this->id( 'jewellery' ) ) );
		$this->assertNotSame( array(), $this->guard->check( array(), 999999 ) );
		$this->assertSame( array(), $this->guard->check( array(), 0 ) );
	}

	/**
	 * A product write that assigns a resolver term is a 409 rj_conflict.
	 */
	public function test_product_write_with_resolver_term_is_409(): void {
		$request = new WP_REST_Request( 'POST', '/wp/v2/products' );
		$request->set_param( 'rj-categories', array( $this->id( 'metals/gold' ) ) );

		$result = CategoryRules::guard_product_request( new \stdClass(), $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rj_conflict', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
	}

	/**
	 * A primary term that is a root or resolver term is a 409 rj_conflict.
	 */
	public function test_product_write_with_bad_primary_term_is_409(): void {
		foreach ( array( $this->id( 'wedding/maang-tikka' ), $this->id( 'others' ) ) as $term_id ) {
			$request = new WP_REST_Request( 'PUT', '/wp/v2/products/1' );
			$request->set_param( 'meta', array( '_rj_primary_term' => $term_id ) );

			$result = CategoryRules::guard_product_request( new \stdClass(), $request );

			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertSame( 409, $result->get_error_data()['status'] );
		}
	}

	/**
	 * Valid and unrelated product writes pass through unchanged.
	 */
	public function test_valid_and_unrelated_product_writes_pass(): void {
		$prepared = new \stdClass();
		$valid    = new WP_REST_Request( 'POST', '/wp/v2/products' );
		$valid->set_param( 'rj-categories', array( $this->id( 'jewellery/gold-jewellery/mahila-ke-abhushan/sir-ke-abhushan/tika' ) ) );

		$this->assertSame( $prepared, CategoryRules::guard_product_request( $prepared, $valid ) );
		$this->assertSame( $prepared, CategoryRules::guard_product_request( $prepared, new WP_REST_Request( 'POST', '/wp/v2/products' ) ) );

		$error = new \WP_Error( 'other', 'Other.' );
		$this->assertSame( $error, CategoryRules::guard_product_request( $error, $valid ) );
	}

	/**
	 * The module hooks the guard to product writes at plugin boot.
	 */
	public function test_guard_is_hooked_to_product_writes(): void {
		$this->assertSame( 10, has_filter( 'rest_pre_insert_rj_product', array( CategoryRules::class, 'guard_product_request' ) ) );
	}

	/**
	 * Resolver terms hold no product relationship: the importer writes a mapping, never a relationship.
	 */
	public function test_resolver_terms_have_no_product_relationships(): void {
		foreach ( $this->records() as $record ) {
			if ( empty( $record['resolver'] ) ) {
				continue;
			}

			$term = get_term( $this->id( (string) $record['path'] ), 'rj_category' );

			$this->assertInstanceOf( \WP_Term::class, $term );
			$this->assertSame( 0, $term->count, $record['path'] );
		}
	}
}
