<?php
/**
 * Product category assignment guard.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Category;

/**
 * The one rule for what a product may be filed under.
 *
 * Roots and resolver (navigation) terms are never assignable, and the primary
 * term is always editorial. A violation is a 409 rj_conflict naming the facet
 * or categories to use instead. It is never dropped silently.
 */
final class AssignmentGuard {

	/**
	 * Builds the guard.
	 *
	 * @param CategoryRepository $repository Category repository.
	 * @param ResolverEngine     $engine     Resolver engine.
	 */
	public function __construct( private CategoryRepository $repository, private ResolverEngine $engine ) {
	}

	/**
	 * Everything wrong with a proposed assignment.
	 *
	 * @param array<int,int> $term_ids Category term IDs to assign.
	 * @param int            $primary  Proposed _rj_primary_term, 0 for none.
	 * @return array<int,string> Violations; empty when allowed.
	 */
	public function check( array $term_ids, int $primary = 0 ): array {
		$violations = array();

		foreach ( array_unique( $term_ids ) as $term_id ) {
			$reason = $this->reason( $term_id );

			if ( null !== $reason ) {
				$violations[] = $reason;
			}
		}

		if ( $primary > 0 ) {
			$reason = $this->reason( $primary );

			if ( null !== $reason ) {
				$violations[] = 'Primary category: ' . $reason;
			}
		}

		return $violations;
	}

	/**
	 * The 409 error for a list of violations.
	 *
	 * @param array<int,string> $violations Violations.
	 * @return \WP_Error
	 */
	public function error( array $violations ): \WP_Error {
		return new \WP_Error(
			'rj_conflict',
			implode( ' ', $violations ),
			array(
				'status'     => 409,
				'violations' => $violations,
			)
		);
	}

	/**
	 * Why one term cannot be assigned, or null when it can.
	 *
	 * @param int $term_id Term ID.
	 * @return string|null
	 */
	private function reason( int $term_id ): ?string {
		$parent = $this->repository->parent_of( $term_id );

		if ( null === $parent ) {
			return "Term {$term_id} is not a category.";
		}

		$path = $this->repository->path_of( $term_id );

		if ( 0 === $parent ) {
			return "{$path} is a root category and cannot be assigned.";
		}

		if ( $this->engine->is_resolver_term( $term_id ) ) {
			return "{$path} is a navigation term and cannot be assigned. " . $this->alternative( $term_id );
		}

		return null;
	}

	/**
	 * What to assign instead of a resolver term.
	 *
	 * @param int $term_id Resolver term ID.
	 * @return string
	 */
	private function alternative( int $term_id ): string {
		try {
			$definition = $this->engine->definition( $term_id );
		} catch ( \InvalidArgumentException $e ) {
			return 'Its mapping is invalid; fix the resolver first.';
		}

		if ( null === $definition ) {
			return '';
		}

		return ResolverDefinition::FACET === $definition->kind()
			? "Assign the {$definition->tax()} term \"{$definition->slug()}\" instead."
			: 'Assign one of: ' . implode( ', ', $definition->paths() ) . '.';
	}
}
