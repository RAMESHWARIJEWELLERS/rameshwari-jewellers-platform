<?php
/**
 * Category engine tests: path identity, repository and importer.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Integration;

use Rameshwari\Core\Category\CategoryImporter;
use Rameshwari\Core\Category\CategoryPath;
use Rameshwari\Core\Category\CategoryRepository;
use WP_UnitTestCase;

/**
 * Imports the real 306-term asset into WordPress.
 */
final class CategoryImporterTest extends WP_UnitTestCase {

	/**
	 * Importer under test.
	 *
	 * @var CategoryImporter
	 */
	private CategoryImporter $importer;

	/**
	 * Repository.
	 *
	 * @var CategoryRepository
	 */
	private CategoryRepository $repository;

	/**
	 * Builds the importer.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->repository = new CategoryRepository();
		$this->importer   = new CategoryImporter( $this->repository );
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
	 * Path identity: valid, parent, leaf and depth.
	 */
	public function test_category_path_identity(): void {
		$path = CategoryPath::from_string( 'jewellery/gold-jewellery/mahila-ke-abhushan' );

		$this->assertSame( 3, $path->depth() );
		$this->assertSame( 'mahila-ke-abhushan', $path->leaf() );
		$this->assertSame( 'jewellery/gold-jewellery', $path->parent()?->to_string() );
		$this->assertNull( CategoryPath::from_string( 'jewellery' )->parent() );
	}

	/**
	 * Non-Latin or malformed paths are rejected.
	 */
	public function test_category_path_rejects_invalid(): void {
		foreach ( array( '', 'a//b', 'A/b', 'महिला', 'a/-b' ) as $bad ) {
			try {
				CategoryPath::from_string( $bad );
				$this->fail( "Accepted {$bad}" );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertInstanceOf( \InvalidArgumentException::class, $e );
			}
		}
	}

	/**
	 * First import creates 306 categories; every parent is real.
	 */
	public function test_first_import_creates_306(): void {
		$result = $this->importer->import( $this->records() );

		$this->assertSame( 306, $result['created'] );
		$this->assertSame( 0, $result['updated'] );
		$this->assertCount( 306, $this->repository->all_ids() );

		foreach ( $this->records() as $record ) {
			$id = $this->repository->find( CategoryPath::from_string( $record['path'] ) );

			$this->assertNotNull( $id, $record['path'] );
			$this->assertSame( $record['slug'], get_term( $id, 'rj_category' )->slug, $record['path'] );
		}
	}

	/**
	 * Importing again updates the same categories and creates none.
	 */
	public function test_reimport_is_idempotent(): void {
		$this->importer->import( $this->records() );
		$before = $this->repository->all_ids();
		$result = $this->importer->import( $this->records() );

		$this->assertSame( 0, $result['created'] );
		$this->assertSame( 306, $result['updated'] );
		$this->assertEqualsCanonicalizing( $before, $this->repository->all_ids() );
	}

	/**
	 * Same visible name under different parents stays separate.
	 */
	public function test_duplicate_visible_names_stay_separate(): void {
		$this->importer->import( $this->records() );

		$gold   = $this->repository->find( CategoryPath::from_string( 'jewellery/gold-jewellery/mahila-ke-abhushan/neck-jewellery/mangalsutra' ) );
		$silver = $this->repository->find( CategoryPath::from_string( 'jewellery/silver-jewellery/mahila-ke-abhushan/neck-jewellery/mangalsutra' ) );

		$this->assertNotNull( $gold );
		$this->assertNotNull( $silver );
		$this->assertNotSame( $gold, $silver );
		$this->assertSame( 'mangalsutra', get_term( (int) $silver, 'rj_category' )->slug );
	}

	/**
	 * Five roots in the locked order.
	 */
	public function test_five_roots_in_order(): void {
		$this->importer->import( $this->records() );

		$roots = get_terms(
			array(
				'taxonomy'   => 'rj_category',
				'parent'     => 0,
				'hide_empty' => false,
				'meta_key'   => '_rj_order', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Test intentionally orders registered category roots by _rj_order metadata.
				'orderby'    => 'meta_value_num',
				'order'      => 'ASC',
			)
		);

		$this->assertSame( array( 'jewellery', 'metals', 'wedding', 'for', 'others' ), wp_list_pluck( $roots, 'slug' ) );
	}

	/**
	 * Resolver rows are stored; Shape B term IDs are derived from the paths.
	 */
	public function test_resolver_term_ids_are_derived_from_paths(): void {
		$this->importer->import( $this->records() );

		$id       = $this->repository->find( CategoryPath::from_string( 'wedding/maang-tikka' ) );
		$resolver = get_term_meta( (int) $id, '_rj_resolver', true );

		$this->assertIsArray( $resolver );
		$this->assertCount( 3, $resolver['terms'] );

		foreach ( $resolver['paths'] as $index => $path ) {
			$this->assertSame( $this->repository->find( CategoryPath::from_string( $path ) ), $resolver['terms'][ $index ] );
		}
	}

	/**
	 * Importing three times reuses every term and keeps every slug.
	 */
	public function test_repeated_import_reuses_every_term(): void {
		$this->importer->import( $this->records() );

		$ids = array();

		foreach ( $this->records() as $record ) {
			$ids[ $record['path'] ] = $this->repository->find( CategoryPath::from_string( $record['path'] ) );
		}

		foreach ( array( 1, 2 ) as $run ) {
			$result = $this->importer->import( $this->records() );

			$this->assertSame( 0, $result['created'], "run {$run}" );
			$this->assertCount( 306, $this->repository->all_ids(), "run {$run}" );
		}

		foreach ( $this->records() as $record ) {
			$id = $this->repository->find( CategoryPath::from_string( $record['path'] ) );

			$this->assertSame( $ids[ $record['path'] ], $id, $record['path'] );
			$this->assertSame( $record['slug'], get_term( (int) $id, 'rj_category' )->slug, $record['path'] );
		}
	}

	/**
	 * Renaming a term whose slug an older term also holds changes only that term.
	 */
	public function test_renaming_a_shared_slug_term_changes_only_that_term(): void {
		$this->importer->import( $this->records() );

		$gold   = (int) $this->repository->find( CategoryPath::from_string( 'jewellery/gold-jewellery/mahila-ke-abhushan' ) );
		$silver = (int) $this->repository->find( CategoryPath::from_string( 'jewellery/silver-jewellery/mahila-ke-abhushan' ) );
		$before = get_term( $gold, 'rj_category' )->name;

		$records = $this->records();

		foreach ( $records as $index => $record ) {
			if ( 'jewellery/silver-jewellery/mahila-ke-abhushan' === $record['path'] ) {
				$records[ $index ]['name_hi'] = 'नया नाम';
			}
		}

		$result = $this->importer->import( $records );

		$this->assertSame( 0, $result['created'] );
		$this->assertSame( 'नया नाम', get_term( $silver, 'rj_category' )->name );
		$this->assertSame( $before, get_term( $gold, 'rj_category' )->name );
		$this->assertSame( 'mahila-ke-abhushan', get_term( $silver, 'rj_category' )->slug );
		$this->assertCount( 306, $this->repository->all_ids() );
	}

	/**
	 * Invalid data is rejected and nothing is written.
	 */
	public function test_invalid_records_write_nothing(): void {
		$records                 = $this->records();
		$records[10]['slug']     = 'wrong';
		$records[11]['path']     = 'jewellery/missing-parent/child';
		$records[11]['slug']     = 'child';
		$records[12]['resolver'] = array(
			'tax'  => 'rj_category',
			'slug' => 'x',
		);

		$this->assertNotEmpty( $this->importer->validate( $records ) );

		try {
			$this->importer->import( $records );
			$this->fail( 'Invalid data was imported.' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertCount( 0, $this->repository->all_ids() );
		}
	}
}
