<?php
/**
 * Resolved reel source.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Domain\Reel;

/**
 * The outcome of resolving a reel source: resolved, invalid or degraded.
 *
 * Degraded means the source looks valid but its provider could not be reached.
 * It is not an error for visitors: the caller keeps the canonical URL and shows
 * the poster or nothing. Immutable; the code is a stable machine value, never
 * remote error text.
 */
final class ReelSource {

	public const RESOLVED = 'resolved';

	public const INVALID = 'invalid';

	public const DEGRADED = 'degraded';

	/**
	 * Builds a result.
	 *
	 * @param string $status        resolved, invalid or degraded.
	 * @param string $source_type   upload, instagram, youtube or url.
	 * @param string $code          Reason for invalid or degraded; empty when resolved.
	 * @param string $canonical_url Normalised URL, empty for uploads and invalid sources.
	 * @param string $provider_id   Provider video ID, empty when none applies.
	 * @param int    $attachment_id Attachment ID for uploads, otherwise 0.
	 * @throws \InvalidArgumentException When the fields do not fit the status.
	 */
	private function __construct(
		public readonly string $status,
		public readonly string $source_type,
		public readonly string $code,
		public readonly string $canonical_url,
		public readonly string $provider_id,
		public readonly int $attachment_id
	) {
		if ( ! in_array( $status, array( self::RESOLVED, self::INVALID, self::DEGRADED ), true ) ) {
			throw new \InvalidArgumentException( 'Unknown reel source status.' );
		}

		if ( self::RESOLVED === $status && '' !== $code ) {
			throw new \InvalidArgumentException( 'A resolved source has no code.' );
		}

		if ( self::RESOLVED !== $status && '' === $code ) {
			throw new \InvalidArgumentException( 'A failed source needs a code.' );
		}
	}

	/**
	 * A source that passed every check.
	 *
	 * @param string $source_type   Source type.
	 * @param string $canonical_url Normalised URL, or empty for an upload.
	 * @param string $provider_id   Provider ID, or empty.
	 * @param int    $attachment_id Attachment ID, or 0.
	 * @return self
	 */
	public static function resolved( string $source_type, string $canonical_url, string $provider_id, int $attachment_id ): self {
		return new self( self::RESOLVED, $source_type, '', $canonical_url, $provider_id, $attachment_id );
	}

	/**
	 * A source that failed validation.
	 *
	 * @param string $source_type Source type as requested.
	 * @param string $code        Reason.
	 * @return self
	 */
	public static function invalid( string $source_type, string $code ): self {
		return new self( self::INVALID, $source_type, $code, '', '', 0 );
	}

	/**
	 * A valid source whose provider could not be reached.
	 *
	 * @param string $source_type   Source type.
	 * @param string $code          Reason.
	 * @param string $canonical_url Normalised URL.
	 * @param string $provider_id   Provider ID, or empty.
	 * @return self
	 */
	public static function degraded( string $source_type, string $code, string $canonical_url, string $provider_id ): self {
		return new self( self::DEGRADED, $source_type, $code, $canonical_url, $provider_id, 0 );
	}

	/**
	 * Whether every check passed.
	 *
	 * @return bool
	 */
	public function is_resolved(): bool {
		return self::RESOLVED === $this->status;
	}

	/**
	 * Whether the source failed validation.
	 *
	 * @return bool
	 */
	public function is_invalid(): bool {
		return self::INVALID === $this->status;
	}

	/**
	 * Whether the provider could not be reached.
	 *
	 * @return bool
	 */
	public function is_degraded(): bool {
		return self::DEGRADED === $this->status;
	}
}
