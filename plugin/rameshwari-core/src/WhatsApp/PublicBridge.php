<?php
/**
 * Public WhatsApp URL bridge.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\WhatsApp;

use Rameshwari\Core\Plugin;

/**
 * Turns a small public context into an engine-built enquiry link.
 *
 * The context is only a type and an object ID. Everything else, including the page
 * address, the names and codes, the product message and the numbers, is resolved
 * on the server by ContextResolver and built by the one registered engine. Any
 * other key, a bad type or ID, an object that is not public, or an engine that is
 * not available gives an empty string and no link. This class builds no URL itself.
 */
final class PublicBridge {

	/**
	 * Supported types.
	 */
	public const TYPES = array( 'product', 'reel', 'collection', 'bridal', 'showroom' );

	/**
	 * Returns the engine, or null when it is unavailable.
	 *
	 * @var callable(): ?WhatsAppEngine
	 */
	private $engine;

	/**
	 * Resolves a type and ID to trusted context, or null.
	 *
	 * @var callable(string, int): ?array<string,mixed>
	 */
	private $resolver;

	/**
	 * Builds the bridge.
	 *
	 * @param callable|null $engine   Engine provider. Defaults to the registered whatsapp.engine service.
	 * @param callable|null $resolver Context resolver. Defaults to ContextResolver.
	 */
	public function __construct( ?callable $engine = null, ?callable $resolver = null ) {
		$this->engine   = $engine ?? array( self::class, 'registered_engine' );
		$this->resolver = $resolver ?? static fn( string $type, int $object_id ): ?array => ( new ContextResolver() )->resolve( $type, $object_id );
	}

	/**
	 * The engine registered in the plugin container, or null before the plugin has booted.
	 *
	 * @return WhatsAppEngine|null
	 */
	public static function registered_engine(): ?WhatsAppEngine {
		$plugin = Plugin::instance();

		if ( null === $plugin ) {
			return null;
		}

		try {
			$engine = $plugin->container()->get( 'whatsapp.engine' );
		} catch ( \Throwable $failure ) {
			return null;
		}

		return $engine instanceof WhatsAppEngine ? $engine : null;
	}

	/**
	 * The enquiry link for a context, or an empty string.
	 *
	 * @param array<mixed> $context Exactly type and object_id; for bridal, object_id may be absent or zero, but never an explicit null.
	 * @return string
	 */
	public function url( array $context ): string {
		$parsed = self::parse( $context );

		if ( null === $parsed ) {
			return '';
		}

		try {
			$engine = ( $this->engine )();

			if ( ! $engine instanceof WhatsAppEngine ) {
				return '';
			}

			$resolved = ( $this->resolver )( $parsed['type'], $parsed['object_id'] );

			if ( ! is_array( $resolved ) || ! is_array( $resolved['values'] ?? null ) || ! is_string( $resolved['override'] ?? null ) ) {
				return '';
			}

			return $engine->link( $parsed['type'], $resolved['values'], $resolved['override'] )->url();
		} catch ( \Throwable $failure ) {
			return '';
		}
	}

	/**
	 * Validates the context shape.
	 *
	 * @param array<mixed> $context Public context.
	 * @return array{type: string, object_id: int}|null
	 */
	private static function parse( array $context ): ?array {
		if ( array() !== array_diff( array_keys( $context ), array( 'type', 'object_id' ) ) ) {
			return null;
		}

		$type = $context['type'] ?? null;

		if ( ! is_string( $type ) || ! in_array( $type, self::TYPES, true ) ) {
			return null;
		}

		$has = array_key_exists( 'object_id', $context );
		$raw = $has ? $context['object_id'] : null;

		if ( 'bridal' === $type ) {
			return ( ! $has || 0 === $raw || '0' === $raw ) ? array(
				'type'      => $type,
				'object_id' => 0,
			) : null;
		}

		if ( is_string( $raw ) && 1 === preg_match( '/^[1-9][0-9]{0,11}$/', $raw ) ) {
			$raw = (int) $raw;
		}

		if ( ! is_int( $raw ) || $raw < 1 ) {
			return null;
		}

		return array(
			'type'      => $type,
			'object_id' => $raw,
		);
	}
}
