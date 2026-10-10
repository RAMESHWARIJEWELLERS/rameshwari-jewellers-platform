<?php
/**
 * Number routing by showroom hours.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\WhatsApp;

use Rameshwari\Core\Domain\Showroom\Showroom;

/**
 * Chooses which route a visitor gets. Showroom hours stay in the showroom record;
 * this only turns its open-now state and the saved choice into a route.
 */
final class Availability {

	public const PRIMARY  = 'primary';
	public const FALLBACK = 'fallback';
	public const CALL     = 'call';

	/**
	 * The route for a state.
	 *
	 * An unknown state counts as open, so an enquiry is never sent to a worse route by a read problem.
	 *
	 * @param array<string,mixed> $availability The rj_whatsapp availability object.
	 * @param string              $state        A Showroom STATE_ constant.
	 * @return string
	 */
	public static function route( array $availability, string $state ): string {
		if ( 'always_available' === ( $availability['mode'] ?? 'showroom_hours' ) || Showroom::STATE_CLOSED !== $state ) {
			return self::PRIMARY;
		}

		return match ( $availability['outside_hours'] ?? 'use_fallback' ) {
			'keep_primary' => self::PRIMARY,
			'call_us'      => self::CALL,
			default        => self::FALLBACK,
		};
	}
}
