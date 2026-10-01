<?php
/**
 * Category picker model.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Category;

/**
 * What the admin category picker offers, and how a submitted choice is checked.
 *
 * The picker only offers editorial leaves (never a root or a navigation term),
 * and a choice is a canonical path, not a term ID. AssignmentGuard stays the
 * authority: every submitted path is re-resolved and re-checked on the server.
 */
final class CategoryPicker {

	/**
	 * Builds the picker.
	 *
	 * @param CategoryRepository $repository Category repository.
	 * @param AssignmentGuard    $guard      Assignment guard.
	 */
	public function __construct( private CategoryRepository $repository, private AssignmentGuard $guard ) {
	}

	/**
	 * Visible categories in tree order, each with the trail that tells duplicate names apart.
	 *
	 * @return array<int,array{path: string, label: string, trail: string, depth: int, selectable: bool}>
	 */
	public function options(): array {
		$nodes  = MenuTree::build( $this->repository, true )->flatten();
		$labels = array();

		foreach ( $nodes as $node ) {
			$labels[ $node->path() ] = $node->label();
		}

		$options = array();

		foreach ( $nodes as $node ) {
			$trail    = array();
			$segments = explode( '/', $node->path() );

			foreach ( array_keys( $segments ) as $index ) {
				$trail[] = $labels[ implode( '/', array_slice( $segments, 0, $index + 1 ) ) ] ?? $segments[ $index ];
			}

			$options[] = array(
				'path'       => $node->path(),
				'label'      => $node->label(),
				'trail'      => implode( ' › ', $trail ),
				'depth'      => $node->depth(),
				'selectable' => ! $node->is_root() && ! $node->is_resolver() && array() === $node->children(),
			);
		}

		return $options;
	}

	/**
	 * Resolves submitted canonical paths to term IDs and checks them.
	 *
	 * @param array<int,string> $paths Submitted paths.
	 * @return array{ids: array<int,int>, violations: array<int,string>}
	 */
	public function resolve( array $paths ): array {
		$ids        = array();
		$violations = array();

		foreach ( array_unique( $paths ) as $path ) {
			try {
				$id = $this->repository->find( CategoryPath::from_string( $path ) );
			} catch ( \InvalidArgumentException $e ) {
				$violations[] = 'Invalid category path.';
				continue;
			}

			if ( null === $id ) {
				$violations[] = "Category {$path} does not exist.";
				continue;
			}

			$ids[] = $id;
		}

		return array(
			'ids'        => $ids,
			'violations' => array_merge( $violations, $this->guard->check( $ids ) ),
		);
	}
}
