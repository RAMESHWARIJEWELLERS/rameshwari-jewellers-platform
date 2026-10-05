<?php
/**
 * Outcome of receiving a poster image.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Services\Media\Value;

/**
 * Success with the new attachment ID, or a typed failure.
 *
 * A failure is an ordinary value: the caller carries on without a poster.
 */
final class PosterResult {

	/**
	 * Builds a result. Use succeeded() or failed().
	 *
	 * @param int    $attachment_id New attachment ID, 0 on failure.
	 * @param string $code          Stable failure code, empty on success.
	 */
	private function __construct( private readonly int $attachment_id, private readonly string $code ) {
	}

	/**
	 * A poster that was received.
	 *
	 * @param int $attachment_id Attachment ID of the poster image.
	 * @return self
	 * @throws \InvalidArgumentException When the ID is not positive.
	 */
	public static function succeeded( int $attachment_id ): self {
		if ( $attachment_id < 1 ) {
			throw new \InvalidArgumentException( 'The attachment ID must be positive.' );
		}

		return new self( $attachment_id, '' );
	}

	/**
	 * A poster that was not received.
	 *
	 * @param string $code Stable failure code. Its syntax is not frozen; it must not be empty.
	 * @return self
	 * @throws \InvalidArgumentException When the code is empty.
	 */
	public static function failed( string $code ): self {
		if ( '' === $code ) {
			throw new \InvalidArgumentException( 'A failure needs a non-empty code.' );
		}

		return new self( 0, $code );
	}

	/**
	 * Whether a poster was received.
	 *
	 * @return bool
	 */
	public function is_success(): bool {
		return '' === $this->code;
	}

	/**
	 * The poster's attachment ID, 0 when there is none.
	 *
	 * @return int
	 */
	public function attachment_id(): int {
		return $this->attachment_id;
	}

	/**
	 * The failure code, empty on success.
	 *
	 * @return string
	 */
	public function code(): string {
		return $this->code;
	}
}
