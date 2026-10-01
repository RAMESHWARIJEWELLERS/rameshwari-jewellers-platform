<?php
/**
 * Category menu tree.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Category;

/**
 * The rj_category hierarchy as a deterministic tree of TreeNode objects.
 *
 * Identity is the canonical path, so two categories with the same visible
 * name under different parents stay two nodes. Nothing is dropped: a term
 * whose parent is missing, or that no root reaches, is returned as an orphan.
 */
final class MenuTree {

	/**
	 * Builds a tree.
	 *
	 * @param array<int,TreeNode> $roots   Roots in display order.
	 * @param array<int,TreeNode> $orphans Terms no root reaches, without children.
	 * @param int                 $total   Terms read from the repository.
	 */
	private function __construct( private array $roots, private array $orphans, private int $total ) {
	}

	/**
	 * Builds the tree from the repository.
	 *
	 * @param CategoryRepository $repository   Category repository.
	 * @param bool               $visible_only Leave out hidden categories and everything below them.
	 * @return self
	 */
	public static function build( CategoryRepository $repository, bool $visible_only = false ): self {
		$terms     = $repository->all_terms();
		$by_parent = array();
		$seen      = array();

		foreach ( $terms as $term ) {
			$by_parent[ (int) $term->parent ][] = $term;
		}

		$roots = self::level( $repository, $by_parent, 0, '', $visible_only, $seen );

		$orphans = array();

		foreach ( $terms as $term ) {
			if ( isset( $seen[ $term->term_id ] ) ) {
				continue;
			}

			if ( $visible_only && ! $repository->is_visible( $term->term_id ) ) {
				continue;
			}

			$orphans[] = self::node( $repository, $term, $term->slug, array() );
		}

		usort( $orphans, array( self::class, 'compare' ) );

		return new self( $roots, $orphans, count( $terms ) );
	}

	/**
	 * Roots in display order.
	 *
	 * @return array<int,TreeNode>
	 */
	public function roots(): array {
		return $this->roots;
	}

	/**
	 * Terms whose parent is missing or that no root reaches. Never shown as part of the tree.
	 *
	 * @return array<int,TreeNode>
	 */
	public function orphans(): array {
		return $this->orphans;
	}

	/**
	 * Terms read from the repository.
	 *
	 * @return int
	 */
	public function total(): int {
		return $this->total;
	}

	/**
	 * Nodes in roots and orphans together.
	 *
	 * @return int
	 */
	public function node_count(): int {
		$count = count( $this->orphans );

		foreach ( $this->roots as $root ) {
			$count += self::count_nodes( $root );
		}

		return $count;
	}

	/**
	 * Every node, parents before children, in display order.
	 *
	 * @return array<int,TreeNode>
	 */
	public function flatten(): array {
		$flat = array();

		foreach ( $this->roots as $root ) {
			self::walk( $root, $flat );
		}

		return $flat;
	}

	/**
	 * Builds one level and, recursively, everything below it.
	 *
	 * @param CategoryRepository             $repository   Repository.
	 * @param array<int,array<int,\WP_Term>> $by_parent    Terms grouped by parent ID.
	 * @param int                            $parent_id    Parent term ID.
	 * @param string                         $parent_path  Parent canonical path.
	 * @param bool                           $visible_only Skip hidden categories.
	 * @param array<int,bool>                $seen         Term IDs reached from a root.
	 * @return array<int,TreeNode>
	 */
	private static function level( CategoryRepository $repository, array $by_parent, int $parent_id, string $parent_path, bool $visible_only, array &$seen ): array {
		$nodes = array();

		foreach ( $by_parent[ $parent_id ] ?? array() as $term ) {
			if ( isset( $seen[ $term->term_id ] ) ) {
				continue;
			}

			$seen[ $term->term_id ] = true;

			// Walk the subtree even when this node is hidden, so its descendants count as reached and never become orphans.
			$path     = '' === $parent_path ? $term->slug : $parent_path . '/' . $term->slug;
			$children = self::level( $repository, $by_parent, $term->term_id, $path, $visible_only, $seen );

			if ( $visible_only && ! $repository->is_visible( $term->term_id ) ) {
				continue;
			}

			$nodes[] = self::node( $repository, $term, $path, $children );
		}

		usort( $nodes, array( self::class, 'compare' ) );

		return $nodes;
	}

	/**
	 * A node for a term.
	 *
	 * @param CategoryRepository  $repository Repository.
	 * @param \WP_Term            $term       Term.
	 * @param string              $path       Canonical path.
	 * @param array<int,TreeNode> $children   Children.
	 * @return TreeNode
	 */
	private static function node( CategoryRepository $repository, \WP_Term $term, string $path, array $children ): TreeNode {
		$id = $term->term_id;

		return new TreeNode(
			$id,
			$path,
			$term->slug,
			(string) $repository->get_meta( $id, '_rj_name_en' ),
			(string) $repository->get_meta( $id, '_rj_name_hi' ),
			(int) $term->parent,
			(int) $repository->get_meta( $id, '_rj_order' ),
			$repository->is_visible( $id ),
			$repository->has_resolver( $id ),
			$children
		);
	}

	/**
	 * Order, then slug, then term ID: the same dataset always gives the same tree.
	 *
	 * @param TreeNode $a First.
	 * @param TreeNode $b Second.
	 * @return int
	 */
	private static function compare( TreeNode $a, TreeNode $b ): int {
		return array( $a->order(), $a->slug(), $a->term_id() ) <=> array( $b->order(), $b->slug(), $b->term_id() );
	}

	/**
	 * Nodes in a subtree.
	 *
	 * @param TreeNode $node Root of the subtree.
	 * @return int
	 */
	private static function count_nodes( TreeNode $node ): int {
		$count = 1;

		foreach ( $node->children() as $child ) {
			$count += self::count_nodes( $child );
		}

		return $count;
	}

	/**
	 * Appends a subtree to a list, parents first.
	 *
	 * @param TreeNode            $node Root of the subtree.
	 * @param array<int,TreeNode> $flat Output list.
	 * @return void
	 */
	private static function walk( TreeNode $node, array &$flat ): void {
		$flat[] = $node;

		foreach ( $node->children() as $child ) {
			self::walk( $child, $flat );
		}
	}
}
