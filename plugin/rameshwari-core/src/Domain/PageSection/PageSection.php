<?php
/**
 * Page section model.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Domain\PageSection;

/**
 * One page section read from its post and registered meta. Immutable.
 *
 * Eligibility is derived from the clock passed in and is never stored. The
 * model does not read the clock, the timezone setting or the request: it is
 * given a DateTimeImmutable and reads the stored dates in that value's timezone.
 */
final class PageSection {

	public const HERO = 'hero';

	public const KEYS = array( 'hero', 'about', 'bridal_band', 'trust_strip', 'footer_note' );

	public const ORDER_MAX = 9999;

	private const FORMAT = 'Y-m-d H:i:s';

	/**
	 * Builds a section.
	 *
	 * @param int      $id            Post ID.
	 * @param string   $status        WordPress post status.
	 * @param string   $section_key   Stored section key, valid or not.
	 * @param string   $title_hi      Hindi title.
	 * @param string   $title_en      English title.
	 * @param string   $description   Description.
	 * @param string   $cta_label     Button label.
	 * @param string   $cta_url       Button target.
	 * @param int      $image_desktop Desktop attachment ID, or 0.
	 * @param int      $image_mobile  Mobile attachment ID, or 0.
	 * @param bool     $active        The _rj_active flag.
	 * @param string   $starts_at     Stored start, Y-m-d H:i:s, or empty. Malformed values are kept as they are.
	 * @param string   $ends_at       Stored end, Y-m-d H:i:s, or empty. Malformed values are kept as they are.
	 * @param int|null $order         Valid 0-9999 order, or null for an invalid legacy value.
	 * @throws \InvalidArgumentException When the ID, status or an attachment ID is impossible.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $status,
		public readonly string $section_key,
		public readonly string $title_hi,
		public readonly string $title_en,
		public readonly string $description,
		public readonly string $cta_label,
		public readonly string $cta_url,
		public readonly int $image_desktop,
		public readonly int $image_mobile,
		public readonly bool $active,
		public readonly string $starts_at,
		public readonly string $ends_at,
		public readonly ?int $order
	) {
		if ( $id < 1 || '' === $status ) {
			throw new \InvalidArgumentException( 'A page section needs a positive ID and a status.' );
		}

		if ( $image_desktop < 0 || $image_mobile < 0 ) {
			throw new \InvalidArgumentException( 'Attachment IDs cannot be negative.' );
		}
	}

	/**
	 * A valid order from a stored value, or null for an invalid legacy value.
	 *
	 * @param mixed $raw Stored value.
	 * @return int|null
	 */
	public static function order_from( mixed $raw ): ?int {
		if ( is_string( $raw ) && 1 === preg_match( '/^\d{1,4}$/', $raw ) ) {
			$raw = (int) $raw;
		}

		return is_int( $raw ) && $raw >= 0 && $raw <= self::ORDER_MAX ? $raw : null;
	}

	/**
	 * Sort key: valid orders first by order, invalid legacy orders after them, then post ID.
	 *
	 * @return array<int,int>
	 */
	public function sort_key(): array {
		return array( null === $this->order ? 1 : 0, $this->order ?? 0, $this->id );
	}

	/**
	 * Whether this is a published, switched-on hero. The schedule is not looked at.
	 *
	 * @return bool
	 */
	public function is_hero_candidate(): bool {
		return self::HERO === $this->section_key && 'publish' === $this->status && $this->active;
	}

	/**
	 * Whether both stored dates are well formed and the window is not empty or reversed.
	 *
	 * @param \DateTimeZone $zone Zone the stored dates are read in.
	 * @return bool
	 */
	public function has_valid_window( \DateTimeZone $zone ): bool {
		return null !== $this->window( $zone );
	}

	/**
	 * Eligible now: published, switched on, a valid key and now inside [start, end).
	 *
	 * An empty date is open-ended. A malformed date, or an end that is not after
	 * the start, makes the section ineligible; it is never read as empty.
	 *
	 * @param \DateTimeImmutable $now Current time, in the timezone the dates are read in.
	 * @return bool
	 */
	public function is_eligible( \DateTimeImmutable $now ): bool {
		if ( 'publish' !== $this->status || ! $this->active || ! in_array( $this->section_key, self::KEYS, true ) ) {
			return false;
		}

		$window = $this->window( $now->getTimezone() );

		if ( null === $window ) {
			return false;
		}

		list( $start, $end ) = $window;

		if ( null !== $start && $now < $start ) {
			return false;
		}

		return null === $end || $now < $end;
	}

	/**
	 * The nearest start or end that is still in the future, as a Unix timestamp.
	 *
	 * @param \DateTimeImmutable $now Current time.
	 * @return int|null Null when there is none or the window is invalid.
	 */
	public function next_boundary( \DateTimeImmutable $now ): ?int {
		$window = $this->window( $now->getTimezone() );
		$best   = null;

		if ( null === $window ) {
			return null;
		}

		foreach ( $window as $bound ) {
			if ( null !== $bound && $bound > $now ) {
				$stamp = $bound->getTimestamp();
				$best  = null === $best ? $stamp : min( $best, $stamp );
			}
		}

		return $best;
	}

	/**
	 * Plain data for the cache. IDs and stored values only, no URLs.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'id'            => $this->id,
			'status'        => $this->status,
			'section_key'   => $this->section_key,
			'title_hi'      => $this->title_hi,
			'title_en'      => $this->title_en,
			'description'   => $this->description,
			'cta_label'     => $this->cta_label,
			'cta_url'       => $this->cta_url,
			'image_desktop' => $this->image_desktop,
			'image_mobile'  => $this->image_mobile,
			'active'        => $this->active,
			'starts_at'     => $this->starts_at,
			'ends_at'       => $this->ends_at,
			'order'         => $this->order,
		);
	}

	/**
	 * Rebuilds a section from to_array() data.
	 *
	 * @param array<mixed> $data Data.
	 * @return self
	 * @throws \InvalidArgumentException When the data is not a section.
	 */
	public static function from_array( array $data ): self {
		$id     = $data['id'] ?? null;
		$status = $data['status'] ?? null;
		$key    = $data['section_key'] ?? null;
		$texts  = array( $data['title_hi'] ?? null, $data['title_en'] ?? null, $data['description'] ?? null, $data['cta_label'] ?? null, $data['cta_url'] ?? null, $data['starts_at'] ?? null, $data['ends_at'] ?? null );
		$desk   = $data['image_desktop'] ?? null;
		$mob    = $data['image_mobile'] ?? null;
		$active = $data['active'] ?? null;
		$order  = $data['order'] ?? null;

		foreach ( $texts as $text ) {
			if ( ! is_string( $text ) ) {
				throw new \InvalidArgumentException( 'Malformed page section data.' );
			}
		}

		if ( ! is_int( $id ) || ! is_string( $status ) || ! is_string( $key ) || ! is_int( $desk ) || ! is_int( $mob ) || ! is_bool( $active ) || ( null !== $order && ! is_int( $order ) ) ) {
			throw new \InvalidArgumentException( 'Malformed page section data.' );
		}

		return new self( $id, $status, $key, (string) $texts[0], (string) $texts[1], (string) $texts[2], (string) $texts[3], (string) $texts[4], $desk, $mob, $active, (string) $texts[5], (string) $texts[6], $order );
	}

	/**
	 * The parsed window, or null when it is invalid.
	 *
	 * @param \DateTimeZone $zone Zone the stored dates are read in.
	 * @return array{0:\DateTimeImmutable|null,1:\DateTimeImmutable|null}|null
	 */
	private function window( \DateTimeZone $zone ): ?array {
		$start = $this->parse( $this->starts_at, $zone );
		$end   = $this->parse( $this->ends_at, $zone );

		if ( false === $start || false === $end ) {
			return null;
		}

		if ( null !== $start && null !== $end && $end <= $start ) {
			return null;
		}

		return array( $start, $end );
	}

	/**
	 * One stored date: null when empty, false when malformed.
	 *
	 * @param string        $value Stored value.
	 * @param \DateTimeZone $zone  Zone.
	 * @return \DateTimeImmutable|null|false
	 */
	private function parse( string $value, \DateTimeZone $zone ): \DateTimeImmutable|null|false {
		if ( '' === $value ) {
			return null;
		}

		$date = \DateTimeImmutable::createFromFormat( self::FORMAT, $value, $zone );

		return false !== $date && $date->format( self::FORMAT ) === $value ? $date : false;
	}
}
