<?php
/**
 * Resolver definition.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Category;

use Rameshwari\Core\Data\Meta;

/**
 * A validated _rj_resolver value: Shape A (facet) or Shape B (category union).
 *
 * Shape B paths are portable. Term IDs are derived, so a definition may carry
 * none yet.
 */
final class ResolverDefinition {

	public const FACET = 'facet';

	public const UNION = 'union';

	/**
	 * Builds a definition. Use parse().
	 *
	 * @param string            $kind  FACET or UNION.
	 * @param string            $tax   Facet taxonomy (Shape A).
	 * @param string            $slug  Facet term slug (Shape A).
	 * @param array<int,string> $paths Portable category paths (Shape B).
	 * @param array<int,int>    $terms Derived runtime term IDs (Shape B).
	 */
	private function __construct(
		private string $kind,
		private string $tax,
		private string $slug,
		private array $paths,
		private array $terms
	) {
	}

	/**
	 * Validates a stored or imported value.
	 *
	 * @param mixed $value Raw value.
	 * @return self
	 * @throws \InvalidArgumentException When the value is not Shape A or Shape B.
	 */
	public static function parse( mixed $value ): self {
		if ( ! is_array( $value ) ) {
			throw new \InvalidArgumentException( 'Resolver must be an object.' );
		}

		$keys = array_keys( $value );
		sort( $keys );

		if ( array( 'slug', 'tax' ) === $keys ) {
			return self::facet( $value['tax'], $value['slug'] );
		}

		if ( array( 'paths', 'terms' ) === $keys ) {
			return self::union( $value['paths'], $value['terms'] );
		}

		throw new \InvalidArgumentException( 'Resolver is neither Shape A nor Shape B.' );
	}

	/**
	 * Shape A.
	 *
	 * @param mixed $tax  Taxonomy.
	 * @param mixed $slug Slug.
	 * @return self
	 * @throws \InvalidArgumentException When the taxonomy or slug is invalid.
	 */
	private static function facet( mixed $tax, mixed $slug ): self {
		if ( ! is_string( $tax ) || ! in_array( $tax, Meta::FLAT_TERM_TAXONOMIES, true ) ) {
			throw new \InvalidArgumentException( 'Resolver taxonomy is not an approved facet.' );
		}

		if ( ! is_string( $slug ) || 1 !== preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug ) ) {
			throw new \InvalidArgumentException( 'Resolver slug is invalid.' );
		}

		return new self( self::FACET, $tax, $slug, array(), array() );
	}

	/**
	 * Shape B.
	 *
	 * @param mixed $paths Portable paths.
	 * @param mixed $terms Derived term IDs.
	 * @return self
	 * @throws \InvalidArgumentException When paths or terms are invalid.
	 */
	private static function union( mixed $paths, mixed $terms ): self {
		if ( ! is_array( $paths ) || ! array_is_list( $paths ) || count( $paths ) < 2 || count( $paths ) > 12 ) {
			throw new \InvalidArgumentException( 'Resolver paths must be a list of 2 to 12 paths.' );
		}

		$clean = array();

		foreach ( $paths as $path ) {
			if ( ! is_string( $path ) ) {
				throw new \InvalidArgumentException( 'Resolver path is not a string.' );
			}

			$clean[] = CategoryPath::from_string( $path )->to_string();
		}

		if ( count( $clean ) !== count( array_unique( $clean ) ) ) {
			throw new \InvalidArgumentException( 'Resolver paths contain a duplicate.' );
		}

		if ( ! is_array( $terms ) || ! array_is_list( $terms ) ) {
			throw new \InvalidArgumentException( 'Resolver terms must be a list.' );
		}

		foreach ( $terms as $term ) {
			if ( ! is_int( $term ) || $term < 1 ) {
				throw new \InvalidArgumentException( 'Resolver term is not a positive integer.' );
			}
		}

		if ( array() !== $terms && count( $terms ) !== count( $clean ) ) {
			throw new \InvalidArgumentException( 'Resolver terms and paths differ in length.' );
		}

		return new self( self::UNION, '', '', $clean, $terms );
	}

	/**
	 * FACET or UNION.
	 *
	 * @return string
	 */
	public function kind(): string {
		return $this->kind;
	}

	/**
	 * Facet taxonomy.
	 *
	 * @return string
	 */
	public function tax(): string {
		return $this->tax;
	}

	/**
	 * Facet slug.
	 *
	 * @return string
	 */
	public function slug(): string {
		return $this->slug;
	}

	/**
	 * Portable paths.
	 *
	 * @return array<int,string>
	 */
	public function paths(): array {
		return $this->paths;
	}

	/**
	 * Stored runtime term IDs.
	 *
	 * @return array<int,int>
	 */
	public function terms(): array {
		return $this->terms;
	}

	/**
	 * A copy of a Shape B definition with the given derived term IDs.
	 *
	 * @param array<int,int> $terms Term IDs, in path order.
	 * @return self
	 */
	public function with_terms( array $terms ): self {
		return new self( $this->kind, $this->tax, $this->slug, $this->paths, array_values( $terms ) );
	}

	/**
	 * The value to store.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return self::FACET === $this->kind
			? array(
				'tax'  => $this->tax,
				'slug' => $this->slug,
			)
			: array(
				'terms' => $this->terms,
				'paths' => $this->paths,
			);
	}
}
