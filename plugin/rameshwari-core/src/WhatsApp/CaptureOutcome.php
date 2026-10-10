<?php
/**
 * Result of a lead capture attempt.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\WhatsApp;

/**
 * Whether the lead was recorded, or why it was not. Either way the visitor goes on to WhatsApp.
 */
final class CaptureOutcome {

	public const RECORDED     = 'recorded';
	public const NOT_RECORDED = 'not_recorded';
	public const BOT          = 'bot';
	public const TOO_FAST     = 'too_fast';
	public const RATE_LIMITED = 'rate_limited';

	/**
	 * Builds the outcome.
	 *
	 * @param string $status  One of the constants.
	 * @param int    $lead_id Lead ID when recorded.
	 */
	public function __construct( private readonly string $status, private readonly int $lead_id = 0 ) {
	}

	/**
	 * The status.
	 *
	 * @return string
	 */
	public function status(): string {
		return $this->status;
	}

	/**
	 * The lead ID, 0 when none was recorded.
	 *
	 * @return int
	 */
	public function lead_id(): int {
		return $this->lead_id;
	}
}
