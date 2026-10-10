<?php
/**
 * Message context resolver.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\WhatsApp;

use Rameshwari\Core\Data\Options;
use Rameshwari\Core\Domain\Reel\ReelRepository;
use Rameshwari\Core\Domain\Showroom\ShowroomRepository;
use Rameshwari\Core\Product\ProductRepository;

/**
 * Turns a message type and an object ID into the real values its template may use.
 *
 * Everything comes from the existing product, reel, showroom and collection
 * contracts, on the server. A hidden, draft or unknown object resolves to null.
 * Weight appears only while the public-weight setting is on. The page URL is the
 * object's own permalink; a bridal enquiry has no object and uses the home page.
 */
final class ContextResolver {

	private const TYPES = array(
		'reel'       => 'rj_reel',
		'collection' => 'rj_collection',
		'showroom'   => 'rj_showroom',
	);

	/**
	 * Resolves a context.
	 *
	 * @param string $type      Message type.
	 * @param int    $object_id Object ID, ignored for bridal.
	 * @return array{values: array<string,string>, override: string, code: string, object: int, page: string}|null
	 */
	public function resolve( string $type, int $object_id ): ?array {
		if ( 'bridal' === $type ) {
			return $this->done( array( 'url' => home_url( '/' ) ), '', '', 0, home_url( '/' ) );
		}

		if ( 'product' === $type ) {
			return $this->product( $object_id );
		}

		$expected = self::TYPES[ $type ] ?? null;

		if ( null === $expected || get_post_type( $object_id ) !== $expected || 'publish' !== get_post_status( $object_id ) ) {
			return null;
		}

		$url   = (string) get_permalink( $object_id );
		$title = (string) get_the_title( $object_id );

		if ( 'reel' === $type ) {
			return $this->reel( $object_id, $title, $url );
		}

		if ( 'showroom' === $type ) {
			return $this->showroom( $object_id, $title, $url );
		}

		return $this->done(
			array(
				'name' => $title,
				'url'  => $url,
			),
			'',
			'',
			$object_id,
			$url
		);
	}

	/**
	 * A public published product.
	 *
	 * @param int $id Product ID.
	 * @return array<string,mixed>|null
	 */
	private function product( int $id ): ?array {
		$product = ( new ProductRepository() )->find( $id );

		if ( null === $product || 'publish' !== $product['status'] || 'public' !== $product['visibility'] ) {
			return null;
		}

		$url    = (string) get_permalink( $id );
		$values = array(
			'code'    => (string) $product['code'],
			'name_hi' => (string) $product['name_hi'],
			'name_en' => (string) $product['name_en'],
			'metal'   => $this->term_name( $product['metal'] ),
			'purity'  => $this->term_name( $product['purity'] ),
			'weight'  => $this->weight( $id ),
			'url'     => $url,
		);

		return $this->done( $values, (string) get_post_meta( $id, '_rj_whatsapp_message', true ), (string) $product['code'], $id, $url );
	}

	/**
	 * A reel with the codes of its public linked products.
	 *
	 * @param int    $id    Reel ID.
	 * @param string $title Reel title.
	 * @param string $url   Permalink.
	 * @return array<string,mixed>|null
	 */
	private function reel( int $id, string $title, string $url ): ?array {
		try {
			$reel = ( new ReelRepository() )->find( $id );
		} catch ( \InvalidArgumentException $failure ) {
			return null;
		}

		if ( null === $reel || 'public' !== $reel->visibility ) {
			return null;
		}

		$repository = new ProductRepository();
		$codes      = array();

		foreach ( $reel->linked_products as $product_id ) {
			$product = $repository->find( $product_id );

			if ( null !== $product && 'publish' === $product['status'] && 'public' === $product['visibility'] && '' !== $product['code'] ) {
				$codes[] = (string) $product['code'];
			}
		}

		return $this->done(
			array(
				'title'        => $title,
				'url'          => $url,
				'linked_codes' => implode( ', ', $codes ),
			),
			'',
			'',
			$id,
			$url
		);
	}

	/**
	 * A showroom with its stored address.
	 *
	 * @param int    $id    Showroom ID.
	 * @param string $title Showroom title.
	 * @param string $url   Permalink.
	 * @return array<string,mixed>|null
	 */
	private function showroom( int $id, string $title, string $url ): ?array {
		try {
			$showroom = ( new ShowroomRepository() )->find( $id );
		} catch ( \InvalidArgumentException $failure ) {
			return null;
		}

		if ( null === $showroom ) {
			return null;
		}

		$place   = trim( $showroom->city . ' ' . $showroom->state . ' ' . $showroom->pincode );
		$address = implode( ', ', array_filter( array( $showroom->address, $place ), static fn( string $part ): bool => '' !== $part ) );

		return $this->done(
			array(
				'branch'  => $title,
				'address' => $address,
				'url'     => $url,
			),
			'',
			'',
			$id,
			$url
		);
	}

	/**
	 * The name of the first assigned term, never its ID.
	 *
	 * @param mixed $ids Term IDs.
	 * @return string
	 */
	private function term_name( mixed $ids ): string {
		$first = is_array( $ids ) && array() !== $ids ? (int) reset( $ids ) : 0;
		$term  = $first > 0 ? get_term( $first ) : null;

		return $term instanceof \WP_Term ? $term->name : '';
	}

	/**
	 * Weight text, only while the public-weight setting is on.
	 *
	 * @param int $id Product ID.
	 * @return string
	 */
	private function weight( int $id ): string {
		$display = Options::get( 'rj_display' );

		if ( true !== ( $display['show_weight_publicly'] ?? false ) ) {
			return '';
		}

		$weight = get_post_meta( $id, '_rj_weight', true );

		if ( ! is_numeric( $weight ) || (float) $weight <= 0 ) {
			return '';
		}

		$text = (string) $weight;

		if ( str_contains( $text, '.' ) ) {
			$text = rtrim( rtrim( $text, '0' ), '.' );
		}

		return trim( $text . ' ' . (string) get_post_meta( $id, '_rj_weight_unit', true ) );
	}

	/**
	 * Builds the result array.
	 *
	 * @param array<string,string> $values    Token values.
	 * @param string               $override  Product message override.
	 * @param string               $code      Object code.
	 * @param int                  $object_id Object ID.
	 * @param string               $page      Page URL.
	 * @return array{values: array<string,string>, override: string, code: string, object: int, page: string}
	 */
	private function done( array $values, string $override, string $code, int $object_id, string $page ): array {
		return array(
			'values'   => $values,
			'override' => $override,
			'code'     => $code,
			'object'   => $object_id,
			'page'     => $page,
		);
	}
}
