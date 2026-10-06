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
 */
final class Showroom {

	public const DAYS = array( 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' );

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
}
