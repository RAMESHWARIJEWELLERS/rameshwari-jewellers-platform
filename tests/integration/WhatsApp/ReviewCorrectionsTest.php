<?php
/**
 * Review correction tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Integration\WhatsApp;

use Rameshwari\Tests\Integration\DatabaseTestCase;
use Rameshwari\Core\Support\Cache;
use Rameshwari\Core\Support\Hash;
use Rameshwari\Core\Support\Logger;
use Rameshwari\Core\Support\RateLimiter;
use Rameshwari\Core\WhatsApp\ContextResolver;
use Rameshwari\Core\WhatsApp\IntentIssuer;
use Rameshwari\Core\WhatsApp\LeadCapture;
use Rameshwari\Core\WhatsApp\LeadHandler;
use Rameshwari\Core\WhatsApp\LeadRepository;
use Rameshwari\Core\WhatsApp\WhatsAppEngine;

/**
 * Real contexts, origin checks, the filter seam, limits and failure isolation.
 */
final class ReviewCorrectionsTest extends DatabaseTestCase {

	/**
	 * Creates the real tables, as the plugin's own migration does, since this test writes to rj_leads.
	 */
	public function set_up(): void {
		parent::set_up();
		( new Cache( 'rate_limit', (bool) wp_using_ext_object_cache() ) )->bump();

		$this->assertTrue( $this->install(), 'The Stage 3 migration must create the rj_* tables.' );
	}

	/**
	 * Leads stored by the phone-rules store, in order.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $leads = array();

	/**
	 * The clock shared by the issuer and the capture.
	 *
	 * @var int
	 */
	private int $now = 1000;

	/**
	 * Token ids already claimed, shared by every handler built in a test.
	 *
	 * @var array<string,bool>
	 */
	private array $claimed = array();

	/**
	 * Whether claiming should fail, to simulate failed token storage.
	 *
	 * @var bool
	 */
	private bool $claim_fails = false;

	/**
	 * An issuer on the shared clock with an in-memory claim list.
	 *
	 * @return IntentIssuer
	 */
	private function intents(): IntentIssuer {
		return new IntentIssuer(
			fn(): int => $this->now,
			function ( string $id ): bool {
				if ( $this->claim_fails ) {
					throw new \RuntimeException( 'Claim storage is down.' );
				}

				if ( isset( $this->claimed[ $id ] ) ) {
					return false;
				}

				$this->claimed[ $id ] = true;

				return true;
			}
		);
	}

	/**
	 * A token issued at a time for a type and object, with the clock restored to its previous value.
	 *
	 * @param string $type      Enquiry type.
	 * @param int    $object_id Object ID.
	 * @param int    $at        Issue time.
	 * @return string
	 */
	private function token( string $type, int $object_id, int $at = 200 ): string {
		$back      = $this->now;
		$this->now = $at;
		$token     = $this->intents()->issue( $type, $object_id )['token'];
		$this->now = $back;

		return $token;
	}

	/**
	 * A handler with a fixed engine number and real capture.
	 *
	 * @param callable|null $store Lead store.
	 * @return LeadHandler
	 */
	private function handler( ?callable $store = null ): LeadHandler {
		$engine  = new WhatsAppEngine(
			static fn(): array => array(
				'active_number' => '9811111111',
				'templates'     => array(
					'bridal'     => 'Hello {url}',
					'showroom'   => '{branch} {address}',
					'collection' => '{name}',
					'reel'       => '{title} {linked_codes}',
				),
				'availability'  => array( 'mode' => 'always_available' ),
			)
		);
		$capture = new LeadCapture( $store, new RateLimiter(), new Hash(), new Logger( static function (): void {} ), fn(): int => $this->now );

		return new LeadHandler( $engine, $capture, null, $this->intents() );
	}

	/**
	 * A verified same-site request.
	 *
	 * @param array<string,mixed> $more Extra POST fields.
	 * @return array<string,mixed>
	 */
	private function post( array $more = array() ): array {
		$fields = $more + array(
			'rj_type'   => 'bridal',
			'rj_object' => 0,
			'rj_nonce'  => wp_create_nonce( LeadHandler::ACTION ),
		);

		if ( ! array_key_exists( 'rj_intent', $fields ) && is_string( $fields['rj_type'] ) && ( is_int( $fields['rj_object'] ) || ( is_string( $fields['rj_object'] ) && ctype_digit( $fields['rj_object'] ) ) ) ) {
			$fields['rj_intent'] = $this->token( $fields['rj_type'], (int) $fields['rj_object'] );
		}

		return $fields;
	}

	/**
	 * Server data with an Origin or Referer.
	 *
	 * @param string $key Header key.
	 * @param string $url Header value.
	 * @return array<string,string>
	 */
	private function server( string $key = 'HTTP_ORIGIN', string $url = '' ): array {
		return array( 'REMOTE_ADDR' => '203.0.113.9' ) + ( '' === $url ? array() : array( $key => $url ) );
	}

	/**
	 * Showroom and collection contexts come from stored data.
	 */
	public function test_real_contexts_resolve_from_stored_data(): void {
		$showroom = self::factory()->post->create(
			array(
				'post_type'   => 'rj_showroom',
				'post_status' => 'publish',
				'post_title'  => 'Jhotwara',
			)
		);

		update_post_meta( $showroom, '_rj_address', 'Near School' );
		update_post_meta( $showroom, '_rj_city', 'Jaipur' );
		update_post_meta( $showroom, '_rj_pincode', '302012' );

		$context = ( new ContextResolver() )->resolve( 'showroom', $showroom );

		$this->assertSame( 'Jhotwara', $context['values']['branch'] );
		$this->assertStringContainsString( 'Near School', $context['values']['address'] );
		$this->assertStringContainsString( '302012', $context['values']['address'] );
		$this->assertSame( get_permalink( $showroom ), $context['page'] );

		$collection = self::factory()->post->create(
			array(
				'post_type'   => 'rj_collection',
				'post_status' => 'publish',
				'post_title'  => 'Festive',
			)
		);

		$this->assertSame( 'Festive', ( new ContextResolver() )->resolve( 'collection', $collection )['values']['name'] );
		$this->assertNull( ( new ContextResolver() )->resolve( 'collection', $showroom ) );
	}

	/**
	 * A bridal enquiry ignores any submitted object and uses the home page.
	 */
	public function test_bridal_ignores_a_client_object(): void {
		$context = ( new ContextResolver() )->resolve( 'bridal', 424242 );

		$this->assertSame( 0, $context['object'] );
		$this->assertSame( home_url( '/' ), $context['page'] );
	}

	/**
	 * A forged page URL is never stored; the server's object URL is.
	 */
	public function test_page_url_is_derived_not_trusted(): void {
		$seen  = array();
		$store = static function ( array $lead ) use ( &$seen ): int {
			$seen = $lead;

			return 7;
		};

		$this->handler( $store )->process( $this->post( array( 'rj_page' => 'https://evil.example/' ) ), $this->server( 'HTTP_ORIGIN', home_url() ) );

		$this->assertSame( home_url( '/' ), $seen['page_url'] );
	}

	/**
	 * A signed-in customer's submitted phone is ignored; an anonymous phone is validated.
	 */
	public function test_phone_rules(): void {
		$this->leads = array();

		$store = function ( array $lead ): int {
			$this->leads[] = $lead;

			return 7;
		};

		$this->handler( $store )->process( $this->post( array( 'rj_phone' => '98 76 54 32 10' ) ), $this->server( 'HTTP_ORIGIN', home_url() ) );
		$this->assertSame( '919876543210', $this->last_value( 'phone' ) );

		$this->handler( $store )->process( $this->post( array( 'rj_phone' => 'not a phone' ) ), $this->server( 'HTTP_ORIGIN', home_url() ) );
		$this->assertSame( '', $this->last_value( 'phone' ) );

		wp_set_current_user( self::factory()->user->create() );
		$this->handler( $store )->process( $this->post( array( 'rj_phone' => '9876543210' ) ), $this->server( 'HTTP_ORIGIN', home_url() ) );
		$last = end( $this->leads );
		$this->assertIsArray( $last );
		$this->assertSame( '', $last['phone'] );
		$this->assertGreaterThan( 0, (int) $last['customer_id'] );
	}

	/**
	 * One field of the most recently stored lead, as a string.
	 *
	 * @param string $field Field name.
	 * @return string
	 */
	private function last_value( string $field ): string {
		$last = end( $this->leads );

		if ( ! is_array( $last ) || ! isset( $last[ $field ] ) ) {
			return '';
		}

		$value = $last[ $field ];

		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Same-site, foreign and missing origins; the visitor always continues.
	 */
	public function test_origin_policy(): void {
		/**
		 * Tracks calls to the test lead store.
		 *
		 * @var \ArrayObject<int,int> $stored
		 */
		$stored = new \ArrayObject();
		$store  = static function () use ( $stored ): int {
			$stored[] = 1;

			return count( $stored );
		};

		$same    = $this->handler( $store )->process( $this->post(), $this->server( 'HTTP_ORIGIN', home_url() ) );
		$referer = $this->handler( $store )->process( $this->post(), $this->server( 'HTTP_REFERER', home_url( '/x' ) ) );
		$this->assertCount( 2, $stored );

		$foreign = $this->handler( $store )->process( $this->post(), $this->server( 'HTTP_ORIGIN', 'https://evil.example' ) );
		$missing = $this->handler( $store )->process( $this->post(), $this->server() );

		$this->assertCount( 2, $stored );

		foreach ( array( $same, $referer, $foreign, $missing ) as $url ) {
			$this->assertStringStartsWith( 'https://wa.me/919811111111', $url );
		}
	}

	/**
	 * A throwing lead store is isolated and the WhatsApp link is still returned.
	 */
	public function test_throwing_store_still_redirects(): void {
		$url = $this->handler(
			static function (): int {
				throw new \RuntimeException( 'database is down' );
			}
		)->process( $this->post(), $this->server( 'HTTP_ORIGIN', home_url() ) );

		$this->assertStringStartsWith( 'https://wa.me/919811111111', $url );
		$this->assertStringNotContainsString( 'database', $url );
	}

	/**
	 * Different objects never merge, and an old lead outside the window is a new lead.
	 */
	public function test_dedupe_by_object_and_window(): void {
		global $wpdb;

		$repository = new LeadRepository();
		$base       = array(
			'source'      => 'collection',
			'object_id'   => 11,
			'object_code' => '',
			'customer_id' => 0,
			'phone'       => '919876543210',
			'message'     => 'a',
			'page_url'    => '',
			'referrer'    => '',
			'ip_hash'     => 'h',
		);
		$first      = $repository->record( $base );

		$this->assertSame( $first, $repository->record( $base ) );
		$this->assertNotSame( $first, $repository->record( array( 'object_id' => 12 ) + $base ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Test ages one lead past the window.
		$wpdb->update( $wpdb->prefix . 'rj_leads', array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - LeadRepository::WINDOW - 60 ) ), array( 'id' => $first ) );

		$this->assertNotSame( $first, $repository->record( $base ) );
	}

	/**
	 * The generated URL passes the one interception filter; an unsafe result is ignored.
	 */
	public function test_filter_seam(): void {
		$engine = new WhatsAppEngine(
			static fn(): array => array(
				'active_number' => '9811111111',
				'templates'     => array( 'bridal' => 'Hi' ),
				'availability'  => array( 'mode' => 'always_available' ),
			)
		);
		$seen   = array();

		add_filter(
			'rj_whatsapp_generated_url',
			static function ( string $url, array $context ) use ( &$seen ): string {
				$seen = $context;

				return 'https://wa.me/919822222222?text=x';
			},
			10,
			2
		);

		$this->assertSame( 'https://wa.me/919822222222?text=x', $engine->link( 'bridal' )->url() );
		$this->assertSame( 'bridal', $seen['type'] );

		remove_all_filters( 'rj_whatsapp_generated_url' );
		add_filter( 'rj_whatsapp_generated_url', static fn(): string => 'javascript:alert(1)' );

		$this->assertStringStartsWith( 'https://wa.me/919811111111', $engine->link( 'bridal' )->url() );

		remove_all_filters( 'rj_whatsapp_generated_url' );
	}

	/**
	 * The daily limit stops a high-volume source after the short-window limit would reset.
	 */
	public function test_phone_limit(): void {
		$capture = new LeadCapture( static fn(): int => 5, new RateLimiter(), new Hash(), new Logger( static function (): void {} ), static fn(): int => 1000 );
		$input   = array(
			'source'     => 'bridal',
			'phone'      => '9876543210',
			'started_at' => 100,
		);
		$status  = array();

		for ( $i = 0; $i < LeadCapture::PHONE_LIMIT + 1; $i++ ) {
			$status[] = $capture->capture( $input, '203.0.113.' . $i )->status();
		}

		$this->assertSame( 'rate_limited', end( $status ) );
	}

	/**
	 * Two enquiries without a phone never merge just because the address hash matches.
	 */
	public function test_no_phone_never_dedupes_on_the_address_hash(): void {
		global $wpdb;

		$repository = new LeadRepository();
		$lead       = array(
			'source'      => 'dedupe_nophone',
			'object_id'   => 21,
			'object_code' => '',
			'customer_id' => 0,
			'phone'       => '',
			'message'     => 'a',
			'page_url'    => '',
			'referrer'    => '',
			'ip_hash'     => 'same-hash',
		);
		$one        = $repository->record( $lead );
		$two        = $repository->record( $lead );

		$this->assertGreaterThan( 0, $one );
		$this->assertNotSame( $one, $two );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test count on the fixed lead table.
		$rows = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}rj_leads WHERE source = %s", 'dedupe_nophone' ) );

		$this->assertSame( 2, $rows );
	}

	/**
	 * A bot hit stores no lead and still returns the safe enquiry link.
	 */
	public function test_bot_gets_the_link_and_no_lead(): void {
		$stored = 0;
		$url    = $this->handler(
			static function () use ( &$stored ): int {
				++$stored;

				return 1;
			}
		)->process( $this->post( array( 'rj_website' => 'spam' ) ), $this->server( 'HTTP_ORIGIN', home_url() ) );

		$this->assertSame( 0, $stored );
		$this->assertStringStartsWith( 'https://wa.me/919811111111', $url );
	}

	/**
	 * Scheme and port must match as well as the host.
	 */
	public function test_origin_tuple_scheme_and_port(): void {
		/**
		 * Tracks calls to the test lead store.
		 *
		 * @var \ArrayObject<int,int> $stored
		 */
		$stored = new \ArrayObject();
		$store  = static function () use ( $stored ): int {
			$stored[] = 1;

			return count( $stored );
		};
		$parts  = wp_parse_url( home_url() );
		$host   = (string) $parts['host'];
		$scheme = (string) $parts['scheme'];
		$other  = 'https' === $scheme ? 'http' : 'https';

		foreach ( array( $other . '://' . $host, $scheme . '://' . $host . ':8081', $scheme . '://' . $host . '.evil.example' ) as $origin ) {
			$url = $this->handler( $store )->process( $this->post(), $this->server( 'HTTP_ORIGIN', $origin ) );

			$this->assertStringStartsWith( 'https://wa.me/919811111111', $url, $origin );
		}

		$this->assertCount( 0, $stored );

		$this->handler( $store )->process( $this->post(), $this->server( 'HTTP_ORIGIN', home_url() ) );

		$this->assertCount( 1, $stored );
	}

	/**
	 * A filtered target must be a complete wa.me or tel: link, or the engine's own link is kept.
	 */
	public function test_filter_rejects_malformed_targets(): void {
		$engine = new WhatsAppEngine(
			static fn(): array => array(
				'active_number' => '9811111111',
				'templates'     => array( 'bridal' => 'Hi' ),
				'availability'  => array( 'mode' => 'always_available' ),
			)
		);
		$own    = $engine->link( 'bridal' )->url();

		foreach ( array( 'https://wa.me/919822222222?text=x&evil=1', 'https://wa.me/919822222222/extra', 'https://wa.me.evil.example/919822222222', 'http://wa.me/919822222222', 'https://wa.me/12', 'tel:+12', 'tel:+919822222222;ext=1', 'https://wa.me/919822222222?text=a b' ) as $bad ) {
			add_filter( 'rj_whatsapp_generated_url', static fn(): string => $bad );

			$this->assertSame( $own, $engine->link( 'bridal' )->url(), $bad );

			remove_all_filters( 'rj_whatsapp_generated_url' );
		}

		add_filter( 'rj_whatsapp_generated_url', static fn(): string => 'tel:+919822222222' );

		$this->assertSame( 'tel:+919822222222', $engine->link( 'bridal' )->url() );

		remove_all_filters( 'rj_whatsapp_generated_url' );
	}

	/**
	 * Returns an existing term of a taxonomy by slug, or creates it.
	 *
	 * @param string $name      Term name.
	 * @param string $slug      Term slug.
	 * @param string $taxonomy  Taxonomy.
	 * @param int    $parent_id Parent term ID.
	 * @return int
	 */
	private function term( string $name, string $slug, string $taxonomy, int $parent_id = 0 ): int {
		$found = get_term_by( 'slug', $slug, $taxonomy );

		if ( $found instanceof \WP_Term ) {
			return $found->term_id;
		}

		$made = wp_insert_term(
			$name,
			$taxonomy,
			array(
				'slug'   => $slug,
				'parent' => $parent_id,
			)
		);

		$this->assertIsArray( $made, $slug );

		return (int) $made['term_id'];
	}

	/**
	 * A complete published product, built through a draft so the Stage 6 gate can accept it.
	 *
	 * @param string $weight Stored weight, or empty.
	 * @return int
	 */
	private function product( string $weight = '' ): int {
		$root  = $this->term( 'Jewellery', 'jewellery', 'rj_category' );
		$child = $this->term( 'Gold Jewellery', 'gold-jewellery', 'rj_category', $root );
		$metal = $this->term( 'Gold', 'gold', 'rj_metal' );
		$pure  = $this->term( '22K', '22k', 'rj_purity' );
		$id    = self::factory()->post->create(
			array(
				'post_type'   => 'rj_product',
				'post_status' => 'draft',
				'post_title'  => 'Necklace',
			)
		);

		update_post_meta( $id, '_rj_code', 'RJ-' . $id );
		update_post_meta( $id, '_rj_name_en', 'Necklace' );
		update_post_meta( $id, '_rj_name_hi', 'हार' );
		update_post_meta( $id, '_rj_weight_unit', 'g' );

		if ( '' !== $weight ) {
			update_post_meta( $id, '_rj_weight', $weight );
		}

		wp_set_object_terms( $id, array( $child ), 'rj_category' );
		wp_set_object_terms( $id, array( $metal ), 'rj_metal' );
		wp_set_object_terms( $id, array( $pure ), 'rj_purity' );
		update_post_meta( $id, '_rj_primary_term', $child );
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'publish',
			)
		);

		$this->assertSame( 'publish', get_post_status( $id ), 'The Stage 6 publication gate must accept this complete product.' );

		return $id;
	}

	/**
	 * A real published product resolves its labels, code and names; weight is hidden by default.
	 */
	public function test_product_context_resolves_real_labels(): void {
		$id     = $this->product( '5.20' );
		$values = ( new ContextResolver() )->resolve( 'product', $id )['values'];

		$this->assertSame( 'RJ-' . $id, $values['code'] );
		$this->assertSame( 'Necklace', $values['name_en'] );
		$this->assertSame( 'हार', $values['name_hi'] );
		$this->assertSame( 'Gold', $values['metal'] );
		$this->assertSame( '22K', $values['purity'] );
		$this->assertSame( '', $values['weight'], 'Weight stays hidden while the public setting is off.' );
	}

	/**
	 * With the public setting on, the real product context carries the formatted weight.
	 */
	public function test_product_context_shows_weight_when_enabled(): void {
		add_filter( 'pre_option_rj_display', static fn(): array => array( 'show_weight_publicly' => true ) );

		$weights = array(
			'10'    => '10 g',
			'120'   => '120 g',
			'0.500' => '0.5 g',
			'5.20'  => '5.2 g',
		);

		foreach ( $weights as $stored => $expected ) {
			$id = $this->product( (string) $stored );

			$this->assertSame( $expected, ( new ContextResolver() )->resolve( 'product', $id )['values']['weight'], (string) $stored );
		}

		remove_all_filters( 'pre_option_rj_display' );

		$id = $this->product( '10' );

		$this->assertSame( '', ( new ContextResolver() )->resolve( 'product', $id )['values']['weight'] );
	}

	/**
	 * Outside the hours the Call Us route dials the stored contact phone, not the WhatsApp number.
	 */
	public function test_call_us_route_uses_the_contact_phone(): void {
		$engine = new WhatsAppEngine(
			static fn(): array => array(
				'active_number'   => '9811111111',
				'fallback_number' => '9822222222',
				'templates'       => array( 'bridal' => 'Hi' ),
				'availability'    => array(
					'mode'          => 'showroom_hours',
					'outside_hours' => 'call_us',
				),
			),
			static fn(): string => 'CLOSED',
			static fn(): int => 1,
			static fn(): array => array(
				array( 'number' => 'bad' ),
				array(
					'number'   => '+919833333333',
					'whatsapp' => false,
				),
			)
		);
		$link   = $engine->link( 'bridal' );

		$this->assertSame( 'tel:+919833333333', $link->url() );
		$this->assertStringNotContainsString( '9811111111', $link->url() );
		$this->assertStringNotContainsString( '9822222222', $link->url() );
	}

	/**
	 * Percent signs must be followed by two hex digits; valid encoding passes.
	 */
	public function test_filter_percent_escape_strictness(): void {
		$engine = new WhatsAppEngine(
			static fn(): array => array(
				'active_number' => '9811111111',
				'templates'     => array( 'bridal' => 'Hi' ),
				'availability'  => array( 'mode' => 'always_available' ),
			)
		);
		$own    = $engine->link( 'bridal' )->url();

		foreach ( array( '%', '%ZZ', '%2G', 'a%' ) as $bad ) {
			add_filter( 'rj_whatsapp_generated_url', static fn(): string => 'https://wa.me/919822222222?text=' . $bad );

			$this->assertSame( $own, $engine->link( 'bridal' )->url(), $bad );

			remove_all_filters( 'rj_whatsapp_generated_url' );
		}

		add_filter( 'rj_whatsapp_generated_url', static fn(): string => 'https://wa.me/919822222222?text=a%20b' );

		$this->assertSame( 'https://wa.me/919822222222?text=a%20b', $engine->link( 'bridal' )->url() );

		remove_all_filters( 'rj_whatsapp_generated_url' );
	}

	/**
	 * An Origin header must be a bare origin; user info, paths, queries and fragments are refused.
	 */
	public function test_malformed_origin_headers_skip_the_lead(): void {
		/**
		 * Tracks calls to the test lead store.
		 *
		 * @var \ArrayObject<int,int> $stored
		 */
		$stored = new \ArrayObject();
		$store  = static function () use ( $stored ): int {
			$stored[] = 1;

			return count( $stored );
		};
		$base   = untrailingslashit( home_url() );

		foreach ( array( $base . '/', $base . '/path', $base . '?x=1', $base . '#frag', str_replace( '://', '://user:pass@', $base ), $base . ' ', 'null' ) as $origin ) {
			$url = $this->handler( $store )->process( $this->post(), $this->server( 'HTTP_ORIGIN', $origin ) );

			$this->assertStringStartsWith( 'https://wa.me/919811111111', $url, $origin );
		}

		$this->assertCount( 0, $stored );

		$this->handler( $store )->process( $this->post(), $this->server( 'HTTP_REFERER', home_url( '/some/page?x=1' ) ) );

		$this->assertCount( 1, $stored, 'A same-origin Referer with a path still works.' );
	}

	/**
	 * Whole weights keep their zeros; only fractional trailing zeros go.
	 */
	public function test_weight_formatting_keeps_significant_zeros(): void {
		$method = new \ReflectionMethod( ContextResolver::class, 'weight' );
		$id     = self::factory()->post->create( array( 'post_type' => 'rj_product' ) );

		add_filter( 'pre_option_rj_display', static fn(): array => array( 'show_weight_publicly' => true ) );
		update_post_meta( $id, '_rj_weight_unit', 'g' );

		$cases = array(
			'10'    => '10 g',
			'100'   => '100 g',
			'120'   => '120 g',
			'0.500' => '0.5 g',
			'5.20'  => '5.2 g',
			'0'     => '',
			'-4'    => '',
		);

		foreach ( $cases as $stored => $expected ) {
			update_post_meta( $id, '_rj_weight', $stored );

			$this->assertSame( $expected, $method->invoke( new ContextResolver(), $id ), (string) $stored );
		}

		remove_all_filters( 'pre_option_rj_display' );
	}

	/**
	 * A counting lead store.
	 *
	 * @param int $stored Number of stored leads, by reference.
	 * @return callable
	 */
	private function counting( int &$stored ): callable {
		return static function () use ( &$stored ): int {
			++$stored;

			return $stored;
		};
	}

	/**
	 * Server data from a trusted origin.
	 *
	 * @param array<string,mixed> $more Extra or replacement entries.
	 * @return array<string,mixed>
	 */
	private function trusted_server( array $more = array() ): array {
		return $more + array(
			'REQUEST_METHOD' => 'POST',
			'REMOTE_ADDR'    => '203.0.113.9',
			'HTTP_ORIGIN'    => untrailingslashit( home_url() ),
		);
	}

	/**
	 * Only POST can reach lead capture; every other method goes home without storing anything.
	 */
	public function test_only_post_reaches_lead_capture(): void {
		$stored = 0;
		$home   = home_url( '/' );

		foreach ( array( 'GET', 'HEAD', 'PUT', 'DELETE', 'OPTIONS', 'post', '' ) as $method ) {
			$this->assertSame( $home, $this->handler( $this->counting( $stored ) )->request( $this->post(), $this->trusted_server( array( 'REQUEST_METHOD' => $method ) ) ), $method );
		}

		$no_method = $this->trusted_server();

		unset( $no_method['REQUEST_METHOD'] );

		$this->assertSame( $home, $this->handler( $this->counting( $stored ) )->request( $this->post(), $no_method ) );
		$this->assertSame( $home, $this->handler( $this->counting( $stored ) )->request( $this->post(), $this->trusted_server( array( 'REQUEST_METHOD' => array( 'POST' ) ) ) ) );
		$this->assertSame( 0, $stored, 'No non-POST request may store a lead.' );

		$url = $this->handler( $this->counting( $stored ) )->request( $this->post(), $this->trusted_server() );

		$this->assertStringStartsWith( 'https://wa.me/919811111111', $url );
		$this->assertSame( 1, $stored, 'A genuine POST still stores its lead.' );
	}

	/**
	 * Malformed type or object never reaches the resolver: the visitor goes home and nothing is stored.
	 */
	public function test_malformed_type_or_object_goes_home(): void {
		$stored = 0;
		$home   = home_url( '/' );
		$bad    = array(
			array( 'rj_type' => array( 'bridal' ) ),
			array( 'rj_type' => new \stdClass() ),
			array( 'rj_type' => 1.5 ),
			array( 'rj_object' => array( 1 ) ),
			array( 'rj_object' => new \stdClass() ),
			array( 'rj_object' => true ),
			array( 'rj_object' => '12abc' ),
			array( 'rj_object' => '-1' ),
			array( 'rj_object' => '1.5' ),
			array( 'rj_object' => '1234567890123' ),
		);

		foreach ( $bad as $n => $fields ) {
			$this->assertSame( $home, $this->handler( $this->counting( $stored ) )->request( $this->post( $fields ), $this->trusted_server() ), (string) $n );
		}

		$this->assertSame( 0, $stored );
	}

	/**
	 * A malformed nonce, phone, honeypot or intent field stores no lead, and the visitor still gets the link.
	 */
	public function test_malformed_security_fields_store_nothing(): void {
		$stored = 0;
		$bad    = array(
			array( 'rj_nonce' => array( 'x' ) ),
			array( 'rj_nonce' => new \stdClass() ),
			array( 'rj_phone' => array( '9811111111' ) ),
			array( 'rj_phone' => new \stdClass() ),
			array( 'rj_website' => array( '' ) ),
			array( 'rj_website' => new \stdClass() ),
			array( 'rj_intent' => array( 'x' ) ),
			array( 'rj_intent' => new \stdClass() ),
			array( 'rj_intent' => 'abc' ),
			array( 'rj_intent' => '1.2.3.4' ),
			array( 'rj_intent' => 12.5 ),
		);

		foreach ( $bad as $n => $fields ) {
			$url = $this->handler( $this->counting( $stored ) )->request( $this->post( $fields ), $this->trusted_server() );

			$this->assertStringStartsWith( 'https://wa.me/919811111111', $url, (string) $n );
		}

		$this->assertSame( 0, $stored );

		$this->handler( $this->counting( $stored ) )->request( $this->post(), $this->trusted_server() );

		$this->assertSame( 1, $stored, 'The same request with well-formed fields stores its lead.' );
	}

	/**
	 * A non-string Origin or Referer counts as absent: no warning, no lead, the link still comes back.
	 */
	public function test_non_string_origin_or_referer_is_ignored(): void {
		$stored = 0;

		foreach ( array( 'HTTP_ORIGIN', 'HTTP_REFERER' ) as $key ) {
			$server = $this->trusted_server( array( $key => array( 'x' ) ) );

			if ( 'HTTP_REFERER' === $key ) {
				unset( $server['HTTP_ORIGIN'] );
			}

			$url = $this->handler( $this->counting( $stored ) )->request( $this->post(), $server );

			$this->assertStringStartsWith( 'https://wa.me/919811111111', $url, $key );
		}

		$this->assertSame( 0, $stored );
	}

	/**
	 * A non-string client address counts as absent: no warning, and the lead keeps an empty address hash.
	 */
	public function test_non_string_client_address_is_treated_as_absent(): void {
		$seen = array();
		$url  = $this->handler(
			static function ( array $lead ) use ( &$seen ): int {
				$seen[] = $lead;

				return 1;
			}
		)->request( $this->post(), $this->trusted_server( array( 'REMOTE_ADDR' => array( '203.0.113.9' ) ) ) );

		$this->assertStringStartsWith( 'https://wa.me/919811111111', $url );
		$this->assertCount( 1, $seen );
		$this->assertSame( '', $seen[0]['ip_hash'] );
		$this->assertStringNotContainsString( '203.0.113.9', (string) wp_json_encode( $seen ) );
	}

	/**
	 * The form seam issues a complete, bound, single-use field set without composing any link.
	 */
	public function test_form_fields_issue_a_usable_intent(): void {
		$handler = $this->handler();
		$fields  = $handler->form_fields( 'bridal', 0 );

		$this->assertIsArray( $fields );
		$this->assertSame( array( 'rj_type', 'rj_object', 'rj_nonce', 'rj_intent' ), array_keys( $fields ) );
		$this->assertStringNotContainsString( 'wa.me', (string) wp_json_encode( $fields ) );
		$this->assertNull( $handler->form_fields( 'product', 99999999 ) );
		$this->assertNull( $handler->form_fields( 'nonsense', 0 ) );
	}

	/**
	 * A valid first submission stores exactly one lead and returns the engine link.
	 */
	public function test_valid_first_submission_records_one_lead(): void {
		$stored = 0;
		$url    = $this->handler( $this->counting( $stored ) )->request( $this->post(), $this->trusted_server() );

		$this->assertStringStartsWith( 'https://wa.me/919811111111', $url );
		$this->assertSame( 1, $stored );
	}

	/**
	 * Replaying the same valid submission records nothing more, shows no error and returns the same safe link.
	 */
	public function test_replay_records_no_second_lead(): void {
		$stored = 0;
		$post   = $this->post();
		$first  = $this->handler( $this->counting( $stored ) )->request( $post, $this->trusted_server() );
		$replay = $this->handler( $this->counting( $stored ) )->request( $post, $this->trusted_server() );
		$third  = $this->handler( $this->counting( $stored ) )->request( $post, $this->trusted_server() );

		$this->assertSame( 1, $stored, 'Only the first submission may store a lead.' );
		$this->assertSame( $first, $replay );
		$this->assertSame( $first, $third );
		$this->assertStringStartsWith( 'https://wa.me/919811111111', $replay );
	}

	/**
	 * A token past its lifetime records nothing but still returns the link.
	 */
	public function test_expired_token_records_nothing(): void {
		$stored = 0;
		$post   = $this->post( array( 'rj_intent' => $this->token( 'bridal', 0, 1000 - IntentIssuer::TTL - 5 ) ) );
		$url    = $this->handler( $this->counting( $stored ) )->request( $post, $this->trusted_server() );

		$this->assertStringStartsWith( 'https://wa.me/919811111111', $url );
		$this->assertSame( 0, $stored );
	}

	/**
	 * A token for another type or object, or with any part altered, is refused.
	 */
	public function test_tampered_or_mismatched_token_records_nothing(): void {
		$stored   = 0;
		$good     = $this->token( 'showroom', 1 );
		$parts    = explode( '.', $this->token( 'bridal', 0 ) );
		$older    = $parts;
		$older[0] = (string) ( (int) $parts[0] - 600 );
		$bad      = array(
			$good,
			$parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.' . str_repeat( '0', 64 ),
			implode( '.', $older ),
			$parts[0] . '.' . $parts[1] . '.' . strrev( $parts[2] ) . '.' . $parts[3],
			substr( implode( '.', $parts ), 0, -1 ),
		);

		foreach ( $bad as $n => $token ) {
			$url = $this->handler( $this->counting( $stored ) )->request( $this->post( array( 'rj_intent' => $token ) ), $this->trusted_server() );

			$this->assertStringStartsWith( 'https://wa.me/919811111111', $url, (string) $n );
		}

		$this->assertSame( 0, $stored );
	}

	/**
	 * A submission faster than the minimum dwell time records nothing; its token is spent, so it cannot be retried.
	 */
	public function test_too_fast_submission_records_nothing(): void {
		$stored = 0;
		$post   = $this->post( array( 'rj_intent' => $this->token( 'bridal', 0, 999 ) ) );
		$first  = $this->handler( $this->counting( $stored ) )->request( $post, $this->trusted_server() );

		$this->assertStringStartsWith( 'https://wa.me/919811111111', $first );
		$this->assertSame( 0, $stored );

		$this->now = 1500;

		$this->handler( $this->counting( $stored ) )->request( $post, $this->trusted_server() );

		$this->assertSame( 0, $stored, 'A too-fast attempt spends its token; waiting and resending the same token records nothing.' );
	}

	/**
	 * When the claim store fails, no lead is recorded, no error shows, and the link still returns.
	 */
	public function test_failed_token_consumption_records_nothing(): void {
		$stored            = 0;
		$this->claim_fails = true;
		$url               = $this->handler( $this->counting( $stored ) )->request( $this->post(), $this->trusted_server() );

		$this->assertStringStartsWith( 'https://wa.me/919811111111', $url );
		$this->assertSame( 0, $stored );

		$no_claim = new IntentIssuer( fn(): int => $this->now, static fn(): bool => false );
		$token    = $this->token( 'bridal', 0 );

		$this->assertNotNull( $no_claim->check( $token, 'bridal', 0 ) );
		$this->assertFalse( $no_claim->consume( 'aaaaaaaaaaaaaaaa' ) );
	}
}
