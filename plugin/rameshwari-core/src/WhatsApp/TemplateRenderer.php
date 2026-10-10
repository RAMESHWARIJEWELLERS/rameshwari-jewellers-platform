<?php
/**
 * Message template rendering.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\WhatsApp;

/**
 * Fills a message template. Each type allows only its own tokens; any other
 * token is removed. The result never exceeds the limit, and the URL is never cut.
 */
final class TemplateRenderer {

	public const MAX = 900;

	private const TOKENS = array(
		'product'    => array( 'code', 'name_hi', 'name_en', 'metal', 'purity', 'weight', 'url' ),
		'collection' => array( 'name', 'url' ),
		'reel'       => array( 'title', 'url', 'linked_codes' ),
		'bridal'     => array( 'url' ),
		'showroom'   => array( 'branch', 'address', 'url' ),
	);

	/**
	 * The tokens a message type allows.
	 *
	 * @param string $type Message type.
	 * @return array<int,string>
	 */
	public static function tokens( string $type ): array {
		return self::TOKENS[ $type ] ?? array();
	}

	/**
	 * Whether a template type exists.
	 *
	 * @param string $type Type.
	 * @return bool
	 */
	public static function knows( string $type ): bool {
		return isset( self::TOKENS[ $type ] );
	}

	/**
	 * Renders a template.
	 *
	 * @param string               $type     Template type.
	 * @param string               $template Template text.
	 * @param array<string,string> $values   Token values.
	 * @param int                  $max      Character limit.
	 * @return string
	 */
	public static function render( string $type, string $template, array $values, int $max = self::MAX ): string {
		$allowed = self::TOKENS[ $type ] ?? array();
		$url     = in_array( 'url', $allowed, true ) ? self::clean( (string) ( $values['url'] ?? '' ) ) : '';
		$text    = preg_replace_callback(
			'/\{([a-z_]+)\}/',
			static function ( array $found ) use ( $allowed, $values ): string {
				if ( 'url' === $found[1] ) {
					return "\x1F";
				}

				return in_array( $found[1], $allowed, true ) ? self::clean( (string) ( $values[ $found[1] ] ?? '' ) ) : '';
			},
			$template
		);

		$text  = is_string( $text ) ? $text : '';
		$parts = explode( "\x1F", $text );
		$room  = max( 0, $max - mb_strlen( $url ) * ( count( $parts ) - 1 ) );
		$out   = '';

		foreach ( $parts as $index => $part ) {
			if ( mb_strlen( $part ) > $room ) {
				$part = self::cut( $part, $room );
			}

			$room -= mb_strlen( $part );
			$out  .= $part . ( $index < count( $parts ) - 1 ? $url : '' );
		}

		return trim( $out );
	}

	/**
	 * Removes control characters.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private static function clean( string $value ): string {
		$value = preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $value );

		return trim( is_string( $value ) ? $value : '' );
	}

	/**
	 * Cuts text to a length at a word boundary where one exists.
	 *
	 * @param string $text Text.
	 * @param int    $room Characters allowed.
	 * @return string
	 */
	private static function cut( string $text, int $room ): string {
		$head = mb_substr( $text, 0, $room );
		$next = mb_substr( $text, $room, 1 );

		$space = mb_strrpos( $head, ' ' );

		if ( ' ' !== $next && false !== $space && $space > 0 ) {
			$head = mb_substr( $head, 0, $space );
		}

		return rtrim( $head );
	}
}
