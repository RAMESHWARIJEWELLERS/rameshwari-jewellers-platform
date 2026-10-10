<?php
/**
 * Public bridge integration tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Integration\WhatsApp;

use Rameshwari\Core\Plugin;
use Rameshwari\Core\WhatsApp\PublicBridge;
use Rameshwari\Core\WhatsApp\WhatsAppEngine;

/**
 * The bridge against the real resolver, real posts and a real engine with fixed settings.
 */
final class PublicBridgeIntegrationTest extends \WP_UnitTestCase {

	private const PREFIX = 'https://wa.me/919876543210?text=';

	/**
	 * Removes the URL filter.
	 */
	public function tear_down(): void {
		remove_all_filters( 'rj_whatsapp_generated_url' );

		parent::tear_down();
	}

	/**
	 * A bridge with the real resolver and a fixed-settings engine.
	 *
	 * @return PublicBridge
	 */
	private function bridge(): PublicBridge {
		$engine = new WhatsAppEngine(
			static fn(): array => array(
				'active_number'   => '9876543210',
				'fallback_number' => '9123456780',
				'templates'       => array(
					'product'    => 'P {code} {url}',
					'reel'       => 'R {title} {url}',
					'collection' => 'C {name} {url}',
					'bridal'     => 'B {url}',
					'showroom'   => 'S {branch} {url}',
				),
				'availability'    => array(
					'mode'          => 'always_available',
					'outside_hours' => 'use_fallback',
				),
			),
			static fn(): string => 'open',
			static fn(): int => 0,
			static fn(): array => array()
		);

		return new PublicBridge( static fn(): WhatsAppEngine => $engine );
	}

	/**
	 * The message text of a link.
	 *
	 * @param string $url Link.
	 * @return string
	 */
	private function message( string $url ): string {
		return rawurldecode( (string) substr( $url, strlen( self::PREFIX ) ) );
	}

	/**
	 * Reel, collection and showroom: published posts give a link with the server-side title and permalink.
	 */
	public function test_published_posts_resolve_per_type(): void {
		$cases = array(
			array( 'rj_reel', 'reel', 'R Bridal reel ' ),
			array( 'rj_collection', 'collection', 'C Bridal reel ' ),
			array( 'rj_showroom', 'showroom', 'S Bridal reel ' ),
		);

		foreach ( $cases as $case ) {
			$id  = self::factory()->post->create(
				array(
					'post_type'   => $case[0],
					'post_status' => 'publish',
					'post_title'  => 'Bridal reel',
				)
			);
			$url = $this->bridge()->url(
				array(
					'type'      => $case[1],
					'object_id' => $id,
				)
			);

			$this->assertStringStartsWith( self::PREFIX, $url, $case[1] );
			$this->assertStringContainsString( (string) get_permalink( $id ), $this->message( $url ), $case[1] );
			$this->assertStringContainsString( 'Bridal reel', $this->message( $url ), $case[1] );
		}
	}

	/**
	 * Bridal resolves to the home page with no object.
	 */
	public function test_bridal_uses_the_home_page(): void {
		$url = $this->bridge()->url( array( 'type' => 'bridal' ) );

		$this->assertStringStartsWith( self::PREFIX, $url );
		$this->assertSame( 'B ' . home_url( '/' ), $this->message( $url ) );
	}

	/**
	 * Draft, private, trashed, unknown and wrong-type objects give no link.
	 */
	public function test_non_public_and_wrong_type_objects_give_no_link(): void {
		foreach ( array( 'draft', 'private', 'trash' ) as $status ) {
			$id = self::factory()->post->create(
				array(
					'post_type'   => 'rj_reel',
					'post_status' => $status,
				)
			);

			$this->assertSame(
				'',
				$this->bridge()->url(
					array(
						'type'      => 'reel',
						'object_id' => $id,
					)
				),
				$status
			);
		}

		$collection = self::factory()->post->create(
			array(
				'post_type'   => 'rj_collection',
				'post_status' => 'publish',
			)
		);

		$this->assertSame(
			'',
			$this->bridge()->url(
				array(
					'type'      => 'reel',
					'object_id' => $collection,
				)
			),
			'a collection ID asked for as a reel'
		);
		$this->assertSame(
			'',
			$this->bridge()->url(
				array(
					'type'      => 'showroom',
					'object_id' => 99999999,
				)
			)
		);
		$this->assertSame(
			'',
			$this->bridge()->url(
				array(
					'type'      => 'product',
					'object_id' => 99999999,
				)
			)
		);
		$this->assertSame(
			'',
			$this->bridge()->url(
				array(
					'type'      => 'product',
					'object_id' => $collection,
				)
			),
			'a non-product ID asked for as a product'
		);
	}

	/**
	 * The generated-url filter still applies, and a malformed filtered value falls back to the engine link.
	 */
	public function test_url_filter_contract_is_preserved(): void {
		$id    = self::factory()->post->create(
			array(
				'post_type'   => 'rj_reel',
				'post_status' => 'publish',
			)
		);
		$input = array(
			'type'      => 'reel',
			'object_id' => $id,
		);
		$plain = $this->bridge()->url( $input );

		add_filter( 'rj_whatsapp_generated_url', static fn(): string => 'https://wa.me/911111111111?text=hello' );

		$this->assertSame( 'https://wa.me/911111111111?text=hello', $this->bridge()->url( $input ) );

		remove_all_filters( 'rj_whatsapp_generated_url' );
		add_filter( 'rj_whatsapp_generated_url', static fn(): string => 'https://evil.example/?x=1' );

		$this->assertSame( $plain, $this->bridge()->url( $input ) );
	}

	/**
	 * The global helper exists, is a thin call into the bridge, and refuses bad context.
	 */
	public function test_global_helper_is_available_and_safe(): void {
		$this->assertTrue( function_exists( 'rj_whatsapp_url' ) );
		$this->assertSame( '', rj_whatsapp_url( array() ) );
		$this->assertSame(
			'',
			rj_whatsapp_url(
				array(
					'type'      => 'reel',
					'object_id' => 99999999,
				)
			)
		);
		$engine = PublicBridge::registered_engine();

		$this->assertNotNull( $engine );
		$this->assertSame( $engine->link( 'bridal', array( 'url' => home_url( '/' ) ) )->url(), rj_whatsapp_url( array( 'type' => 'bridal' ) ) );
	}

	/**
	 * The bridge's default engine is the one registered in the plugin container.
	 */
	public function test_default_engine_is_the_registered_service(): void {
		$plugin = Plugin::instance();

		$this->assertNotNull( $plugin );

		$first  = $plugin->container()->get( 'whatsapp.engine' );
		$second = $plugin->container()->get( 'whatsapp.engine' );

		$this->assertInstanceOf( WhatsAppEngine::class, $first );
		$this->assertSame( $first, $second );
		$this->assertSame( $first, PublicBridge::registered_engine() );
	}
}
