<?php
/**
 * Product domain service.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Product;

use Rameshwari\Core\Category\AssignmentGuard;
use Rameshwari\Core\Data\Meta;
use Rameshwari\Core\Support\Lock;

/**
 * The domain boundary for products: validate, uniqueness, publication
 * history, name sync, category and facet rules, publication requirements,
 * then persistence. Category rules are AssignmentGuard's; none are copied.
 *
 * @phpstan-import-type ProductState from ProductRepository
 */
final class ProductService {

	/**
	 * Builds the service.
	 *
	 * @param ProductRepository $repository Persistence.
	 * @param AssignmentGuard   $guard      Stage 5 category rules.
	 * @param Lock              $lock       Cooperative lock for code claims.
	 */
	public function __construct( private ProductRepository $repository, private AssignmentGuard $guard, private Lock $lock ) {
	}

	/**
	 * Creates (null id) or updates a product.
	 *
	 * @param ProductData $data Allow-listed input.
	 * @param int|null    $id   Existing product, or null to create.
	 * @return int|\WP_Error Product ID, a 409 rj_conflict, or a 400 rj_invalid.
	 */
	public function save( ProductData $data, ?int $id = null ): int|\WP_Error {
		$current = null === $id ? null : $this->repository->find( $id );

		if ( null !== $id && null === $current ) {
			return new \WP_Error( 'rj_not_found', 'Product not found.', array( 'status' => 404 ) );
		}

		$code  = (string) $data->get( 'code', '' );
		$name  = 'pcode-' . substr( md5( $code ), 0, 16 );
		$token = null;

		if ( $data->has( 'code' ) && '' !== $code ) {
			$token = $this->lock->acquire( $name, 15 );

			if ( null === $token ) {
				return $this->conflict( "Product Code {$code} is being claimed by another request." );
			}
		}

		try {
			return $this->persist( $data, $id, $current );
		} finally {
			if ( null !== $token ) {
				$this->lock->release( $name, $token );
			}
		}
	}

	/**
	 * Moves a product to trash. The Product Code stays reserved.
	 *
	 * @param int $id Product ID.
	 * @return bool
	 */
	public function trash( int $id ): bool {
		return $this->repository->trash( $id );
	}

	/**
	 * Whether the product has ever been published. The authority for D1.
	 *
	 * A product that is published now but carries no marker is a legacy
	 * case: the marker is established on this first observation as a legacy
	 * backfill. Its value is the observation time, not a historical date.
	 *
	 * @param int $id Product ID.
	 * @return bool
	 */
	public function has_ever_been_published( int $id ): bool {
		if ( '' !== (string) get_post_meta( $id, Meta::PUBLICATION_MARKER, true ) ) {
			return true;
		}

		if ( 'publish' === get_post_status( $id ) ) {
			$this->record_first_publication( $id );

			return true;
		}

		return false;
	}

	/**
	 * Adds the marker if absent. Never replaces or clears it.
	 *
	 * @param int $id Product ID.
	 * @return void
	 */
	public function record_first_publication( int $id ): void {
		ProductSystemScope::run(
			static fn() => add_post_meta( $id, Meta::PUBLICATION_MARKER, wp_date( 'Y-m-d H:i:s' ), true )
		);
	}

	/**
	 * Whether the product is shown publicly: published and public.
	 *
	 * @param int $id Product ID.
	 * @return bool
	 */
	public function is_public( int $id ): bool {
		$state = $this->repository->find( $id );

		return null !== $state && 'publish' === $state['status'] && 'public' === $state['visibility'];
	}

	/**
	 * The canonical public-read meta clause: visibility is public, or unset
	 * (the registered default is public). Combined with post_status = publish
	 * by WordPress, this is the rule is_public() states for one product.
	 *
	 * @return array<int|string, mixed>
	 */
	public function public_visibility_clause(): array {
		return array(
			'relation' => 'OR',
			array(
				'key'   => '_rj_visibility',
				'value' => 'public',
			),
			array(
				'key'     => '_rj_visibility',
				'compare' => 'NOT EXISTS',
			),
		);
	}

	/**
	 * Post-write recheck of a Product Code claim. When two writes both passed
	 * the pre-write check, the lowest product ID keeps the code and the other
	 * loses it (and goes back to draft if it was published). Idempotent.
	 *
	 * @param int $id Product just written.
	 * @return bool True when this product lost its code.
	 */
	public function resolve_code_race( int $id ): bool {
		$state = $this->repository->find( $id );

		if ( null === $state || '' === $state['code'] ) {
			return false;
		}

		$winner = $this->repository->first_id_for_code( $state['code'] );

		if ( null === $winner || $winner === $id ) {
			return false;
		}

		ProductSystemScope::run(
			static fn() => delete_post_meta( $id, '_rj_code' )
		);

		if ( 'publish' === $state['status'] ) {
			wp_update_post(
				array(
					'ID'          => $id,
					'post_status' => 'draft',
				)
			);
		}

		return true;
	}

	/**
	 * Why a Product Code cannot be used, or null.
	 *
	 * @param string   $raw Raw code.
	 * @param int|null $id  Product being written, or null for a new one.
	 * @return \WP_Error|null
	 */
	public function code_conflict( string $raw, ?int $id ): ?\WP_Error {
		$code = ProductCode::normalise( $raw );

		if ( '' !== $code && $this->repository->code_exists( $code, $id ) ) {
			return $this->conflict( "Product Code {$code} is already in use." );
		}

		$current = null === $id ? null : $this->repository->find( $id );

		if ( null !== $current && '' !== $current['code'] && $current['code'] !== $code && $this->has_ever_been_published( (int) $id ) ) {
			return $this->conflict( 'The Product Code of a product that has been published is permanent.' );
		}

		return null;
	}

	/**
	 * What a product still needs before it can be published.
	 *
	 * @param array<string, mixed> $state code, name_en, name_hi, metal, purity, categories, primary.
	 * @return array<int, string>
	 */
	public function missing_for_publication( array $state ): array {
		$gaps = array();

		foreach (
			array(
				'code'    => 'Product Code',
				'name_en' => 'English name',
				'name_hi' => 'Hindi name',
			) as $key => $label
		) {
			if ( '' === (string) ( $state[ $key ] ?? '' ) ) {
				$gaps[] = $label;
			}
		}

		foreach (
			array(
				'metal'  => 'exactly one Metal',
				'purity' => 'exactly one Purity',
			) as $key => $label
		) {
			$ids = (array) ( $state[ $key ] ?? array() );

			if ( 1 !== count( $ids ) || ! get_term( (int) reset( $ids ), 'metal' === $key ? 'rj_metal' : 'rj_purity' ) instanceof \WP_Term ) {
				$gaps[] = $label;
			}
		}

		$categories = array_map( 'intval', (array) ( $state['categories'] ?? array() ) );

		if ( array() === $categories || array() !== $this->guard->check( $categories ) ) {
			$gaps[] = 'at least one editorial category';
		}

		return $gaps;
	}

	/**
	 * Publication gaps for a stored product.
	 *
	 * @param int $id Product ID.
	 * @return array<int, string>
	 */
	public function publication_gaps( int $id ): array {
		$state = $this->repository->find( $id );

		return null === $state ? array( 'a product' ) : $this->missing_for_publication( $state );
	}

	/**
	 * Category violations for a proposed assignment, via the Stage 5 guard.
	 *
	 * @param array<int, int> $term_ids Category IDs.
	 * @param int             $primary  Primary term, 0 for none.
	 * @return array<int, string>
	 */
	public function category_violations( array $term_ids, int $primary = 0 ): array {
		return $this->guard->check( $term_ids, $primary );
	}

	/**
	 * The 409 error for violations.
	 *
	 * @param array<int, string> $violations Violations.
	 * @return \WP_Error
	 */
	public function category_error( array $violations ): \WP_Error {
		return $this->guard->error( $violations );
	}

	/**
	 * The validated save pipeline.
	 *
	 * @param ProductData               $data    Input.
	 * @param int|null                  $id      Product, or null.
	 * @param array<string, mixed>|null $current Stored state.
	 * @return int|\WP_Error
	 */
	private function persist( ProductData $data, ?int $id, ?array $current ): int|\WP_Error {
		if ( $data->has( 'code' ) ) {
			$error = $this->code_conflict( (string) $data->get( 'code' ), $id );

			if ( null !== $error ) {
				return $error;
			}
		}

		$state = array(
			'code'       => $data->has( 'code' ) ? (string) $data->get( 'code' ) : (string) ( $current['code'] ?? '' ),
			'name_en'    => $data->has( 'name_en' ) ? (string) $data->get( 'name_en' ) : (string) ( $current['name_en'] ?? '' ),
			'name_hi'    => $data->has( 'name_hi' ) ? (string) $data->get( 'name_hi' ) : (string) ( $current['name_hi'] ?? '' ),
			'metal'      => $data->has( 'metal' ) ? (array) $data->get( 'metal' ) : (array) ( $current['metal'] ?? array() ),
			'purity'     => $data->has( 'purity' ) ? (array) $data->get( 'purity' ) : (array) ( $current['purity'] ?? array() ),
			'categories' => $data->has( 'categories' ) ? (array) $data->get( 'categories' ) : (array) ( $current['categories'] ?? array() ),
			'primary'    => $data->has( 'primary_term' ) ? (int) $data->get( 'primary_term' ) : (int) ( $current['primary'] ?? 0 ),
		);

		foreach ( array( 'metal', 'purity' ) as $facet ) {
			if ( count( $state[ $facet ] ) > 1 ) {
				return $this->conflict( "A product may have at most one {$facet}." );
			}
		}

		$categories = array_map( 'intval', $state['categories'] );
		$violations = $this->guard->check( $categories, $state['primary'] );

		if ( $state['primary'] > 0 && ! in_array( $state['primary'], $categories, true ) ) {
			$violations[] = 'The primary category must be one of the assigned categories.';
		}

		if ( array() !== $violations ) {
			return $this->guard->error( $violations );
		}

		$target = $data->has( 'status' ) ? (string) $data->get( 'status' ) : (string) ( $current['status'] ?? 'draft' );

		if ( 'publish' === $target ) {
			$gaps = $this->missing_for_publication( $state );

			if ( array() !== $gaps ) {
				return new \WP_Error( 'rj_invalid', 'Cannot publish: needs ' . implode( ', ', $gaps ) . '.', array( 'status' => 400 ) );
			}
		}

		if ( '' !== $state['name_en'] ) {
			$data = $data->with( 'title', $state['name_en'] );
		}

		if ( null === $id ) {
			$created = $this->repository->create( $data );

			if ( $created instanceof \WP_Error ) {
				return $created;
			}

			$id = $created;

			if ( 'publish' === $target ) {
				$this->repository->update( $id, ProductData::from_array( array( 'status' => 'publish' ) ) );
			}
		} else {
			$result = $this->repository->update( $id, $data );

			if ( $result instanceof \WP_Error ) {
				return $result;
			}
		}

		if ( 'publish' === get_post_status( $id ) ) {
			$this->record_first_publication( $id );
		}

		return $id;
	}

	/**
	 * A 409 rj_conflict.
	 *
	 * @param string $message Message.
	 * @return \WP_Error
	 */
	private function conflict( string $message ): \WP_Error {
		return new \WP_Error( 'rj_conflict', $message, array( 'status' => 409 ) );
	}
}
