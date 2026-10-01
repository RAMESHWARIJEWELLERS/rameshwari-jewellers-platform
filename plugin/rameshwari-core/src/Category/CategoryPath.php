<?php
/**
 * Canonical category path.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Category;

/**
 * A slash-joined Latin slug path: the portable identity of a category.
 *
 * Term IDs and visible names are never identity. Two categories with the same
 * name under different parents have different paths.
 */
final class CategoryPath {

	/**
	 * Slug segments, root first.
	 *
	 * @var array<int, string>
	 */
	private array $segments;

	/**
	 * Builds a path from segments.
	 *
	 * @param array<int, string> $segments Slug segments, root first.
	 * @throws \InvalidArgumentException When empty or a segment is not a Latin slug.
	 */
	private function __construct( array $segments ) {
		if ( array() === $segments ) {
			throw new \InvalidArgumentException( 'Category path is empty.' );
		}

		foreach ( $segments as $segment ) {
			if ( 1 !== preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $segment ) ) {
				throw new \InvalidArgumentException( 'Invalid category path segment.' );
			}
		}

		$this->segments = array_values( $segments );
	}

	/**
	 * Parses "a/b/c".
	 *
	 * @param string $path Slash-joined slug path.
	 * @return self
	 */
	public static function from_string( string $path ): self {
		return new self( explode( '/', $path ) );
	}

	/**
	 * Slug segments, root first.
	 *
	 * @return array<int, string>
	 */
	public function segments(): array {
		return $this->segments;
	}

	/**
	 * Last segment: the term slug.
	 *
	 * @return string
	 */
	public function leaf(): string {
		return $this->segments[ count( $this->segments ) - 1 ];
	}

	/**
	 * Number of segments; a root has depth 1.
	 *
	 * @return int
	 */
	public function depth(): int {
		return count( $this->segments );
	}

	/**
	 * Parent path, or null for a root.
	 *
	 * @return self|null
	 */
	public function parent(): ?self {
		return $this->depth() > 1 ? new self( array_slice( $this->segments, 0, -1 ) ) : null;
	}

	/**
	 * The slash-joined form.
	 *
	 * @return string
	 */
	public function to_string(): string {
		return implode( '/', $this->segments );
	}
}
