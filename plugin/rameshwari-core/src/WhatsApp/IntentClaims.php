<?php
/**
 * Single-use claims for enquiry intents.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\WhatsApp;

use Rameshwari\Core\Support\Lock;

/**
 * Claims an intent token id once, and tidies expired claims when there is no object cache.
 *
 * The claim is Support\Lock's atomic add-if-absent, named lead_intent_<id>, held for
 * the token lifetime plus 300 seconds. A token is always claimed before it expires, so
 * its claim always outlives it and an expired claim can never be needed again.
 *
 * With a persistent object cache the backend expires claims itself. Without one, Lock
 * keeps each claim as a non-autoloaded options row that is only reclaimed when the same
 * name is acquired again, which never happens for a random id. So every claim also
 * removes a bounded number of the oldest expired claim rows. The exact claim-name pattern
 * is applied in the query itself, before the row limit, so unrelated or malformed options
 * that merely share the prefix can never use up the scan. Only rows whose expiry has
 * passed are deleted, so an active claim is never removed. No cron job, table or option
 * group is added.
 */
final class IntentClaims {

	/**
	 * Seconds a claim is held: the token lifetime plus a margin.
	 */
	public const TTL = IntentIssuer::TTL + 300;

	/**
	 * Most expired claim rows removed per claim.
	 */
	public const SWEEP_LIMIT = 20;

	/**
	 * Most candidate claim rows read per sweep, so a sweep never scans without bound.
	 */
	public const SCAN_LIMIT = 100;

	/**
	 * SQL pattern a claim row name must match exactly, enforced before the row limit.
	 */
	public const ROW_PATTERN = '^rj_lock_lead_intent_[a-f0-9]{16}$';

	/**
	 * Option-name prefix Lock gives a lead_intent_ claim in its options fallback.
	 */
	public const ROW_PREFIX = 'rj_lock_lead_intent_';

	/**
	 * Returns the current Unix time.
	 *
	 * @var callable(): int
	 */
	private $clock;

	/**
	 * Whether a persistent object cache holds the claims.
	 *
	 * @var bool
	 */
	private bool $persistent;

	/**
	 * The lock that makes a claim atomic.
	 *
	 * @var Lock
	 */
	private Lock $lock;

	/**
	 * Removes expired claim rows.
	 *
	 * @var callable(int): void
	 */
	private $sweeper;

	/**
	 * Builds the claim store.
	 *
	 * @param callable|null $clock      Clock.
	 * @param Lock|null     $lock       Lock; defaults to one in the detected cache mode.
	 * @param bool|null     $persistent Force the cache mode; null detects it.
	 * @param callable|null $sweeper    Clean-up step; defaults to the options-row sweep.
	 */
	public function __construct( ?callable $clock = null, ?Lock $lock = null, ?bool $persistent = null, ?callable $sweeper = null ) {
		$this->clock      = $clock ?? static fn(): int => time();
		$this->persistent = $persistent ?? (bool) wp_using_ext_object_cache();
		$this->lock       = $lock ?? new Lock( $this->persistent, $this->clock );
		$this->sweeper    = $sweeper ?? array( $this, 'sweep' );
	}

	/**
	 * Claims a token id. A second claim of the same id, an invalid id or a storage failure returns false.
	 *
	 * A failing clean-up never changes the result.
	 *
	 * @param string $id Token id: 16 lowercase hex characters.
	 * @return bool
	 */
	public function claim( string $id ): bool {
		if ( 1 !== preg_match( '/^[a-f0-9]{16}$/D', $id ) ) {
			return false;
		}

		try {
			$claimed = null !== $this->lock->acquire( 'lead_intent_' . $id, self::TTL );
		} catch ( \Throwable $failure ) {
			return false;
		}

		if ( ! $this->persistent ) {
			try {
				( $this->sweeper )( (int) ( $this->clock )() );
			} catch ( \Throwable $failure ) {
				return $claimed;
			}
		}

		return $claimed;
	}

	/**
	 * Deletes up to SWEEP_LIMIT of the oldest claim rows that have expired.
	 *
	 * One bounded, prepared read returns the oldest SCAN_LIMIT rows whose name matches
	 * ROW_PATTERN exactly (case-sensitive: the name is converted to utf8mb4 and compared under the utf8mb4_bin collation, because MySQL 8.0.22+ rejects binary-string REGEXP arguments), oldest first. Rows with an unreadable record count
	 * as expired, the same rule Lock applies when it reclaims. A failed read deletes nothing.
	 *
	 * @param int $now Current Unix time.
	 * @return void
	 */
	public function sweep( int $now ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Bounded, prepared read of this class's own claim rows; the table name is core's.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE CONVERT( option_name USING utf8mb4 ) COLLATE utf8mb4_bin REGEXP %s ORDER BY option_id ASC LIMIT %d", self::ROW_PATTERN, self::SCAN_LIMIT ), ARRAY_A );
		$done = 0;

		foreach ( (array) $rows as $row ) {
			if ( $done >= self::SWEEP_LIMIT ) {
				break;
			}

			if ( ! is_array( $row ) || ! isset( $row['option_name'], $row['option_value'] ) || ! is_string( $row['option_name'] ) || ! is_string( $row['option_value'] ) ) {
				continue;
			}

			$name = $row['option_name'];

			if ( 1 !== preg_match( '/^rj_lock_lead_intent_[a-f0-9]{16}$/D', $name ) ) {
				continue;
			}

			$value = maybe_unserialize( $row['option_value'] );

			if ( ! is_array( $value ) || ! isset( $value['expires'] ) || ! is_int( $value['expires'] ) || $value['expires'] <= $now ) {
				delete_option( $name );

				++$done;
			}
		}
	}
}
