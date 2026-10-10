<?php
/**
 * Token sets and phone fallback tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Unit\WhatsApp;

use PHPUnit\Framework\TestCase;
use Rameshwari\Core\WhatsApp\TemplateRenderer;
use Rameshwari\Core\WhatsApp\WhatsAppEngine;

/**
 * The locked token sets and the Call Us fallback.
 */
final class TokenAndFallbackTest extends TestCase {

	/**
	 * Every message type allows exactly its locked tokens.
	 */
	public function test_token_sets_are_exact(): void {
		$this->assertSame( array( 'code', 'name_hi', 'name_en', 'metal', 'purity', 'weight', 'url' ), TemplateRenderer::tokens( 'product' ) );
		$this->assertSame( array( 'name', 'url' ), TemplateRenderer::tokens( 'collection' ) );
		$this->assertSame( array( 'title', 'url', 'linked_codes' ), TemplateRenderer::tokens( 'reel' ) );
		$this->assertSame( array( 'url' ), TemplateRenderer::tokens( 'bridal' ) );
		$this->assertSame( array( 'branch', 'address', 'url' ), TemplateRenderer::tokens( 'showroom' ) );
		$this->assertSame( array(), TemplateRenderer::tokens( 'unknown' ) );
	}

	/**
	 * Each locked token is replaced by its value, and a foreign token is dropped.
	 */
	public function test_every_token_renders(): void {
		$values = array(
			'code'         => 'RJ-1',
			'name_hi'      => 'हार',
			'name_en'      => 'Necklace',
			'metal'        => 'Gold',
			'purity'       => '22K',
			'weight'       => '5.2 g',
			'url'          => 'https://example.org/p',
			'name'         => 'Bridal',
			'title'        => 'Reel',
			'linked_codes' => 'RJ-1, RJ-2',
			'branch'       => 'Jhotwara',
			'address'      => 'Near School, Jaipur',
		);

		foreach ( array( 'product', 'collection', 'reel', 'bridal', 'showroom' ) as $type ) {
			$template = implode( ' ', array_map( static fn( string $token ): string => '{' . $token . '}', TemplateRenderer::tokens( $type ) ) ) . ' {code}{secret}';
			$out      = TemplateRenderer::render( $type, $template, $values );

			foreach ( TemplateRenderer::tokens( $type ) as $token ) {
				$this->assertStringContainsString( $values[ $token ], $out, $type . ':' . $token );
			}

			$this->assertStringNotContainsString( '{', $out, $type );
		}
	}

	/**
	 * Without any WhatsApp number the first valid stored phone gives a tel: link.
	 */
	public function test_missing_number_falls_back_to_a_stored_phone(): void {
		$engine = new WhatsAppEngine(
			static fn(): array => array(
				'active_number'   => '',
				'fallback_number' => '',
				'templates'       => array( 'bridal' => 'Hello {url}' ),
				'availability'    => array( 'mode' => 'always_available' ),
			),
			null,
			static fn(): int => 0,
			static fn(): array => array(
				array(
					'label'    => 'bad',
					'number'   => '123',
					'whatsapp' => false,
				),
				array(
					'label'    => 'shop',
					'number'   => '+918290260806',
					'whatsapp' => false,
				),
			)
		);
		$link   = $engine->link( 'bridal', array( 'url' => 'https://example.org/' ) );

		$this->assertTrue( $link->ok() );
		$this->assertSame( 'tel:+918290260806', $link->url() );
		$this->assertSame( 'call', $link->route() );
	}

	/**
	 * With no number and no valid phone the link is empty, never a bare wa.me address.
	 */
	public function test_no_number_and_no_phone_is_empty(): void {
		$engine = new WhatsAppEngine(
			static fn(): array => array( 'templates' => array( 'bridal' => 'x' ) ),
			null,
			static fn(): int => 0,
			static fn(): array => array( array( 'number' => 'nope' ) )
		);

		$this->assertFalse( $engine->link( 'bridal' )->ok() );
	}

	/**
	 * A change to the stored number changes every link without any caller edit.
	 */
	public function test_global_number_change_reaches_every_link(): void {
		$number = '9811111111';
		$engine = new WhatsAppEngine(
			static function () use ( &$number ): array {
				return array(
					'active_number' => $number,
					'templates'     => array(
						'bridal'     => 'Hi',
						'collection' => 'Hi',
					),
					'availability'  => array( 'mode' => 'always_available' ),
				);
			},
			null,
			static fn(): int => 0
		);

		$this->assertStringContainsString( '919811111111', $engine->link( 'bridal' )->url() );

		$number = '9822222222';

		$this->assertStringContainsString( '919822222222', $engine->link( 'collection' )->url() );
	}
}
