<?php
/**
 * Output escaping helpers.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Support;

/**
 * Thin, named wrappers over WordPress escaping, one per output context, so
 * that "escape at output" is greppable in review. Escaping happens at the
 * point of output, never at save; sanitising input is Sanitizer's job.
 */
final class Escaper {

	/**
	 * Text inside an HTML element.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function html( string $text ): string {
		return esc_html( $text );
	}

	/**
	 * An HTML attribute value.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function attr( string $text ): string {
		return esc_attr( $text );
	}

	/**
	 * A URL in an href or src. Allows http, https, mailto and tel only.
	 *
	 * @param string $url URL.
	 * @return string Empty for any other scheme.
	 */
	public static function url( string $url ): string {
		return esc_url( $url, array( 'http', 'https', 'mailto', 'tel' ) );
	}

	/**
	 * Text inside a textarea element.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function textarea( string $text ): string {
		return esc_textarea( $text );
	}

	/**
	 * Rich text, filtered to the tags WordPress allows in post content.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function rich( string $html ): string {
		return wp_kses_post( $html );
	}
}
