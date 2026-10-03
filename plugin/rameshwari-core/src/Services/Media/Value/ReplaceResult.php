<?php
/**
 * Replacement result.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Services\Media\Value;

/**
 * The outcome of replacing the file behind an attachment: success with the new
 * dimensions and the retention artifact name, or a typed failure.
 */
final class ReplaceResult {

	/**
	 * Builds a result. Use succeeded() or failed().
	 *
	 * @param bool   $success  Whether the replacement happened.
	 * @param int    $width    New width, 0 on failure.
	 * @param int    $height   New height, 0 on failure.
	 * @param string $artifact Retention artifact file name, empty on failure.
	 * @param string $code     Stable failure code, empty on success.
	 */
	private function __construct( private readonly bool $success, private readonly int $width, private readonly int $height, private readonly string $artifact, private readonly string $code ) {
	}

	/**
	 * A successful replacement.
	 *
	 * @param int    $width    New width in pixels.
	 * @param int    $height   New height in pixels.
	 * @param string $artifact Retention artifact file name (a base name, no path).
	 * @return self
	 * @throws \InvalidArgumentException When a dimension is not positive or the artifact is not a base name.
	 */
	public static function succeeded( int $width, int $height, string $artifact ): self {
		if ( $width < 1 || $height < 1 ) {
			throw new \InvalidArgumentException( 'Dimensions must be positive.' );
		}

		if ( '' === $artifact || basename( str_replace( '\\', '/', $artifact ) ) !== $artifact || str_contains( $artifact, '..' ) ) {
			throw new \InvalidArgumentException( 'The artifact must be a plain file name.' );
		}

		return new self( true, $width, $height, $artifact, '' );
	}

	/**
	 * A failed replacement.
	 *
	 * @param string $code Stable failure code. Its syntax is not frozen; it must not be empty.
	 * @return self
	 * @throws \InvalidArgumentException When the code is empty.
	 */
	public static function failed( string $code ): self {
		if ( '' === $code ) {
			throw new \InvalidArgumentException( 'A failure needs a non-empty code.' );
		}

		return new self( false, 0, 0, '', $code );
	}

	/**
	 * Whether the replacement happened.
	 *
	 * @return bool
	 */
	public function is_success(): bool {
		return $this->success;
	}

	/**
	 * New width, 0 on failure.
	 *
	 * @return int
	 */
	public function width(): int {
		return $this->width;
	}

	/**
	 * New height, 0 on failure.
	 *
	 * @return int
	 */
	public function height(): int {
		return $this->height;
	}

	/**
	 * Retention artifact name, empty on failure.
	 *
	 * @return string
	 */
	public function artifact(): string {
		return $this->artifact;
	}

	/**
	 * Failure code, empty on success.
	 *
	 * @return string
	 */
	public function code(): string {
		return $this->code;
	}
}
