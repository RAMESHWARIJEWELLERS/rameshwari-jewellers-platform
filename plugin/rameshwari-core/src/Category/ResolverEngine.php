<?php
/**
 * Resolver engine.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Category;

/**
 * Reads, resolves and rebuilds _rj_resolver. The one place resolver logic lives.
 *
 * Widgets, blocks, shortcodes and templates call this; they never repeat it.
 * Portable paths are the source of truth. Runtime term IDs are derived from
 * them on every resolve and rebuild.
 */
final class ResolverEngine {

	/**
	 * Builds the engine.
	 *
	 * @param CategoryRepository $repository Category repository.
	 */
	public function __construct( private CategoryRepository $repository ) {
	}

	/**
	 * Whether a category term carries any resolver value, valid or not.
	 *
	 * @param int $term_id Term ID.
	 * @return bool
	 */
	public function is_resolver_term( int $term_id ): bool {
		return ! in_array( $this->repository->get_meta( $term_id, '_rj_resolver' ), array( '', array(), null, false ), true );
	}

	/**
	 * The parsed definition, or null when the term has none.
	 *
	 * @param int $term_id Term ID.
	 * @return ResolverDefinition|null
	 * @throws \InvalidArgumentException When a stored value is malformed.
	 */
	public function definition( int $term_id ): ?ResolverDefinition {
		if ( ! $this->is_resolver_term( $term_id ) ) {
			return null;
		}

		return ResolverDefinition::parse( $this->repository->get_meta( $term_id, '_rj_resolver' ) );
	}

	/**
	 * What the resolver currently points at.
	 *
	 * @param int $term_id Resolver term ID.
	 * @return Resolution
	 */
	public function resolve( int $term_id ): Resolution {
		try {
			$definition = $this->definition( $term_id );
		} catch ( \InvalidArgumentException $e ) {
			return new Resolution( '', array(), null, array( $e->getMessage() ) );
		}

		if ( null === $definition ) {
			return new Resolution( '', array(), null, array( 'Term has no resolver.' ) );
		}

		if ( ResolverDefinition::FACET === $definition->kind() ) {
			$facet = $this->repository->facet_term_id( $definition->tax(), $definition->slug() );

			return new Resolution(
				ResolverDefinition::FACET,
				array(),
				$facet,
				null === $facet ? array( "Facet term {$definition->tax()}:{$definition->slug()} does not exist." ) : array()
			);
		}

		$derived  = $this->derive( $definition );
		$problems = $derived['problems'];

		if ( array() === $problems && $definition->terms() !== $derived['ids'] ) {
			$problems[] = 'Stored term IDs differ from the portable paths; rebuild the resolver.';
		}

		return new Resolution( ResolverDefinition::UNION, $derived['ids'], null, $problems );
	}

	/**
	 * Re-derives a Shape B resolver's term IDs from its paths and stores them.
	 *
	 * Used after an import, a migration or any change of environment.
	 *
	 * @param int $term_id Resolver term ID.
	 * @return ResolverDefinition The stored definition.
	 * @throws \InvalidArgumentException When the term has no valid resolver.
	 * @throws \RuntimeException         When a path no longer resolves; nothing is written.
	 */
	public function rebuild( int $term_id ): ResolverDefinition {
		$definition = $this->definition( $term_id );

		if ( null === $definition ) {
			throw new \InvalidArgumentException( 'Term has no resolver.' );
		}

		if ( ResolverDefinition::FACET === $definition->kind() ) {
			return $definition;
		}

		$derived = $this->derive( $definition );

		if ( array() !== $derived['problems'] ) {
			throw new \RuntimeException( esc_html( implode( ' ', $derived['problems'] ) ) );
		}

		$rebuilt = $definition->with_terms( $derived['ids'] );
		$this->repository->set_meta( $term_id, '_rj_resolver', $rebuilt->to_array() );

		return $rebuilt;
	}

	/**
	 * Resolves every resolver term and lists what is broken, by category path.
	 *
	 * @return array{count: int, problems: array<string, array<int, string>>}
	 */
	public function verify_all(): array {
		$count    = 0;
		$problems = array();

		foreach ( $this->repository->all_ids() as $term_id ) {
			if ( ! $this->is_resolver_term( $term_id ) ) {
				continue;
			}

			++$count;
			$resolution = $this->resolve( $term_id );

			if ( ! $resolution->ok() ) {
				$problems[ $this->repository->path_of( $term_id ) ] = $resolution->problems();
			}
		}

		return array(
			'count'    => $count,
			'problems' => $problems,
		);
	}

	/**
	 * Current term IDs for a Shape B definition's paths.
	 *
	 * @param ResolverDefinition $definition Shape B definition.
	 * @return array{ids: array<int,int>, problems: array<int,string>}
	 */
	private function derive( ResolverDefinition $definition ): array {
		$ids      = array();
		$problems = array();

		foreach ( $definition->paths() as $path ) {
			$id = $this->repository->find( CategoryPath::from_string( $path ) );

			if ( null === $id ) {
				$problems[] = "Path {$path} does not resolve.";
				continue;
			}

			$ids[] = $id;
		}

		return array(
			'ids'      => $ids,
			'problems' => $problems,
		);
	}
}
