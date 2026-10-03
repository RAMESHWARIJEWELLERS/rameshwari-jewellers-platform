<?php
/**
 * Upload validation result.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Services\Media\Value;

/**
 * The outcome of one upload check: passed, or failed with a stable code.
 * Carries no WordPress state and no message text.
 */
final class ValidationResult {

	/**
	 * Builds a result. Use passed() or failed().
	 *
	 * @param bool   $valid Whether the check passed.
	 * @param string $code  Stable error code, empty when passed.
	 */
	private function __construct( private readonly bool $valid, private readonly string $code ) {
	}

	/**
	 * A passing result.
	 *
	 * @return self
	 */
	public static function passed(): self {
		return new self( true, '' );
	}

	/**
	 * A failing result.
	 *
	 * @param string $code Stable error code. Its syntax is not frozen; it must not be empty.
	 * @return self
	 * @throws \InvalidArgumentException When the code is empty.
	 */
	public static function failed( string $code ): self {
		if ( '' === $code ) {
			throw new \InvalidArgumentException( 'A failure needs a non-empty error code.' );
		}

		return new self( false, $code );
	}

	/**
	 * Whether the check passed.
	 *
	 * @return bool
	 */
	public function is_valid(): bool {
		return $this->valid;
	}

	/**
	 * The error code, empty when passed.
	 *
	 * @return string
	 */
	public function code(): string {
		return $this->code;
	}

	/**
	 * Value equality.
	 *
	 * @param ValidationResult $other Other result.
	 * @return bool
	 */
	public function equals( ValidationResult $other ): bool {
		return $this->valid === $other->valid && $this->code === $other->code;
	}
}
