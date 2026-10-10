<?php
/**
 * Lead capture, repository and handler tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Integration\WhatsApp;

use Rameshwari\Tests\Integration\DatabaseTestCase;
use Rameshwari\Core\Support\Cache;
use Rameshwari\Core\Support\Hash;
use Rameshwari\Core\Support\Logger;
use Rameshwari\Core\Support\RateLimiter;
use Rameshwari\Core\WhatsApp\CaptureOutcome;
use Rameshwari\Core\WhatsApp\IntentIssuer;
use Rameshwari\Core\WhatsApp\LeadCapture;
use Rameshwari\Core\WhatsApp\LeadHandler;
use Rameshwari\Core\WhatsApp\LeadRepository;
use Rameshwari\Core\WhatsApp\WhatsAppEngine;
/**
 * Lead recording against the real rj_leads table, with failures injected through the store callable.
 */
final class LeadTest extends DatabaseTestCase {

	/**
	 * Creates the real tables, as the plugin's own migration does, since this test writes to rj_leads.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->assertTrue( $this->install(), 'The Stage 3 migration must create the rj_* tables.' );
	}

	private const NOW = 1800000000;

	/**
	 * Builds a capture service.
	 *
	 * @param callable|null $store Store override.
	 * @return LeadCapture
	 */
	private function capture( ?callable $store = null ): LeadCapture {
		$this->log = array();

		return new LeadCapture(
			$store,
			new RateLimiter( new Cache( 'lead_test_' . substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 16 ), false ), static fn(): int => self::NOW ),
			new Hash( 'test-salt' ),
			new Logger(
				function ( string $line ): void {
					$this->log[] = $line;
				}
			),
			static fn(): int => self::NOW
		);
	}

	/**
	 * A valid submission.
	 *
	 * @param array<string,mixed> $more Fields to change.
	 * @return array<string,mixed>
	 */
	private function input( array $more = array() ): array {
		return array_merge(
			array(
				'source'     => 'bridal',
				'object_id'  => 0,
				'message'    => 'नमस्ते',
				'page_url'   => 'https://x.test/a',
				'started_at' => self::NOW - 30,
			),
			$more
		);
	}

	/**
	 * Lead rows for a source.
	 *
	 * @param string $source Source.
	 * @return int
	 */
	private function rows( string $source ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Test count of the lead table.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'rj_leads WHERE source = %s', $source ) );
	}

	/**
	 * A good submission is recorded with the address hashed, never raw.
	 */
	public function test_recorded_without_the_raw_address(): void {
		$seen    = array();
		$outcome = $this->capture(
			static function ( array $lead ) use ( &$seen ): int {
				$seen = $lead;

				return 7;
			}
		)->capture( $this->input( array( 'phone' => '98765 43210' ) ), '203.0.113.9' );

		$this->assertSame( CaptureOutcome::RECORDED, $outcome->status() );
		$this->assertSame( 7, $outcome->lead_id() );
		$this->assertSame( '919876543210', $seen['phone'] );
		$this->assertSame( 64, strlen( $seen['ip_hash'] ) );
		$this->assertStringNotContainsString( '203.0.113.9', wp_json_encode( $seen ) );
	}

	/**
	 * Bot signals and rate limits record nothing and never call the store.
	 */
	public function test_screening(): void {
		$calls = 0;
		$store = static function () use ( &$calls ): int {
			++$calls;

			return 1;
		};

		$this->assertSame( CaptureOutcome::BOT, $this->capture( $store )->capture( $this->input( array( 'honeypot' => 'x' ) ), '203.0.113.9' )->status() );
		$this->assertSame( CaptureOutcome::TOO_FAST, $this->capture( $store )->capture( $this->input( array( 'started_at' => self::NOW - 1 ) ), '203.0.113.9' )->status() );
		$this->assertSame( CaptureOutcome::TOO_FAST, $this->capture( $store )->capture( $this->input( array( 'started_at' => 0 ) ), '203.0.113.9' )->status() );

		$service = $this->capture( $store );

		for ( $i = 0; $i < LeadCapture::LIMIT; $i++ ) {
			$service->capture( $this->input(), '203.0.113.9' );
		}

		$this->assertSame( CaptureOutcome::RATE_LIMITED, $service->capture( $this->input(), '203.0.113.9' )->status() );
		$this->assertSame( LeadCapture::LIMIT, $calls );
	}

	/**
	 * A failing or throwing store is logged and reported; it never raises.
	 */
	public function test_store_failure_is_isolated(): void {
		$zero = $this->capture( static fn(): int => 0 )->capture( $this->input(), '203.0.113.9' );

		$this->assertSame( CaptureOutcome::NOT_RECORDED, $zero->status() );
		$this->assertCount( 1, $this->log );

		$boom = $this->capture(
			static function (): int {
				throw new \RuntimeException( 'db down' );
			}
		)->capture( $this->input(), '203.0.113.9' );

		$this->assertSame( CaptureOutcome::NOT_RECORDED, $boom->status() );
		$this->assertSame( 0, $boom->lead_id() );
	}

	/**
	 * A repeat enquiry inside the window updates the lead instead of adding one.
	 */
	public function test_repository_dedupes_inside_the_window(): void {
		$repo = new LeadRepository();
		$lead = array(
			'source'      => 'lead_dedupe_test',
			'object_id'   => 11,
			'object_code' => '',
			'customer_id' => 0,
			'phone'       => '919876543210',
			'message'     => 'first',
			'page_url'    => 'https://x.test/1',
			'referrer'    => '',
			'ip_hash'     => '',
		);

		$one = $repo->record( $lead );
		$two = $repo->record( array_merge( $lead, array( 'message' => 'second' ) ) );

		$this->assertGreaterThan( 0, $one, 'The rj_leads table must exist and accept the insert.' );
		$this->assertSame( $one, $two );
		$this->assertSame( 1, $this->rows( 'lead_dedupe_test' ) );
	}

	/**
	 * The handler sends everyone to the enquiry link even when no lead is stored; only an unknown object goes home.
	 */
	public function test_handler_redirects_and_survives_a_failed_store(): void {
		$engine = new WhatsAppEngine(
			static fn(): array => array(
				'active_number' => '9876543210',
				'templates'     => array( 'bridal' => 'bridal {url}' ),
				'availability'  => array( 'mode' => 'always_available' ),
			)
		);

		$now = self::NOW - 30;

		$intents = new IntentIssuer(
			static function () use ( &$now ): int {
				return $now;
			},
			static fn(): bool => true
		);

		$token   = $intents->issue( 'bridal', 0 )['token'];
		$now     = self::NOW;
		$failing = new LeadHandler( $engine, $this->capture( static fn(): int => 0 ), null, $intents );
		$post    = array(
			'rj_type'   => 'bridal',
			'rj_nonce'  => wp_create_nonce( LeadHandler::ACTION ),
			'rj_intent' => $token,
		);
		$server  = array( 'REMOTE_ADDR' => '203.0.113.9' );

		$this->assertStringStartsWith( 'https://wa.me/919876543210', $failing->process( $post, $server ) );
		$this->assertStringStartsWith( 'https://wa.me/', $failing->process( array_merge( $post, array( 'rj_nonce' => 'bad' ) ), $server ), 'A bad nonce skips the lead but still redirects.' );
		$this->assertStringStartsWith( 'https://wa.me/919876543210', $failing->process( array_merge( $post, array( 'rj_website' => 'spam' ) ), $server ), 'A honeypot hit stores no lead but still returns the enquiry link.' );
		$this->assertSame(
			home_url( '/' ),
			$failing->process(
				array_merge(
					$post,
					array(
						'rj_type'   => 'product',
						'rj_object' => 999999,
					)
				),
				$server
			)
		);
		$this->assertSame( home_url( '/' ), $failing->process( array_merge( $post, array( 'rj_type' => 'nonsense' ) ), $server ) );
	}
}
