<?php
/**
 * Public bridge tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Unit\WhatsApp;

use PHPUnit\Framework\TestCase;
use Rameshwari\Core\WhatsApp\PublicBridge;
use Rameshwari\Core\WhatsApp\WhatsAppEngine;

/**
 * The bridge accepts only a type and an object ID, and delegates everything else.
 */
final class PublicBridgeTest extends TestCase {

	private const TEMPLATES = array(
		'product'    => 'P {code} {url}',
		'reel'       => 'R {title} {url}',
		'collection' => 'C {name} {url}',
		'bridal'     => 'B {url}',
		'showroom'   => 'S {branch} {url}',
	);

	/**
	 * Calls the resolver received.
	 *
	 * @var array<int,array{0: string, 1: int}>
	 */
	private array $calls = array();

	/**
	 * A real engine with fixed settings.
	 *
	 * @return WhatsAppEngine
	 */
	private function engine(): WhatsAppEngine {
		return new WhatsAppEngine(
			static fn(): array => array(
				'active_number'   => '9876543210',
				'fallback_number' => '9123456780',
				'templates'       => self::TEMPLATES,
				'availability'    => array(
					'mode'          => 'always_available',
					'outside_hours' => 'use_fallback',
				),
			),
			static fn(): string => 'open',
			static fn(): int => 0,
			static fn(): array => array()
		);
	}

	/**
	 * A bridge with a spying resolver.
	 *
	 * @param array<string,mixed>|null $resolved Resolver result.
	 * @param bool                     $engine   Whether the engine is available.
	 * @return PublicBridge
	 */
	private function bridge( ?array $resolved, bool $engine = true ): PublicBridge {
		return new PublicBridge(
			$engine ? fn(): WhatsAppEngine => $this->engine() : static fn(): ?WhatsAppEngine => null,
			function ( string $type, int $id ) use ( $resolved ): ?array {
				$this->calls[] = array( $type, $id );

				return $resolved;
			}
		);
	}

	/**
	 * A resolved context.
	 *
	 * @param array<string,string> $values Token values.
	 * @param string               $override Product message override.
	 * @return array<string,mixed>
	 */
	private function resolved( array $values, string $override = '' ): array {
		return array(
			'values'   => $values,
			'override' => $override,
			'code'     => '',
			'object'   => 0,
			'page'     => '',
		);
	}

	/**
	 * Every supported type yields an engine link carrying the resolved values.
	 */
	public function test_every_type_builds_a_link_through_the_engine(): void {
		$cases = array(
			array( 'product', 7, 'code', 'TT-1', 'P TT-1 ' ),
			array( 'reel', 8, 'title', 'Bridal reel', 'R Bridal reel ' ),
			array( 'collection', 9, 'name', 'Festive', 'C Festive ' ),
			array( 'showroom', 10, 'branch', 'Jhotwara', 'S Jhotwara ' ),
			array( 'bridal', 0, 'url', 'https://example.org/', 'B ' ),
		);

		foreach ( $cases as $case ) {
			$values = array( $case[2] => $case[3] );

			if ( 'bridal' !== $case[0] ) {
				$values['url'] = 'https://example.org/x';
			}

			$url = $this->bridge( $this->resolved( $values ) )->url(
				array(
					'type'      => $case[0],
					'object_id' => $case[1],
				)
			);

			$this->assertStringStartsWith( 'https://wa.me/919876543210?text=', $url, $case[0] );
			$this->assertStringStartsWith( $case[4], rawurldecode( (string) substr( $url, strlen( 'https://wa.me/919876543210?text=' ) ) ), $case[0] );
		}
	}

	/**
	 * A stored per-product message override reaches the engine unchanged.
	 */
	public function test_product_override_is_used(): void {
		$url = $this->bridge( $this->resolved( array( 'code' => 'TT-1' ), 'Custom {code}' ) )->url(
			array(
				'type'      => 'product',
				'object_id' => 7,
			)
		);

		$this->assertSame( 'Custom TT-1', rawurldecode( (string) substr( $url, strlen( 'https://wa.me/919876543210?text=' ) ) ) );
	}

	/**
	 * Invalid contexts give an empty string and never reach the resolver.
	 */
	public function test_invalid_context_is_refused_without_resolving(): void {
		$bad = array(
			array(),
			array( 'type' => 'product' ),
			array( 'object_id' => 5 ),
			array(
				'type'      => 'lead',
				'object_id' => 5,
			),
			array(
				'type'      => 'PRODUCT',
				'object_id' => 5,
			),
			array(
				'type'      => array( 'product' ),
				'object_id' => 5,
			),
			array(
				'type'      => 'product',
				'object_id' => 0,
			),
			array(
				'type'      => 'product',
				'object_id' => -3,
			),
			array(
				'type'      => 'product',
				'object_id' => '12abc',
			),
			array(
				'type'      => 'product',
				'object_id' => 1.5,
			),
			array(
				'type'      => 'product',
				'object_id' => true,
			),
			array(
				'type'      => 'product',
				'object_id' => array( 5 ),
			),
			array(
				'type'      => 'product',
				'object_id' => '0123',
			),
			array(
				'type'      => 'bridal',
				'object_id' => 9,
			),
			array(
				'type'      => 'bridal',
				'object_id' => array( 1 ),
			),
			array(
				'type'      => 'bridal',
				'object_id' => null,
			),
			array(
				'type'      => 'product',
				'object_id' => null,
			),
		);

		foreach ( $bad as $index => $context ) {
			$this->assertSame( '', $this->bridge( $this->resolved( array( 'url' => 'x' ) ) )->url( $context ), 'bad case ' . $index );
		}

		$this->assertSame( array(), $this->calls );
	}

	/**
	 * Client-supplied message, page, phone and URL fields are not accepted.
	 */
	public function test_client_supplied_fields_are_rejected(): void {
		foreach ( array( 'message', 'page', 'url', 'phone', 'title', 'code', 'number', 'template' ) as $key ) {
			$this->assertSame(
				'',
				$this->bridge( $this->resolved( array( 'url' => 'x' ) ) )->url(
					array(
						'type'      => 'reel',
						'object_id' => 4,
						$key        => 'https://evil.example/',
					)
				),
				$key
			);
		}

		$this->assertSame( array(), $this->calls );
	}

	/**
	 * A numeric-string ID is accepted as the same ID.
	 */
	public function test_numeric_string_id_is_normalised(): void {
		$url = $this->bridge( $this->resolved( array( 'name' => 'F' ) ) )->url(
			array(
				'type'      => 'collection',
				'object_id' => '42',
			)
		);

		$this->assertNotSame( '', $url );
		$this->assertSame( array( array( 'collection', 42 ) ), $this->calls );
	}

	/**
	 * Bridal ignores any ID: it takes none, or a literal zero, and resolves with zero.
	 */
	public function test_bridal_context(): void {
		$bridge = $this->bridge( $this->resolved( array( 'url' => 'https://example.org/' ) ) );

		$this->assertNotSame( '', $bridge->url( array( 'type' => 'bridal' ) ) );
		$this->assertNotSame(
			'',
			$bridge->url(
				array(
					'type'      => 'bridal',
					'object_id' => 0,
				)
			)
		);
		$this->assertSame( array( array( 'bridal', 0 ), array( 'bridal', 0 ) ), $this->calls );
	}

	/**
	 * An unknown, unpublished or hidden object resolves to nothing and gives no link.
	 */
	public function test_unresolvable_object_gives_no_link(): void {
		$this->assertSame(
			'',
			$this->bridge( null )->url(
				array(
					'type'      => 'product',
					'object_id' => 99,
				)
			)
		);
		$this->assertSame( array( array( 'product', 99 ) ), $this->calls );
	}

	/**
	 * A malformed resolver result gives no link.
	 */
	public function test_malformed_resolver_result_gives_no_link(): void {
		foreach ( array( array(), array( 'values' => 'x' ), array( 'values' => array() ) ) as $result ) {
			$this->assertSame(
				'',
				$this->bridge( $result )->url(
					array(
						'type'      => 'reel',
						'object_id' => 4,
					)
				)
			);
		}
	}

	/**
	 * With no engine available the bridge gives an empty string and does not resolve.
	 */
	public function test_unavailable_engine_gives_no_link(): void {
		$this->assertSame(
			'',
			$this->bridge( $this->resolved( array( 'url' => 'x' ) ), false )->url(
				array(
					'type'      => 'reel',
					'object_id' => 4,
				)
			)
		);
		$this->assertSame( array(), $this->calls );
	}

	/**
	 * A resolver or engine that throws is contained.
	 */
	public function test_throwing_dependencies_are_contained(): void {
		$context = array(
			'type'      => 'reel',
			'object_id' => 4,
		);

		$bad_resolver = new PublicBridge(
			fn(): WhatsAppEngine => $this->engine(),
			static function (): ?array {
				throw new \RuntimeException( 'resolver down' );
			}
		);
		$bad_engine   = new PublicBridge(
			static function (): ?WhatsAppEngine {
				throw new \RuntimeException( 'engine down' );
			},
			fn(): array => $this->resolved( array( 'url' => 'x' ) )
		);

		$this->assertSame( '', $bad_resolver->url( $context ) );
		$this->assertSame( '', $bad_engine->url( $context ) );
	}

	/**
	 * The bridge never builds a URL itself: its source has no wa.me literal and no urlencoding.
	 */
	public function test_bridge_source_builds_no_url(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/plugin/rameshwari-core/src/WhatsApp/PublicBridge.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source read in a test.

		$this->assertStringNotContainsString( 'wa.me', $source );
		$this->assertStringNotContainsString( 'rawurlencode', $source );
		$this->assertStringNotContainsString( 'urlencode', $source );
		$this->assertStringNotContainsString( 'tel:', $source );
	}
}
