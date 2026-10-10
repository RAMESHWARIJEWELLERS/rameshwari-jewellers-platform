<?php
/**
 * Lead capture.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\WhatsApp;

use Rameshwari\Core\Support\Hash;
use Rameshwari\Core\Support\Logger;
use Rameshwari\Core\Support\RateLimiter;

/**
 * Screens an enquiry and records it as a lead. It is the only code that passes a
 * raw address to Hash; the address itself is never stored or logged.
 *
 * Screening decides only whether to record. A store failure is logged and
 * reported as not recorded; it never raises, so the redirect that follows is never blocked.
 */
final class LeadCapture {

	public const LIMIT  = 5;
	public const WINDOW = 600;
	public const DWELL  = 2;

	public const DAILY_LIMIT = 20;

	public const DAY = 86400;

	public const PHONE_LIMIT = 3;

	public const PHONE_WINDOW = 3600;

	/**
	 * Stores a lead and returns its ID.
	 *
	 * @var callable(array<string,mixed>): int
	 */
	private $store;

	/**
	 * Returns the current Unix time.
	 *
	 * @var callable(): int
	 */
	private $clock;

	/**
	 * Builds the service.
	 *
	 * @param callable|null    $store   Lead store. Defaults to LeadRepository.
	 * @param RateLimiter|null $limiter Rate limiter.
	 * @param Hash|null        $hash    Address hasher.
	 * @param Logger|null      $logger  Logger.
	 * @param callable|null    $clock   Clock.
	 */
	public function __construct( ?callable $store = null, private ?RateLimiter $limiter = null, private ?Hash $hash = null, private ?Logger $logger = null, ?callable $clock = null ) {
		$this->store   = $store ?? static fn( array $lead ): int => ( new LeadRepository() )->record( $lead );
		$this->limiter = $limiter ?? new RateLimiter();
		$this->hash    = $hash ?? new Hash();
		$this->logger  = $logger ?? new Logger();
		$this->clock   = $clock ?? static fn(): int => time();
	}

	/**
	 * Screens and records one enquiry.
	 *
	 * @param array<string,mixed> $in source, object_id, object_code, customer_id, phone, message, page_url, referrer, honeypot, started_at.
	 * @param string              $ip Client address.
	 * @return CaptureOutcome
	 */
	public function capture( array $in, string $ip ): CaptureOutcome {
		if ( '' !== trim( (string) ( $in['honeypot'] ?? '' ) ) ) {
			return new CaptureOutcome( CaptureOutcome::BOT );
		}

		$started = (int) ( $in['started_at'] ?? 0 );

		if ( $started < 1 || ( $this->clock )() - $started < self::DWELL ) {
			return new CaptureOutcome( CaptureOutcome::TOO_FAST );
		}

		$ip_hash = $this->hash->ip( $ip, 'leads' );

		if ( ! $this->limiter->hit( '' === $ip_hash ? 'unknown' : $ip_hash, self::LIMIT, self::WINDOW ) ) {
			return new CaptureOutcome( CaptureOutcome::RATE_LIMITED );
		}

		$phone = NumberNormalizer::normalize( (string) ( $in['phone'] ?? '' ) );

		if ( ! $this->limiter->hit( 'day_' . ( '' === $ip_hash ? 'unknown' : $ip_hash ), self::DAILY_LIMIT, self::DAY ) ) {
			return new CaptureOutcome( CaptureOutcome::RATE_LIMITED );
		}

		if ( '' !== $phone && ! $this->limiter->hit( 'phone_' . substr( wp_hash( $phone ), 0, 32 ), self::PHONE_LIMIT, self::PHONE_WINDOW ) ) {
			return new CaptureOutcome( CaptureOutcome::RATE_LIMITED );
		}

		$lead = array(
			'source'      => (string) ( $in['source'] ?? '' ),
			'object_id'   => (int) ( $in['object_id'] ?? 0 ),
			'object_code' => substr( (string) ( $in['object_code'] ?? '' ), 0, 64 ),
			'customer_id' => (int) ( $in['customer_id'] ?? 0 ),
			'phone'       => $phone,
			'message'     => mb_substr( (string) ( $in['message'] ?? '' ), 0, 500 ),
			'page_url'    => substr( (string) ( $in['page_url'] ?? '' ), 0, 500 ),
			'referrer'    => substr( (string) ( $in['referrer'] ?? '' ), 0, 500 ),
			'ip_hash'     => $ip_hash,
		);

		try {
			$id = (int) ( $this->store )( $lead );
		} catch ( \Throwable $failure ) {
			$id = 0;
		}

		if ( $id < 1 ) {
			$this->logger->warning( 'A WhatsApp enquiry lead could not be recorded.', array( 'source' => $lead['source'] ) );

			return new CaptureOutcome( CaptureOutcome::NOT_RECORDED );
		}

		return new CaptureOutcome( CaptureOutcome::RECORDED, $id );
	}
}
