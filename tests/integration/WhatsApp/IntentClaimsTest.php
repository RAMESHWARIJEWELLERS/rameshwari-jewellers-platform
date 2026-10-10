<?php
/**
 * Intent claim storage tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Integration\WhatsApp;

use Rameshwari\Core\Support\Lock;
use Rameshwari\Core\WhatsApp\IntentClaims;
use Rameshwari\Core\WhatsApp\IntentIssuer;

/**
 * The real default consumer against the real Lock options fallback, with no object cache.
 */
final class IntentClaimsTest extends \WP_UnitTestCase {

	/**
	 * Object-cache mode before the test, restored afterwards.
	 *
	 * @var bool
	 */
	private bool $was_persistent = false;

	/**
	 * Turns the persistent object cache off, as on a site without one.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->was_persistent = (bool) wp_using_ext_object_cache();

		wp_using_ext_object_cache( false );
	}

	/**
	 * Restores the object-cache mode.
	 */
	public function tear_down(): void {
		wp_using_ext_object_cache( $this->was_persistent );

		parent::tear_down();
	}

	/**
	 * The shared clock.
	 *
	 * @var int
	 */
	private int $now = 1000;

	/**
	 * Claims built on the options fallback and the shared clock.
	 *
	 * @param callable|null $sweeper Clean-up step override.
	 * @return IntentClaims
	 */
	private function claims( ?callable $sweeper = null ): IntentClaims {
		return new IntentClaims( fn(): int => $this->now, new Lock( false, fn(): int => $this->now ), false, $sweeper );
	}

	/**
	 * A valid token id.
	 *
	 * @param int $n Sequence number.
	 * @return string
	 */
	private function id( int $n ): string {
		return sprintf( '%016x', $n );
	}

	/**
	 * Number of claim rows in the options table.
	 *
	 * @return int
	 */
	private function rows(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test counts its own claim rows.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( IntentClaims::ROW_PREFIX ) . '%' ) );
	}

	/**
	 * Adds an already expired claim row.
	 *
	 * @param int $n Sequence number.
	 * @return void
	 */
	private function stale( int $n ): void {
		add_option(
			IntentClaims::ROW_PREFIX . $this->id( $n ),
			array(
				'token'   => 'x',
				'expires' => 100,
			),
			'',
			false
		);
	}

	/**
	 * The first claim succeeds and leaves a non-autoloaded row; a replay is refused.
	 */
	public function test_first_claim_succeeds_and_replay_is_refused(): void {
		$claims = $this->claims();

		$this->assertTrue( $claims->claim( $this->id( 1 ) ) );
		$this->assertIsArray( get_option( IntentClaims::ROW_PREFIX . $this->id( 1 ) ) );
		$this->assertFalse( $claims->claim( $this->id( 1 ) ) );
		$this->assertFalse( $this->claims()->claim( $this->id( 1 ) ), 'A fresh instance sees the same claim.' );
		$this->assertSame( 1, $this->rows() );
	}

	/**
	 * Invalid ids are refused and store nothing.
	 */
	public function test_invalid_ids_store_nothing(): void {
		foreach ( array( '', 'abc', 'ZZZZZZZZZZZZZZZZ', str_repeat( 'a', 17 ), '../../etc' ) as $bad ) {
			$this->assertFalse( $this->claims()->claim( $bad ), $bad );
		}

		$this->assertSame( 0, $this->rows() );
	}

	/**
	 * Expired claims are removed; active claims, including the one just taken, stay.
	 */
	public function test_expired_claims_are_swept_and_active_ones_kept(): void {
		$this->now = 1000;

		$this->assertTrue( $this->claims()->claim( $this->id( 1 ) ) );

		$this->now = 2000;

		$this->assertTrue( $this->claims()->claim( $this->id( 2 ) ) );
		$this->assertNotFalse( get_option( IntentClaims::ROW_PREFIX . $this->id( 1 ) ), 'Not yet expired at 2000.' );

		$this->now = 2300;

		$this->assertTrue( $this->claims()->claim( $this->id( 3 ) ) );
		$this->assertFalse( get_option( IntentClaims::ROW_PREFIX . $this->id( 1 ) ), 'Expired at 2200.' );
		$this->assertNotFalse( get_option( IntentClaims::ROW_PREFIX . $this->id( 2 ) ) );
		$this->assertNotFalse( get_option( IntentClaims::ROW_PREFIX . $this->id( 3 ) ) );
		$this->assertFalse( $this->claims()->claim( $this->id( 2 ) ), 'An active claim still refuses a replay.' );
	}

	/**
	 * Repeated submissions over time do not grow the claim rows.
	 */
	public function test_rows_do_not_grow_over_repeated_submissions(): void {
		for ( $n = 1; $n <= 40; ++$n ) {
			$this->now += IntentClaims::TTL + 100;

			$this->assertTrue( $this->claims()->claim( $this->id( $n ) ) );
		}

		$this->assertSame( 1, $this->rows(), 'Only the newest, still-active claim remains.' );
	}

	/**
	 * One claim removes at most SWEEP_LIMIT expired rows, oldest first.
	 */
	public function test_sweep_is_bounded_per_claim(): void {
		for ( $n = 1; $n <= 50; ++$n ) {
			$this->stale( $n );
		}

		$this->assertTrue( $this->claims()->claim( $this->id( 900 ) ) );
		$this->assertSame( 50 - IntentClaims::SWEEP_LIMIT + 1, $this->rows() );
		$this->assertFalse( get_option( IntentClaims::ROW_PREFIX . $this->id( 1 ) ), 'The oldest expired row goes first.' );
		$this->assertNotFalse( get_option( IntentClaims::ROW_PREFIX . $this->id( 50 ) ) );
	}

	/**
	 * Only rows matching the claim pattern exactly are ever deleted.
	 */
	public function test_sweep_leaves_other_options_alone(): void {
		add_option(
			'rj_lock_other_job',
			array(
				'token'   => 'x',
				'expires' => 100,
			),
			'',
			false
		);
		add_option(
			'rj_lock_lead_intent_nothex',
			array(
				'token'   => 'x',
				'expires' => 100,
			),
			'',
			false
		);
		add_option( 'unrelated_option', 'keep', '', false );

		$this->assertTrue( $this->claims()->claim( $this->id( 1 ) ) );
		$this->assertNotFalse( get_option( 'rj_lock_other_job' ) );
		$this->assertNotFalse( get_option( 'rj_lock_lead_intent_nothex' ) );
		$this->assertSame( 'keep', get_option( 'unrelated_option' ) );
	}

	/**
	 * A claim row with an unreadable record counts as expired, as Lock treats it.
	 */
	public function test_unreadable_claim_rows_are_swept(): void {
		add_option( IntentClaims::ROW_PREFIX . $this->id( 7 ), 'corrupt', '', false );

		$this->assertTrue( $this->claims()->claim( $this->id( 8 ) ) );
		$this->assertFalse( get_option( IntentClaims::ROW_PREFIX . $this->id( 7 ) ) );
	}

	/**
	 * A failing clean-up neither allows a replay nor changes the first result.
	 */
	public function test_cleanup_failure_does_not_allow_a_replay(): void {
		$broken = static function (): void {
			throw new \RuntimeException( 'Clean-up storage is down.' );
		};

		$this->assertTrue( $this->claims( $broken )->claim( $this->id( 1 ) ) );
		$this->assertFalse( $this->claims( $broken )->claim( $this->id( 1 ) ) );
		$this->assertSame( 1, $this->rows() );
	}

	/**
	 * With a persistent cache, claims use the cache and the options table is untouched.
	 */
	public function test_persistent_mode_uses_the_cache_not_options(): void {
		$claims = new IntentClaims( fn(): int => $this->now, new Lock( true, fn(): int => $this->now ), true );

		$this->assertTrue( $claims->claim( $this->id( 1 ) ) );
		$this->assertFalse( $claims->claim( $this->id( 1 ) ) );
		$this->assertSame( 0, $this->rows() );
	}

	/**
	 * The issuer's own default consumer is the claim store: first consume wins, the replay loses.
	 */
	public function test_default_issuer_consumer_is_single_use(): void {
		$issuer = new IntentIssuer( fn(): int => $this->now );
		$token  = $issuer->issue( 'bridal', 0 )['token'];

		$this->now += 5;

		$ok = $issuer->check( $token, 'bridal', 0 );

		$this->assertNotNull( $ok );
		$this->assertTrue( $issuer->consume( $ok['id'] ) );
		$this->assertFalse( $issuer->consume( $ok['id'] ) );
		$this->assertFalse( ( new IntentIssuer( fn(): int => $this->now ) )->consume( $ok['id'] ), 'Another issuer instance sees the same claim.' );
	}

	/**
	 * Adds a claim row with a given expiry.
	 *
	 * @param int $n       Sequence number.
	 * @param int $expires Expiry timestamp.
	 * @return void
	 */
	private function row( int $n, int $expires ): void {
		add_option(
			IntentClaims::ROW_PREFIX . $this->id( $n ),
			array(
				'token'   => 'x',
				'expires' => $expires,
			),
			'',
			false
		);
	}

	/**
	 * Malformed rows that share the prefix cannot use up the scan, and they are never touched.
	 */
	public function test_malformed_prefix_rows_do_not_starve_the_sweep(): void {
		$malformed = array();

		for ( $n = 0; $n < 25; ++$n ) {
			$malformed[] = IntentClaims::ROW_PREFIX . ( 0 === $n % 5 ? 'zzzzzzzzzzzzzzzz' . $n : ( 1 === $n % 5 ? 'AAAAAAAAAAAAAAA' . dechex( $n ) : ( 2 === $n % 5 ? 'abc' . $n : ( 3 === $n % 5 ? sprintf( '%017x', $n ) : sprintf( '%015x', $n ) ) ) ) );
		}

		foreach ( $malformed as $name ) {
			add_option(
				$name,
				array(
					'token'   => 'x',
					'expires' => 100,
				),
				'',
				false
			);
		}

		for ( $n = 1; $n <= 30; ++$n ) {
			$this->stale( $n );
		}

		$this->row( 500, 9999999999 );

		$this->assertTrue( $this->claims()->claim( $this->id( 900 ) ) );

		foreach ( $malformed as $name ) {
			$this->assertNotFalse( get_option( $name ), $name );
		}

		$left = 0;

		for ( $n = 1; $n <= 30; ++$n ) {
			$left += false === get_option( IntentClaims::ROW_PREFIX . $this->id( $n ) ) ? 0 : 1;
		}

		$this->assertSame( 10, $left, 'Exactly 20 of the 30 valid expired rows are removed.' );
		$this->assertFalse( get_option( IntentClaims::ROW_PREFIX . $this->id( 1 ) ), 'Oldest eligible first.' );
		$this->assertFalse( get_option( IntentClaims::ROW_PREFIX . $this->id( 20 ) ) );
		$this->assertNotFalse( get_option( IntentClaims::ROW_PREFIX . $this->id( 21 ) ) );
		$this->assertNotFalse( get_option( IntentClaims::ROW_PREFIX . $this->id( 500 ) ), 'Active claim kept.' );
		$this->assertNotFalse( get_option( IntentClaims::ROW_PREFIX . $this->id( 900 ) ) );
		$this->assertSame( 25 + 10 + 1 + 1, $this->rows() );
	}

	/**
	 * Active rows among the oldest are never deleted, and expired rows behind them are still reached.
	 */
	public function test_active_rows_are_skipped_not_deleted(): void {
		$this->row( 1, 9999999999 );
		$this->row( 2, 9999999999 );

		for ( $n = 3; $n <= 12; ++$n ) {
			$this->stale( $n );
		}

		$this->assertTrue( $this->claims()->claim( $this->id( 900 ) ) );
		$this->assertNotFalse( get_option( IntentClaims::ROW_PREFIX . $this->id( 1 ) ) );
		$this->assertNotFalse( get_option( IntentClaims::ROW_PREFIX . $this->id( 2 ) ) );

		for ( $n = 3; $n <= 12; ++$n ) {
			$this->assertFalse( get_option( IntentClaims::ROW_PREFIX . $this->id( $n ) ), (string) $n );
		}
	}

	/**
	 * The default issuer, with no object cache and no injected consumer, claims through option rows and cleans them up.
	 */
	public function test_default_issuer_uses_option_rows_without_an_object_cache(): void {
		$this->assertFalse( (bool) wp_using_ext_object_cache() );

		$this->now = 1000;

		$first = new IntentIssuer( fn(): int => $this->now );
		$one   = $first->issue( 'bridal', 0 )['token'];

		$this->now += 5;

		$ok = $first->check( $one, 'bridal', 0 );

		$this->assertNotNull( $ok );

		$row = IntentClaims::ROW_PREFIX . $ok['id'];

		$this->assertTrue( $first->consume( $ok['id'] ) );
		$this->assertIsArray( get_option( $row ) );
		$this->assertArrayNotHasKey( $row, wp_load_alloptions( true ), 'The claim row is not autoloaded.' );
		$this->assertFalse( $first->consume( $ok['id'] ) );
		$this->assertFalse( ( new IntentIssuer( fn(): int => $this->now ) )->consume( $ok['id'] ), 'Replay is denied across issuer instances.' );

		$this->now += IntentClaims::TTL + 10;

		$later = new IntentIssuer( fn(): int => $this->now );
		$two   = $later->issue( 'bridal', 0 )['token'];

		$this->now += 5;

		$ok2 = $later->check( $two, 'bridal', 0 );

		$this->assertNotNull( $ok2 );
		$this->assertTrue( $later->consume( $ok2['id'] ) );
		$this->assertFalse( get_option( $row ), 'The expired claim is cleaned up.' );
		$this->assertIsArray( get_option( IntentClaims::ROW_PREFIX . $ok2['id'] ), 'The active claim is kept.' );

		$this->now += 10;

		$next  = new IntentIssuer( fn(): int => $this->now );
		$three = $next->issue( 'bridal', 0 )['token'];

		$this->now += 5;

		$ok3 = $next->check( $three, 'bridal', 0 );

		$this->assertNotNull( $ok3 );
		$this->assertTrue( $next->consume( $ok3['id'] ) );
		$this->assertIsArray( get_option( IntentClaims::ROW_PREFIX . $ok2['id'] ), 'The active claim survives a further submission.' );
		$this->assertFalse( $later->consume( $ok2['id'] ), 'Its replay is still denied.' );
	}

	/**
	 * The claim-row pattern is fully anchored: it accepts exactly 16 lowercase hex characters and nothing else.
	 */
	public function test_row_pattern_is_fully_anchored(): void {
		$pattern = '/' . IntentClaims::ROW_PATTERN . '/D';
		$prefix  = IntentClaims::ROW_PREFIX;

		$this->assertStringStartsWith( '^', IntentClaims::ROW_PATTERN );
		$this->assertStringEndsWith( '$', IntentClaims::ROW_PATTERN );
		$this->assertSame( 1, preg_match( $pattern, $prefix . '0123456789abcdef' ) );
		$this->assertSame( 1, preg_match( $pattern, $prefix . 'ffffffffffffffff' ) );

		$rejected = array(
			$prefix . '0123456789ABCDEF',
			$prefix . '0123456789abcdeF',
			$prefix . '0123456789abcde',
			$prefix . '0123456789abcdef0',
			$prefix . '0123456789abcdeg',
			$prefix . '0123456789abcdef' . "\n",
			$prefix . '',
			'x' . $prefix . '0123456789abcdef',
			'rj_lock_other_0123456789abcdef',
			$prefix . '0123456789abcdef-extra',
		);

		foreach ( $rejected as $name ) {
			$this->assertSame( 0, preg_match( $pattern, $name ), bin2hex( $name ) );
		}
	}

	/**
	 * The sweep query runs on the project's database without a database error.
	 */
	public function test_sweep_query_runs_without_a_database_error(): void {
		global $wpdb;

		$this->stale( 1 );

		$wpdb->last_error = '';

		$this->assertTrue( $this->claims()->claim( $this->id( 2 ) ) );
		$this->assertSame( '', (string) $wpdb->last_error );
		$this->assertFalse( get_option( IntentClaims::ROW_PREFIX . $this->id( 1 ) ) );
	}
}
