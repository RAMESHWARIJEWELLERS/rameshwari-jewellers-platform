<?php
/**
 * WhatsApp engine, rendering and routing tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Unit\WhatsApp;

use PHPUnit\Framework\TestCase;
use Rameshwari\Core\Domain\Showroom\Showroom;
use Rameshwari\Core\WhatsApp\Availability;
use Rameshwari\Core\WhatsApp\NumberNormalizer;
use Rameshwari\Core\WhatsApp\TemplateRenderer;
use Rameshwari\Core\WhatsApp\WhatsAppEngine;
/**
 * Pure checks: no WordPress needed.
 */
final class WhatsAppUnitTest extends TestCase {

	private const TEMPLATES = array(
		'product'    => 'नमस्ते, मुझे {name_hi} ({code}) के बारे में जानकारी चाहिए। {url}',
		'reel'       => 'reel {url}',
		'collection' => '{name} {url}',
		'bridal'     => 'bridal {url}',
		'showroom'   => '{branch} शोरूम',
	);

	/**
	 * Builds an engine with fixed settings and state.
	 *
	 * @param array<string,mixed> $override    Settings to change.
	 * @param string              $state       Showroom state.
	 * @param bool                $state_fails Whether the state read fails.
	 * @param array<int,mixed>    $phones      Stored contact phone records.
	 * @return WhatsAppEngine
	 */
	private function engine( array $override = array(), string $state = Showroom::STATE_OPEN, bool $state_fails = false, array $phones = array() ): WhatsAppEngine {
		$options = array_merge(
			array(
				'active_number'   => '9876543210',
				'fallback_number' => '9123456780',
				'templates'       => self::TEMPLATES,
				'availability'    => array(
					'mode'          => 'showroom_hours',
					'outside_hours' => 'use_fallback',
				),
			),
			$override
		);

		return new WhatsAppEngine(
			static fn(): array => $options,
			static function () use ( $state, $state_fails ): string {
				if ( $state_fails ) {
					throw new \RuntimeException( 'unreadable' );
				}

				return $state;
			},
			static fn(): int => 5,
			static fn(): array => $phones
		);
	}

	/**
	 * Numbers are brought to the 91 form or refused.
	 */
	public function test_number_normalising(): void {
		$this->assertSame( '919876543210', NumberNormalizer::normalize( '98765 43210' ) );
		$this->assertSame( '919876543210', NumberNormalizer::normalize( '+91-9876543210' ) );
		$this->assertSame( '919876543210', NumberNormalizer::normalize( '09876543210' ) );
		$this->assertSame( '', NumberNormalizer::normalize( '12345' ) );
		$this->assertSame( '', NumberNormalizer::normalize( '441234567890' ) );
		$this->assertSame( '', NumberNormalizer::normalize( '' ) );
	}

	/**
	 * Tokens fill, unknown tokens go, control characters are cleaned and Hindi survives.
	 */
	public function test_rendering_tokens(): void {
		$text = TemplateRenderer::render(
			'product',
			self::TEMPLATES['product'],
			array(
				'name_hi' => "कंगन\n",
				'code'    => 'RJ-1',
				'url'     => 'https://x.test/p/1',
			)
		);

		$this->assertSame( 'नमस्ते, मुझे कंगन (RJ-1) के बारे में जानकारी चाहिए। https://x.test/p/1', $text );
		$this->assertSame( 'a  b', TemplateRenderer::render( 'reel', 'a {code} b', array( 'code' => 'X' ) ) );
	}

	/**
	 * A long template stays within 900 characters and the URL is kept whole.
	 */
	public function test_long_message_is_cut_but_the_url_is_not(): void {
		$url  = 'https://example.test/product/very-long-slug-' . str_repeat( 'a', 40 );
		$text = TemplateRenderer::render( 'reel', str_repeat( 'शब्द ', 400 ) . '{url}', array( 'url' => $url ) );

		$this->assertLessThanOrEqual( 900, mb_strlen( $text ) );
		$this->assertStringEndsWith( $url, $text );
	}

	/**
	 * Every route of the availability matrix.
	 */
	public function test_availability_matrix(): void {
		$hours = array( 'mode' => 'showroom_hours' );

		$this->assertSame( 'primary', Availability::route( $hours, Showroom::STATE_OPEN ) );
		$this->assertSame( 'fallback', Availability::route( $hours + array( 'outside_hours' => 'use_fallback' ), Showroom::STATE_CLOSED ) );
		$this->assertSame( 'primary', Availability::route( $hours + array( 'outside_hours' => 'keep_primary' ), Showroom::STATE_CLOSED ) );
		$this->assertSame( 'call', Availability::route( $hours + array( 'outside_hours' => 'call_us' ), Showroom::STATE_CLOSED ) );
		$this->assertSame( 'primary', Availability::route( $hours, Showroom::STATE_UNAVAILABLE ) );
		$this->assertSame( 'primary', Availability::route( array( 'mode' => 'always_available' ), Showroom::STATE_CLOSED ) );
	}

	/**
	 * The URL carries the active number and the message, Devanagari intact.
	 */
	public function test_product_link(): void {
		$link = $this->engine()->link(
			'product',
			array(
				'name_hi' => 'कंगन',
				'code'    => 'RJ-1',
				'url'     => 'https://x.test/p/1',
			)
		);

		$this->assertTrue( $link->ok() );
		$this->assertSame( 'primary', $link->route() );
		$this->assertStringStartsWith( 'https://wa.me/919876543210?text=', $link->url() );
		$this->assertSame( $link->message(), rawurldecode( substr( $link->url(), strlen( 'https://wa.me/919876543210?text=' ) ) ) );
		$this->assertStringContainsString( 'कंगन', $link->message() );
	}

	/**
	 * A per-product message replaces the template for products only.
	 */
	public function test_override_applies_to_products_only(): void {
		$this->assertStringContainsString( 'special RJ-1', $this->engine()->link( 'product', array( 'code' => 'RJ-1' ), 'special {code}' )->message() );
		$this->assertStringNotContainsString( 'special', $this->engine()->link( 'reel', array( 'url' => 'u' ), 'special' )->message() );
	}

	/**
	 * Outside hours the fallback number or a call link is used.
	 */
	public function test_closed_routes(): void {
		$fallback = $this->engine( array(), Showroom::STATE_CLOSED )->link( 'bridal', array( 'url' => 'u' ) );
		$call     = $this->engine(
			array(
				'availability' => array(
					'mode'          => 'showroom_hours',
					'outside_hours' => 'call_us',
				),
			),
			Showroom::STATE_CLOSED,
			false,
			array(
				array(
					'label'    => 'bad',
					'number'   => '123',
					'whatsapp' => false,
				),
				array(
					'label'    => 'shop',
					'number'   => '+919876543210',
					'whatsapp' => false,
				),
			)
		)->link( 'bridal', array( 'url' => 'u' ) );

		$this->assertSame( '919123456780', $fallback->number() );
		$this->assertSame( 'tel:+919876543210', $call->url() );
	}

	/**
	 * Failures degrade: a failing state read still gives a link; no number or an unknown type gives none.
	 */
	public function test_failure_paths(): void {
		$this->assertSame( 'primary', $this->engine( array(), Showroom::STATE_OPEN, true )->link( 'bridal', array( 'url' => 'u' ) )->route() );
		$this->assertFalse(
			$this->engine(
				array(
					'active_number'   => '',
					'fallback_number' => '',
				)
			)->link( 'bridal' )->ok()
		);
		$this->assertFalse( $this->engine()->link( 'nonsense' )->ok() );
		$this->assertSame( '919123456780', $this->engine( array( 'active_number' => 'bad' ) )->link( 'bridal', array( 'url' => 'u' ) )->number() );
	}

	/**
	 * No other file builds a wa.me URL: the engine is the only builder.
	 */
	public function test_one_url_builder_only(): void {
		$root  = dirname( __DIR__, 3 ) . '/plugin/rameshwari-core/src';
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );
		$found = array();

		foreach ( $files as $file ) {
			if ( 'php' === $file->getExtension() && str_contains( (string) file_get_contents( $file->getPathname() ), 'wa.me/' ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source scan.
				$found[] = $file->getFilename();
			}
		}

		$this->assertSame( array( 'WhatsAppEngine.php' ), $found );
	}
}
