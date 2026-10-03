<?php
/**
 * Source quality result.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Services\Media\Value;

/**
 * The advisory outcome of a source-size check: adequate, or a warning that
 * names the registered sizes the source is too small for.
 *
 * It is not a validation failure. A warning means the administrator must
 * confirm before the upload proceeds (CL-8). Nothing here is stored.
 */
final class SourceQualityResult {

	/**
	 * Builds a result. Use adequate() or warning().
	 *
	 * @param array<int,string> $sizes Contract names of the sizes the source is too small for.
	 */
	private function __construct( private readonly array $sizes ) {
	}

	/**
	 * A source that suits every requested size.
	 *
	 * @return self
	 */
	public static function adequate(): self {
		return new self( array() );
	}

	/**
	 * A source too small for the named registered sizes.
	 *
	 * @param array<int,string> $sizes Contract names. At least one, none blank.
	 * @return self
	 * @throws \InvalidArgumentException When the list is empty or holds a blank name.
	 */
	public static function warning( array $sizes ): self {
		$unique = array();

		foreach ( $sizes as $name ) {
			if ( '' === $name ) {
				throw new \InvalidArgumentException( 'A size name cannot be blank.' );
			}

			if ( ! in_array( $name, $unique, true ) ) {
				$unique[] = $name;
			}
		}

		if ( array() === $unique ) {
			throw new \InvalidArgumentException( 'A quality warning must name at least one size.' );
		}

		return new self( $unique );
	}

	/**
	 * Whether the source suits every requested size.
	 *
	 * @return bool
	 */
	public function is_adequate(): bool {
		return array() === $this->sizes;
	}

	/**
	 * Whether the administrator must confirm before proceeding.
	 *
	 * @return bool
	 */
	public function requires_confirmation(): bool {
		return array() !== $this->sizes;
	}

	/**
	 * Contract names of the sizes that triggered the warning, in request order.
	 *
	 * @return array<int,string>
	 */
	public function sizes(): array {
		return $this->sizes;
	}

	/**
	 * Value equality.
	 *
	 * @param SourceQualityResult $other Other result.
	 * @return bool
	 */
	public function equals( SourceQualityResult $other ): bool {
		return $this->sizes === $other->sizes;
	}
}
