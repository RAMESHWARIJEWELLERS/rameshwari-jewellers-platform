<?php
/**
 * Category tree node.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Category;

/**
 * One category in the tree, identified by its canonical path.
 *
 * The runtime term ID is carried for lookups only. It is never identity.
 */
final class TreeNode {

	/**
	 * Builds a node.
	 *
	 * @param int                 $term_id   Runtime term ID.
	 * @param string              $path      Canonical slash-joined slug path.
	 * @param string              $slug      Term slug.
	 * @param string              $name_en   English name.
	 * @param string              $name_hi   Hindi name.
	 * @param int                 $parent_id Runtime parent term ID, 0 for a root.
	 * @param int                 $order     Sibling order.
	 * @param bool                $visible   Visible state.
	 * @param bool                $resolver  Whether the term is a navigation (resolver) term.
	 * @param array<int,TreeNode> $children  Children in display order.
	 */
	public function __construct(
		private int $term_id,
		private string $path,
		private string $slug,
		private string $name_en,
		private string $name_hi,
		private int $parent_id,
		private int $order,
		private bool $visible,
		private bool $resolver,
		private array $children
	) {
	}

	/**
	 * Runtime term ID.
	 *
	 * @return int
	 */
	public function term_id(): int {
		return $this->term_id;
	}

	/**
	 * Canonical path.
	 *
	 * @return string
	 */
	public function path(): string {
		return $this->path;
	}

	/**
	 * Slug.
	 *
	 * @return string
	 */
	public function slug(): string {
		return $this->slug;
	}

	/**
	 * English name.
	 *
	 * @return string
	 */
	public function name_en(): string {
		return $this->name_en;
	}

	/**
	 * Hindi name.
	 *
	 * @return string
	 */
	public function name_hi(): string {
		return $this->name_hi;
	}

	/**
	 * Display name: Hindi, else English, else the slug.
	 *
	 * @return string
	 */
	public function label(): string {
		return '' !== $this->name_hi ? $this->name_hi : ( '' !== $this->name_en ? $this->name_en : $this->slug );
	}

	/**
	 * Parent term ID.
	 *
	 * @return int
	 */
	public function parent_id(): int {
		return $this->parent_id;
	}

	/**
	 * Sibling order.
	 *
	 * @return int
	 */
	public function order(): int {
		return $this->order;
	}

	/**
	 * Visible state.
	 *
	 * @return bool
	 */
	public function visible(): bool {
		return $this->visible;
	}

	/**
	 * Whether this is a navigation (resolver) term.
	 *
	 * @return bool
	 */
	public function is_resolver(): bool {
		return $this->resolver;
	}

	/**
	 * Whether this is a root.
	 *
	 * @return bool
	 */
	public function is_root(): bool {
		return 0 === $this->parent_id;
	}

	/**
	 * Depth; a root is 1.
	 *
	 * @return int
	 */
	public function depth(): int {
		return substr_count( $this->path, '/' ) + 1;
	}

	/**
	 * Children in display order.
	 *
	 * @return array<int,TreeNode>
	 */
	public function children(): array {
		return $this->children;
	}
}
