<?php
/**
 * Category menu tree tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Integration;

use Rameshwari\Core\Category\CategoryImporter;
use Rameshwari\Core\Category\CategoryPath;
use Rameshwari\Core\Category\CategoryRepository;
use Rameshwari\Core\Category\MenuTree;
use WP_UnitTestCase;

/**
 * Builds the tree from the real imported 306-term master.
 */
final class CategoryTreeTest extends WP_UnitTestCase {

	/**
	 * Repository.
	 *
	 * @var CategoryRepository
	 */
	private CategoryRepository $repository;

	/**
	 * Imports the master.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->repository = new CategoryRepository();

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local Stage 5 test fixture; no remote URL is involved.
		$data = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/plugin/rameshwari-core/data/categories.json' ), true );

		$this->assertIsArray( $data );
		( new CategoryImporter( $this->repository ) )->import( $data );
	}

	/**
	 * Term ID of a path.
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
	 * The five roots come out in the locked order.
	 */
	public function test_five_roots_in_locked_order(): void {
		$slugs = array_map( static fn( $node ): string => $node->slug(), MenuTree::build( $this->repository )->roots() );

		$this->assertSame( array( 'jewellery', 'metals', 'wedding', 'for', 'others' ), $slugs );
	}

	/**
	 * Every category is in the tree once: nothing is lost and nothing is added.
	 */
	public function test_every_category_appears_exactly_once(): void {
		$tree = MenuTree::build( $this->repository );
		$flat = $tree->flatten();

		$this->assertSame( 306, $tree->total() );
		$this->assertSame( 306, $tree->node_count() );
		$this->assertSame( array(), $tree->orphans() );
		$this->assertCount( 306, array_unique( array_map( static fn( $node ): int => $node->term_id(), $flat ) ) );
		$this->assertCount( 306, array_unique( array_map( static fn( $node ): string => $node->path(), $flat ) ) );
	}

	/**
	 * A node's path is its canonical identity and matches the repository's path.
	 */
	public function test_node_paths_match_the_repository(): void {
		foreach ( MenuTree::build( $this->repository )->flatten() as $node ) {
			$this->assertSame( $this->repository->path_of( $node->term_id() ), $node->path() );
		}
	}

	/**
	 * The same visible name under different parents stays separate nodes.
	 */
	public function test_duplicate_names_stay_distinct_nodes(): void {
		$paths = array();

		foreach ( MenuTree::build( $this->repository )->flatten() as $node ) {
			if ( 'mangalsutra' === $node->slug() ) {
				$paths[] = $node->path();
			}
		}

		$this->assertGreaterThanOrEqual( 4, count( $paths ) );
		$this->assertSame( count( $paths ), count( array_unique( $paths ) ) );
	}

	/**
	 * Siblings sort by order, then slug.
	 */
	public function test_sibling_order_then_slug(): void {
		update_term_meta( $this->id( 'jewellery/gold-jewellery' ), '_rj_order', 99 );
		update_term_meta( $this->id( 'jewellery/silver-jewellery' ), '_rj_order', 7 );
		update_term_meta( $this->id( 'jewellery/black-polish-silver' ), '_rj_order', 7 );

		$roots = MenuTree::build( $this->repository )->roots();
		$slugs = array_map( static fn( $node ): string => $node->slug(), $roots[0]->children() );

		$this->assertSame( 'gold-jewellery', end( $slugs ) );
		$this->assertLessThan( array_search( 'silver-jewellery', $slugs, true ), array_search( 'black-polish-silver', $slugs, true ) );
	}

	/**
	 * Nesting is not limited to today's depth.
	 */
	public function test_unbounded_depth_is_preserved(): void {
		$parent = $this->id( 'others/antique' );
		$path   = 'others/antique';

		for ( $level = 1; $level <= 12; $level++ ) {
			$parent = $this->repository->create( "Level {$level}", "level-{$level}", $parent );
			$path  .= "/level-{$level}";
		}

		$paths = array_map( static fn( $node ): string => $node->path(), MenuTree::build( $this->repository )->flatten() );

		$this->assertContains( $path, $paths );
	}

	/**
	 * A hidden category and everything below it leave the public tree, not the full tree.
	 */
	public function test_hidden_category_and_subtree_follow_visibility(): void {
		update_term_meta( $this->id( 'jewellery/gold-jewellery' ), '_rj_visible', false );

		$public = MenuTree::build( $this->repository, true );
		$full   = MenuTree::build( $this->repository );

		$this->assertSame( 306 - 74, $public->node_count() );
		$this->assertSame( 306, $full->node_count() );

		foreach ( $public->flatten() as $node ) {
			$this->assertNotSame( 'jewellery/gold-jewellery', $node->path() );
			$this->assertStringStartsNotWith( 'jewellery/gold-jewellery/', $node->path() );
		}
	}

	/**
	 * A hidden parent hides its visible child, and that child is not reported as an orphan.
	 */
	public function test_visible_child_of_hidden_parent_is_hidden_not_orphaned(): void {
		$parent = $this->repository->create( 'Hidden parent', 'hidden-parent', $this->id( 'others' ) );
		$child  = $this->repository->create( 'Visible child', 'visible-child', $parent );

		update_term_meta( $parent, '_rj_visible', false );
		update_term_meta( $child, '_rj_visible', true );

		$public = MenuTree::build( $this->repository, true );
		$ids    = array_map( static fn( $node ): int => $node->term_id(), $public->flatten() );

		$this->assertNotContains( $parent, $ids );
		$this->assertNotContains( $child, $ids );
		$this->assertSame( array(), $public->orphans() );

		$full = MenuTree::build( $this->repository );
		$all  = array_map( static fn( $node ): int => $node->term_id(), $full->flatten() );

		$this->assertContains( $parent, $all );
		$this->assertContains( $child, $all );
		$this->assertSame( $full->total(), $full->node_count() );
	}

	/**
	 * A category whose parent is missing is reported, never dropped or promoted to a root.
	 */
	public function test_missing_parent_is_reported_not_dropped(): void {
		global $wpdb;

		$leaf = $this->id( 'jewellery/silver-jewellery/mahila-ke-abhushan/neck-jewellery/mangalsutra' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test breaks a parent link on purpose.
		$wpdb->update( $wpdb->term_taxonomy, array( 'parent' => 999999 ), array( 'term_id' => $leaf ) );
		clean_term_cache( $leaf, 'rj_category' );

		$tree = MenuTree::build( $this->repository );

		$this->assertSame( array( $leaf ), array_map( static fn( $node ): int => $node->term_id(), $tree->orphans() ) );
		$this->assertSame( 306, $tree->node_count() );
		$this->assertCount( 5, $tree->roots() );
	}

	/**
	 * Building twice gives the same tree.
	 */
	public function test_repeated_builds_are_identical(): void {
		$paths = static fn(): array => array_map( static fn( $node ): string => $node->path(), MenuTree::build( new CategoryRepository() )->flatten() );

		$this->assertSame( $paths(), $paths() );
	}

	/**
	 * Navigation terms are flagged on their nodes.
	 */
	public function test_resolver_terms_are_flagged(): void {
		$flagged = 0;

		foreach ( MenuTree::build( $this->repository )->flatten() as $node ) {
			if ( $node->is_resolver() ) {
				++$flagged;
			}
		}

		$this->assertSame( 22, $flagged );
	}
}
