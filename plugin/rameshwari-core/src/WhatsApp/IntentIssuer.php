<?php
/**
 * Single-use enquiry intents.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\WhatsApp;

/**
 * Issues and consumes the server-side proof that an enquiry form was really shown.
 *
 * A token carries its own issue time, expiry and random id, signed with the site
 * salt over the action, enquiry type and resolved object, so the client cannot
 * change any of them or back-date the issue time. Consuming a token takes an
 * atomic named lock keyed by the token id (the first caller wins; the lock outlives
 * the token). Nothing is stored at issue time, and no table, option group or
 * route is added. A failed or refused consumption means no lead is recorded.
 */
final class IntentIssuer {

	/**
	 * Seconds a token stays valid.
	 */
	public const TTL = 900;

	private const ACTION = 'rj_whatsapp_lead';

	/**
	 * Returns the current Unix time.
	 *
	 * @var callable(): int
	 */
	private $clock;

	/**
	 * Claims a token id once; false when it was already claimed or cannot be.
	 *
	 * @var callable(string): bool
	 */
	private $consumer;

	/**
	 * Builds the issuer.
	 *
	 * @param callable|null $clock    Clock.
	 * @param callable|null $consumer Claims a token id once. Defaults to IntentClaims, an atomic Lock with expired-claim clean-up.
	 */
	public function __construct( ?callable $clock = null, ?callable $consumer = null ) {
		$this->clock    = $clock ?? static fn(): int => time();
		$claims         = new IntentClaims( $this->clock );
		$this->consumer = $consumer ?? static fn( string $id ): bool => $claims->claim( $id );
	}

	/**
	 * Issues a token bound to an enquiry type and resolved object.
	 *
	 * @param string $type      Enquiry type.
	 * @param int    $object_id Resolved object ID (0 when none).
	 * @return array{token: string, issued: int, expires: int}
	 */
	public function issue( string $type, int $object_id ): array {
		$issued  = ( $this->clock )();
		$expires = $issued + self::TTL;
		$id      = bin2hex( random_bytes( 8 ) );

		return array(
			'token'   => $issued . '.' . $expires . '.' . $id . '.' . $this->sign( $type, $object_id, $issued, $expires, $id ),
			'issued'  => $issued,
			'expires' => $expires,
		);
	}

	/**
	 * Verifies signature, binding and lifetime without consuming the token.
	 *
	 * @param string $token     Submitted token.
	 * @param string $type      Enquiry type.
	 * @param int    $object_id Resolved object ID.
	 * @return array{id: string, issued: int}|null Null when the token is not valid for this request.
	 */
	public function check( string $token, string $type, int $object_id ): ?array {
		$parts = explode( '.', $token );

		if ( 4 !== count( $parts ) || ! ctype_digit( $parts[0] ) || ! ctype_digit( $parts[1] ) || 1 !== preg_match( '/^[a-f0-9]{16}$/', $parts[2] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/', $parts[3] ) ) {
			return null;
		}

		$issued  = (int) $parts[0];
		$expires = (int) $parts[1];
		$now     = ( $this->clock )();

		if ( ! hash_equals( $this->sign( $type, $object_id, $issued, $expires, $parts[2] ), $parts[3] ) || self::TTL !== $expires - $issued || $now > $expires || $issued > $now + 60 ) {
			return null;
		}

		return array(
			'id'     => $parts[2],
			'issued' => $issued,
		);
	}

	/**
	 * Claims a token id. The second claim of the same id, or any storage failure, returns false.
	 *
	 * @param string $id Token id from check().
	 * @return bool
	 */
	public function consume( string $id ): bool {
		try {
			return true === ( $this->consumer )( $id );
		} catch ( \Throwable $failure ) {
			return false;
		}
	}

	/**
	 * Signature over everything the token binds.
	 *
	 * @param string $type      Enquiry type.
	 * @param int    $object_id Object ID.
	 * @param int    $issued    Issue time.
	 * @param int    $expires   Expiry time.
	 * @param string $id        Token id.
	 * @return string
	 */
	private function sign( string $type, int $object_id, int $issued, int $expires, string $id ): string {
		return hash_hmac( 'sha256', implode( '|', array( self::ACTION, $type, $object_id, $issued, $expires, $id ) ), wp_salt( 'nonce' ) );
	}
}
