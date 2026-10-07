<?php
/**
 * Showroom model.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Domain\Showroom;

/**
 * One showroom, read from its post and registered meta. Immutable.
 *
 * Opening hours live only in timings (_rj_timings). There is no second hours
 * field, and no hours text is derived or stored here.
 *
 * Whether the showroom is open is derived on demand from timings and a given
 * time (state_at). The three states are runtime answers and are never stored.
 */
final class Showroom {

	public const DAYS = array( 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' );

	public const STATE_OPEN        = 'OPEN';
	public const STATE_CLOSED      = 'CLOSED';
	public const STATE_UNAVAILABLE = 'UNAVAILABLE';

	/**
	 * Builds a showroom.
	 *
	 * @param int                               $id        Post ID.
	 * @param string                            $status    WordPress post status.
	 * @param string                            $address   Street address.
	 * @param string                            $city      City.
	 * @param string                            $state     State.
	 * @param string                            $pincode   Six digits, or empty.
	 * @param string                            $phone     Normalised phone, or empty.
	 * @param string                            $whatsapp  Normalised WhatsApp number, or empty.
	 * @param array<string,array<string,mixed>> $timings Opening hours by day.
	 * @param string                            $map_url   Map URL, or empty.
	 * @param float                             $lat       Latitude, -90 to 90.
	 * @param float                             $lng       Longitude, -180 to 180.
	 * @param array<int,int>                    $gallery   Gallery attachment IDs, in order.
	 * @throws \InvalidArgumentException When a field is outside its contract.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $status,
		public readonly string $address,
		public readonly string $city,
		public readonly string $state,
		public readonly string $pincode,
		public readonly string $phone,
		public readonly string $whatsapp,
		public readonly array $timings,
		public readonly string $map_url,
		public readonly float $lat,
		public readonly float $lng,
		public readonly array $gallery
	) {
		if ( $id < 1 || '' === $status ) {
			throw new \InvalidArgumentException( 'A showroom needs a positive ID and a status.' );
		}

		if ( '' !== $pincode && 1 !== preg_match( '/^\d{6}$/', $pincode ) ) {
			throw new \InvalidArgumentException( 'A pincode is six digits.' );
		}

		if ( $lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0 ) {
			throw new \InvalidArgumentException( 'Latitude or longitude is out of range.' );
		}

		foreach ( array_keys( $timings ) as $day ) {
			if ( ! in_array( $day, self::DAYS, true ) ) {
				throw new \InvalidArgumentException( 'Timings can only name mon to sun.' );
			}
		}

		foreach ( $gallery as $attachment ) {
			if ( ! is_int( $attachment ) || $attachment < 1 ) {
				throw new \InvalidArgumentException( 'Gallery entries must be positive IDs.' );
			}
		}
	}

	/**
	 * Whether a location is set.
	 *
	 * @return bool
	 */
	public function has_location(): bool {
		return 0.0 !== $this->lat || 0.0 !== $this->lng;
	}

	/**
	 * Opening hours of one day, or an empty array when none are stored.
	 *
	 * @param string $day mon to sun.
	 * @return array<string,mixed>
	 */
	public function timing_for( string $day ): array {
		return $this->timings[ $day ] ?? array();
	}

	/**
	 * Whether the showroom is open, closed or unknown at a moment.
	 *
	 * The moment is read in its own timezone, so the caller supplies it in the site
	 * timezone. A day's hours run open <= time < close; a close earlier than the
	 * open crosses midnight, and the part after midnight belongs to the day before.
	 * A missing or unusable day gives UNAVAILABLE, never an invented answer.
	 *
	 * @param \DateTimeImmutable $now Current time, in the timezone to judge it by.
	 * @return string One of the STATE_ constants.
	 */
	public function state_at( \DateTimeImmutable $now ): string {
		$index     = (int) $now->format( 'N' ) - 1;
		$minutes   = ( (int) $now->format( 'G' ) * 60 ) + (int) $now->format( 'i' );
		$today     = self::interval( $this->timings[ self::DAYS[ $index ] ] ?? null );
		$yesterday = self::interval( $this->timings[ self::DAYS[ ( $index + 6 ) % 7 ] ] ?? null );

		if ( null !== $today && 'open' === $today[0] && ( $today[1] < $today[2] ? ( $minutes >= $today[1] && $minutes < $today[2] ) : $minutes >= $today[1] ) ) {
			return self::STATE_OPEN;
		}

		if ( null !== $yesterday && 'open' === $yesterday[0] && $yesterday[1] > $yesterday[2] && $minutes < $yesterday[2] ) {
			return self::STATE_OPEN;
		}

		return null === $today ? self::STATE_UNAVAILABLE : self::STATE_CLOSED;
	}

	/**
	 * Whether the showroom is open at a moment.
	 *
	 * @param \DateTimeImmutable $now Current time.
	 * @return bool
	 */
	public function is_open_at( \DateTimeImmutable $now ): bool {
		return self::STATE_OPEN === $this->state_at( $now );
	}

	/**
	 * One stored day as a closed day or an open interval in minutes, or null when unusable.
	 *
	 * @param mixed $entry Stored day entry.
	 * @return array{0: string, 1: int, 2: int}|null
	 */
	private static function interval( mixed $entry ): ?array {
		if ( ! is_array( $entry ) ) {
			return null;
		}

		if ( array_key_exists( 'closed', $entry ) ) {
			return true === $entry['closed'] ? array( 'closed', 0, 0 ) : null;
		}

		$open  = self::minutes( $entry['open'] ?? null );
		$close = self::minutes( $entry['close'] ?? null );

		return null === $open || null === $close || $open === $close ? null : array( 'open', $open, $close );
	}

	/**
	 * Minutes since midnight for a HH:MM string, or null.
	 *
	 * @param mixed $value Stored time.
	 * @return int|null
	 */
	private static function minutes( mixed $value ): ?int {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^([01]\d|2[0-3]):([0-5]\d)$/', $value, $found ) ) {
			return null;
		}

		return ( (int) $found[1] * 60 ) + (int) $found[2];
	}
}
