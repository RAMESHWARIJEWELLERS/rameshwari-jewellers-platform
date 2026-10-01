<?php
/**
 * Resolver resolution result.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Category;

/**
 * What a resolver term currently points at, and what is wrong with it.
 *
 * A resolver with any problem is broken. It is reported, never treated as an
 * empty result.
 */
final class Resolution {

	/**
	 * Builds a result.
	 *
	 * @param string            $kind       ResolverDefinition::FACET or UNION, empty when unreadable.
	 * @param array<int,int>    $categories Current category term IDs (Shape B).
	 * @param int|null          $facet_term Current facet term ID (Shape A).
	 * @param array<int,string> $problems   What is wrong.
	 */
	public function __construct(
		private string $kind,
		private array $categories,
		private ?int $facet_term,
		private array $problems
	) {
	}

	/**
	 * Whether the mapping resolves cleanly.
	 *
	 * @return bool
	 */
	public function ok(): bool {
		return array() === $this->problems;
	}

	/**
	 * Kind.
	 *
	 * @return string
	 */
	public function kind(): string {
		return $this->kind;
	}

	/**
	 * Current category term IDs, in path order.
	 *
	 * @return array<int,int>
	 */
	public function categories(): array {
		return $this->categories;
	}

	/**
	 * Current facet term ID.
	 *
	 * @return int|null
	 */
	public function facet_term(): ?int {
		return $this->facet_term;
	}

	/**
	 * Problems.
	 *
	 * @return array<int,string>
	 */
	public function problems(): array {
		return $this->problems;
	}
}
