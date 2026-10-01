<?php
/**
 * Category importer.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Category;

/**
 * Imports categories.json: validate everything first, then write.
 *
 * Identity is the full slug path, so a second run finds and updates the same
 * categories. Shape B resolver term IDs are derived from the portable paths
 * after every category exists; IDs are never read from the file.
 */
final class CategoryImporter {

	private const REQUIRED = array( 'path', 'name_en', 'name_hi', 'name_alt', 'slug', 'order', 'visible', 'featured', 'image', 'seo_title', 'seo_desc', 'seo_noindex' );

	private const FACETS = array( 'rj_metal', 'rj_purity', 'rj_occasion', 'rj_audience' );

	private const META = array(
		'order'       => '_rj_order',
		'visible'     => '_rj_visible',
		'featured'    => '_rj_featured',
		'name_hi'     => '_rj_name_hi',
		'name_en'     => '_rj_name_en',
		'name_alt'    => '_rj_name_alt',
		'image'       => '_rj_image_id',
		'seo_title'   => '_rj_seo_title',
		'seo_desc'    => '_rj_seo_desc',
		'seo_noindex' => '_rj_seo_noindex',
	);

	/**
	 * Builds the importer.
	 *
	 * @param CategoryRepository $repository Category repository.
	 */
	public function __construct( private CategoryRepository $repository ) {
	}

	/**
	 * Checks records without writing anything.
	 *
	 * @param array<int, array<string, mixed>> $records Records.
	 * @return array<int, string> Problems; empty when valid.
	 */
	public function validate( array $records ): array {
		$errors = array();
		$paths  = array();

		foreach ( $records as $index => $record ) {
			$errors = array_merge( $errors, $this->validate_record( $index, $record ) );

			if ( isset( $record['path'] ) && is_string( $record['path'] ) ) {
				if ( isset( $paths[ $record['path'] ] ) ) {
					$errors[] = "Duplicate path {$record['path']}.";
				}

				$paths[ $record['path'] ] = true;
			}
		}

		foreach ( array_keys( $paths ) as $path ) {
			$cut = strrpos( $path, '/' );

			if ( false !== $cut && ! isset( $paths[ substr( $path, 0, $cut ) ] ) ) {
				$errors[] = "Missing parent for {$path}.";
			}
		}

		foreach ( $records as $record ) {
			foreach ( $this->shape_b_paths( $record ) as $target ) {
				if ( ! isset( $paths[ $target ] ) ) {
					$errors[] = "Resolver path {$target} is not in the file.";
				}
			}
		}

		return $errors;
	}

	/**
	 * Imports records.
	 *
	 * @param array<int, array<string, mixed>> $records Records.
	 * @return array{created: int, updated: int}
	 * @throws \InvalidArgumentException When validation fails; nothing is written.
	 */
	public function import( array $records ): array {
		$errors = $this->validate( $records );

		if ( array() !== $errors ) {
			throw new \InvalidArgumentException( esc_html( implode( ' ', $errors ) ) );
		}

		usort(
			$records,
			static fn( array $a, array $b ): int => substr_count( (string) $a['path'], '/' ) <=> substr_count( (string) $b['path'], '/' )
		);

		$created = 0;
		$updated = 0;
		$ids     = array();

		add_filter( 'wp_unique_term_slug', array( $this, 'keep_slug' ), 10, 3 );

		try {
			foreach ( $records as $record ) {
				$path = CategoryPath::from_string( (string) $record['path'] );
				$name = '' !== $record['name_hi'] ? (string) $record['name_hi'] : (string) $record['name_en'];
				$id   = $this->repository->find( $path );

				if ( null === $id ) {
					$parent = $path->parent();
					$id     = $this->repository->create( $name, $path->leaf(), null === $parent ? 0 : (int) $this->repository->find( $parent ) );
					++$created;
				} else {
					$this->repository->rename( $id, $name );
					++$updated;
				}

				$ids[ $path->to_string() ] = $id;

				foreach ( self::META as $field => $key ) {
					$this->repository->set_meta( $id, $key, $record[ $field ] );
				}
			}

			foreach ( $records as $record ) {
				if ( ! empty( $record['resolver'] ) ) {
					$this->repository->set_meta( $ids[ (string) $record['path'] ], '_rj_resolver', $this->resolve( $record['resolver'], $ids ) );
				}
			}
		} finally {
			remove_filter( 'wp_unique_term_slug', array( $this, 'keep_slug' ), 10 );
		}

		return array(
			'created' => $created,
			'updated' => $updated,
		);
	}

	/**
	 * Keeps the requested slug unless a sibling under the same parent already has it.
	 *
	 * WordPress otherwise appends a suffix when two categories under different
	 * parents share a slug, which would break path identity.
	 *
	 * @param string    $slug     Slug WordPress chose.
	 * @param \stdClass $term     Term arguments.
	 * @param string    $original Slug that was requested.
	 * @return string
	 */
	public function keep_slug( string $slug, $term, string $original ): string {
		if ( CategoryRepository::TAXONOMY !== ( $term->taxonomy ?? '' ) ) {
			return $slug;
		}

		$siblings = get_terms(
			array(
				'taxonomy'   => CategoryRepository::TAXONOMY,
				'slug'       => $original,
				'parent'     => (int) ( $term->parent ?? 0 ),
				'exclude'    => array( (int) ( $term->term_id ?? 0 ) ),
				'hide_empty' => false,
				'fields'     => 'ids',
			)
		);

		return is_array( $siblings ) && array() === $siblings ? $original : $slug;
	}

	/**
	 * Problems with one record.
	 *
	 * @param int                  $index  Position.
	 * @param array<string, mixed> $record Record.
	 * @return array<int, string>
	 */
	private function validate_record( int $index, array $record ): array {
		$errors = array();

		foreach ( self::REQUIRED as $field ) {
			if ( ! array_key_exists( $field, $record ) ) {
				$errors[] = "Record {$index} is missing {$field}.";
			}
		}

		if ( array() !== $errors ) {
			return $errors;
		}

		try {
			$path = CategoryPath::from_string( (string) $record['path'] );
		} catch ( \InvalidArgumentException $e ) {
			return array( "Record {$index} has an invalid path." );
		}

		if ( $record['slug'] !== $path->leaf() ) {
			$errors[] = "Record {$index} slug does not match its path.";
		}

		if ( '' === $record['name_hi'] && '' === $record['name_en'] ) {
			$errors[] = "Record {$index} has no name.";
		}

		if ( ! empty( $record['resolver'] ) && ! $this->valid_resolver( $record['resolver'] ) ) {
			$errors[] = "Record {$index} has an invalid resolver.";
		}

		return $errors;
	}

	/**
	 * Whether a resolver is Shape A or Shape B.
	 *
	 * @param mixed $resolver Resolver value.
	 * @return bool
	 */
	private function valid_resolver( mixed $resolver ): bool {
		if ( ! is_array( $resolver ) ) {
			return false;
		}

		if ( array( 'tax', 'slug' ) === array_keys( $resolver ) ) {
			return in_array( $resolver['tax'], self::FACETS, true ) && is_string( $resolver['slug'] ) && '' !== $resolver['slug'];
		}

		return array( 'terms', 'paths' ) === array_keys( $resolver ) && is_array( $resolver['paths'] ) && count( $resolver['paths'] ) >= 2 && count( $resolver['paths'] ) === count( array_unique( $resolver['paths'] ) );
	}

	/**
	 * Shape B target paths of a record.
	 *
	 * @param array<string, mixed> $record Record.
	 * @return array<int, string>
	 */
	private function shape_b_paths( array $record ): array {
		$resolver = $record['resolver'] ?? null;

		return is_array( $resolver ) && isset( $resolver['paths'] ) && is_array( $resolver['paths'] ) ? array_map( 'strval', $resolver['paths'] ) : array();
	}

	/**
	 * Resolver ready to store: Shape B term IDs derived from paths.
	 *
	 * @param mixed              $resolver Resolver from the file.
	 * @param array<string, int> $ids      Term IDs by path.
	 * @return array<string, mixed>
	 * @throws \RuntimeException When a path has no term.
	 */
	private function resolve( mixed $resolver, array $ids ): array {
		if ( ! is_array( $resolver ) || ! isset( $resolver['paths'] ) ) {
			return is_array( $resolver ) ? $resolver : array();
		}

		$terms = array();

		foreach ( $resolver['paths'] as $path ) {
			if ( ! isset( $ids[ $path ] ) ) {
				throw new \RuntimeException( esc_html( "Resolver path {$path} has no term." ) );
			}

			$terms[] = $ids[ $path ];
		}

		return array(
			'terms' => $terms,
			'paths' => array_values( $resolver['paths'] ),
		);
	}
}
