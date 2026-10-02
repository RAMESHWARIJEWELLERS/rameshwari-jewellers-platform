<?php
/**
 * Explicit allow-list input for product writes.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Product;

use Rameshwari\Core\Data\Meta;

/**
 * The only write surface for a product. Unknown keys are rejected, so no
 * client, bulk tool or importer can supply the publication marker, a title,
 * or any other field outside the contract.
 */
final class ProductData {

	/**
	 * Valid visibility values. Anything else is rejected, never coerced.
	 */
	public const VISIBILITIES = array( 'public', 'hidden', 'archived' );

	/**
	 * Allowed client keys and their sanitising rule.
	 */
	private const ALLOWED = array(
		'code'          => 'code',
		'name_en'       => 'text:191',
		'name_hi'       => 'text:191',
		'weight'        => 'decimal:3:0:99999',
		'weight_unit'   => 'enum:g|tola|carat',
		'visibility'    => 'enum:public|hidden|archived',
		'featured'      => 'bool',
		'stone_details' => 'textarea:500',
		'gallery'       => 'attachments:12',
		'primary_term'  => 'int:0:',
		'status'        => 'enum:draft|publish',
		'categories'    => 'ids',
		'metal'         => 'ids',
		'purity'        => 'ids',
	);

	/**
	 * Sanitised fields.
	 *
	 * @var array<string, mixed>
	 */
	private array $fields = array();

	/**
	 * Builds from client input.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return self
	 * @throws \InvalidArgumentException For a key outside the allow-list.
	 */
	public static function from_array( array $input ): self {
		$data = new self();

		foreach ( $input as $key => $value ) {
			if ( ! is_string( $key ) || ! isset( self::ALLOWED[ $key ] ) ) {
				throw new \InvalidArgumentException( esc_html( 'Field not allowed: ' . (string) $key ) );
			}

			$rule = self::ALLOWED[ $key ];

			if ( 'visibility' === $key && ! in_array( $value, self::VISIBILITIES, true ) ) {
				throw new \InvalidArgumentException( 'Invalid visibility: use public, hidden or archived.' );
			}

			$data->fields[ $key ] = 'ids' === $rule
				? array_values( array_unique( array_filter( array_map( 'absint', is_array( $value ) ? $value : array( $value ) ) ) ) )
				: Meta::sanitize( $rule, $value );
		}

		return $data;
	}

	/**
	 * A copy carrying a domain-derived field (the title). Never client input.
	 *
	 * @param string $key   Internal key.
	 * @param mixed  $value Value.
	 * @return self
	 */
	public function with( string $key, mixed $value ): self {
		$copy = clone $this;

		$copy->fields[ $key ] = $value;

		return $copy;
	}

	/**
	 * Whether a field was supplied.
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	public function has( string $key ): bool {
		return array_key_exists( $key, $this->fields );
	}

	/**
	 * A field value, or the default.
	 *
	 * @param string $key           Key.
	 * @param mixed  $default_value Default.
	 * @return mixed
	 */
	public function get( string $key, mixed $default_value = null ): mixed {
		return $this->fields[ $key ] ?? $default_value;
	}
}
