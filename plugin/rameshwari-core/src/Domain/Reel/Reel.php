<?php
/**
 * Reel model.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Domain\Reel;

/**
 * One reel, read from its post and registered meta. Immutable.
 *
 * Source_type says where the video came from. The video itself is an uploaded
 * attachment, a provider video ID or a URL, and the poster is a separate
 * attachment. Lifecycle is draft, published, expired: expiry is derived from
 * _rj_expires_at and the clock passed in, never stored.
 */
final class Reel {

	public const SOURCE_TYPES = array( 'upload', 'instagram', 'youtube', 'url' );

	public const VISIBILITIES = array( 'public', 'hidden', 'archived' );

	public const STATE_DRAFT = 'draft';

	public const STATE_PUBLISHED = 'published';

	public const STATE_EXPIRED = 'expired';

	/**
	 * Builds a reel.
	 *
	 * @param int            $id              Post ID.
	 * @param string         $status          WordPress post status.
	 * @param string         $source_type     upload, instagram, youtube or url.
	 * @param string         $source_url      Source URL, or empty.
	 * @param string         $video_id        Provider video ID, or empty.
	 * @param int            $attachment_id   Uploaded video attachment, or 0.
	 * @param int            $poster_id       Poster attachment, or 0.
	 * @param array<int,int> $linked_products Linked product IDs.
	 * @param string         $caption         Caption.
	 * @param string         $visibility      public, hidden or archived.
	 * @param string         $expires_at      Y-m-d H:i:s in the site timezone, or empty.
	 * @throws \InvalidArgumentException When a field is outside its contract.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $status,
		public readonly string $source_type,
		public readonly string $source_url,
		public readonly string $video_id,
		public readonly int $attachment_id,
		public readonly int $poster_id,
		public readonly array $linked_products,
		public readonly string $caption,
		public readonly string $visibility,
		public readonly string $expires_at
	) {
		if ( $id < 1 || '' === $status ) {
			throw new \InvalidArgumentException( 'A reel needs a positive ID and a status.' );
		}

		if ( ! in_array( $source_type, self::SOURCE_TYPES, true ) ) {
			throw new \InvalidArgumentException( 'Invalid reel source type.' );
		}

		if ( ! in_array( $visibility, self::VISIBILITIES, true ) ) {
			throw new \InvalidArgumentException( 'Invalid reel visibility.' );
		}

		if ( $attachment_id < 0 || $poster_id < 0 ) {
			throw new \InvalidArgumentException( 'Attachment IDs cannot be negative.' );
		}

		foreach ( $linked_products as $product ) {
			if ( ! is_int( $product ) || $product < 1 ) {
				throw new \InvalidArgumentException( 'Linked products must be positive IDs.' );
			}
		}

		if ( '' !== $expires_at && false === \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $expires_at ) ) {
			throw new \InvalidArgumentException( 'Expiry must be Y-m-d H:i:s.' );
		}
	}

	/**
	 * Whether the reel plays from an uploaded attachment.
	 *
	 * @return bool
	 */
	public function is_upload(): bool {
		return 'upload' === $this->source_type;
	}

	/**
	 * Whether the reel has a poster of its own.
	 *
	 * @return bool
	 */
	public function has_poster(): bool {
		return $this->poster_id > 0;
	}

	/**
	 * Whether the expiry has passed. Read in the timezone of the clock given.
	 *
	 * @param \DateTimeImmutable $now Current time.
	 * @return bool
	 */
	public function is_expired( \DateTimeImmutable $now ): bool {
		if ( '' === $this->expires_at ) {
			return false;
		}

		$expiry = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $this->expires_at, $now->getTimezone() );

		return false !== $expiry && $expiry <= $now;
	}

	/**
	 * Lifecycle state: draft, published or expired.
	 *
	 * @param \DateTimeImmutable $now Current time.
	 * @return string
	 */
	public function state( \DateTimeImmutable $now ): string {
		if ( 'publish' !== $this->status ) {
			return self::STATE_DRAFT;
		}

		return $this->is_expired( $now ) ? self::STATE_EXPIRED : self::STATE_PUBLISHED;
	}
}
