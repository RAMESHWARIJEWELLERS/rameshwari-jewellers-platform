<?php
/**
 * Public lead-capture handler.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\WhatsApp;

/**
 * The admin-post entry point for a WhatsApp enquiry.
 *
 * It resolves the object on the server, builds the link through the one engine,
 * records a lead only when the request is verified, and always continues to the
 * link. A failed or skipped lead never stops the visitor reaching WhatsApp or the
 * call fallback. No browser form posts to this action yet: the theme must add a
 * form that sends rj_type, rj_object, rj_nonce and rj_intent (all issued by form_fields()), and the nonce comes
 * from wp_create_nonce( LeadHandler::ACTION ).
 */
final class LeadHandler {

	public const ACTION = 'rj_whatsapp_lead_capture';

	/**
	 * Resolves a trusted enquiry context from the server's own data.
	 *
	 * @var ContextResolver
	 */
	private ContextResolver $resolver;

	/**
	 * Issues and consumes enquiry intents.
	 *
	 * @var IntentIssuer
	 */
	private IntentIssuer $intents;

	/**
	 * Builds the handler.
	 *
	 * @param WhatsAppEngine       $engine   The link builder.
	 * @param LeadCapture          $capture  The lead service.
	 * @param ContextResolver|null $resolver Context resolver.
	 * @param IntentIssuer|null    $intents  Single-use intent tokens.
	 */
	public function __construct( private WhatsAppEngine $engine, private LeadCapture $capture, ?ContextResolver $resolver = null, ?IntentIssuer $intents = null ) {
		$this->resolver = $resolver ?? new ContextResolver();
		$this->intents  = $intents ?? new IntentIssuer();
	}

	/**
	 * The hidden fields a form must post: type, resolved object, nonce and a fresh single-use intent.
	 *
	 * This is the only seam a theme, block or shortcode needs; none of them builds a WhatsApp link.
	 *
	 * @param string $type      Enquiry type.
	 * @param int    $object_id Object ID (ignored for types without an object).
	 * @return array<string,string|int>|null Null when the enquiry cannot be resolved.
	 */
	public function form_fields( string $type, int $object_id ): ?array {
		$type    = sanitize_key( $type );
		$context = $this->resolver->resolve( $type, $object_id );

		if ( null === $context ) {
			return null;
		}

		$intent = $this->intents->issue( $type, (int) $context['object'] );

		return array(
			'rj_type'   => $type,
			'rj_object' => (int) $context['object'],
			'rj_nonce'  => wp_create_nonce( self::ACTION ),
			'rj_intent' => $intent['token'],
		);
	}

	/**
	 * Handles the admin-post request and redirects.
	 *
	 * @return void
	 */
	public function handle(): void {
		$post   = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in process().
		$server = wp_unslash( $_SERVER );
		$url    = $this->request( is_array( $post ) ? $post : array(), is_array( $server ) ? $server : array() );

		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- The target is a wa.me or tel: link built by the engine, or the home page.
		exit;
	}

	/**
	 * Resolves, builds the link and records the lead when verified.
	 *
	 * @param array<string,mixed> $post   Unslashed POST data.
	 * @param array<string,mixed> $server Unslashed server data.
	 * @return string Redirect target.
	 */
	public function process( array $post, array $server ): string {
		$home      = home_url( '/' );
		$type      = $this->field( $post, 'rj_type' );
		$object_id = $this->field( $post, 'rj_object' );

		if ( null === $type || null === $object_id || ! $this->digits( $object_id, 12 ) ) {
			return $home;
		}

		$type    = sanitize_key( $type );
		$context = $this->resolver->resolve( $type, absint( $object_id ) );

		if ( null === $context ) {
			return $home;
		}

		$link = $this->engine->link( $type, $context['values'], $context['override'] );

		if ( ! $link->ok() ) {
			return $home;
		}

		$nonce  = $this->field( $post, 'rj_nonce' );
		$phone  = $this->field( $post, 'rj_phone' );
		$trap   = $this->field( $post, 'rj_website' );
		$intent = $this->field( $post, 'rj_intent' );

		if ( null === $nonce || null === $phone || null === $trap || null === $intent || ! $this->trusted( $nonce, $server ) ) {
			return $link->url();
		}

		$verified = $this->intents->check( $intent, $type, (int) $context['object'] );

		if ( null === $verified || ! $this->intents->consume( $verified['id'] ) ) {
			return $link->url();
		}

		$user    = get_current_user_id();
		$outcome = $this->capture->capture(
			array(
				'source'      => $type,
				'object_id'   => $context['object'],
				'object_code' => $context['code'],
				'customer_id' => $user,
				'phone'       => $user > 0 ? '' : NumberNormalizer::normalize( sanitize_text_field( $phone ) ),
				'message'     => $link->message(),
				'page_url'    => $context['page'],
				'referrer'    => esc_url_raw( $this->header( $server, 'HTTP_REFERER' ) ),
				'honeypot'    => $trap,
				'started_at'  => $verified['issued'],
			),
			sanitize_text_field( $this->header( $server, 'REMOTE_ADDR' ) )
		);

		unset( $outcome );

		return $link->url();
	}

	/**
	 * The only entry for an HTTP request: anything but POST goes home and never reaches lead capture.
	 *
	 * @param array<string,mixed> $post   Unslashed POST data.
	 * @param array<string,mixed> $server Unslashed server data.
	 * @return string Redirect target.
	 */
	public function request( array $post, array $server ): string {
		if ( 'POST' !== $this->header( $server, 'REQUEST_METHOD' ) ) {
			return home_url( '/' );
		}

		return $this->process( $post, $server );
	}

	/**
	 * Whether the nonce is valid and the request came from this exact origin.
	 *
	 * The Origin header decides when present, otherwise the Referer. Scheme, host
	 * and effective port must all match the site. With neither header, or any
	 * mismatch, no lead is stored and the visitor still continues.
	 *
	 * @param string              $nonce  Nonce field, already confirmed to be a string.
	 * @param array<string,mixed> $server Server data.
	 * @return bool
	 */
	private function trusted( string $nonce, array $server ): bool {
		if ( false === wp_verify_nonce( $nonce, self::ACTION ) ) {
			return false;
		}

		$origin = $this->header( $server, 'HTTP_ORIGIN' );
		$site   = $this->tuple( home_url() );

		if ( '' !== $origin ) {
			return null !== $site && $this->is_origin( $origin ) && $this->tuple( $origin ) === $site;
		}

		$referer = $this->header( $server, 'HTTP_REFERER' );
		$sent    = '' === $referer ? null : $this->tuple( $referer );

		return null !== $site && null !== $sent && $sent === $site;
	}

	/**
	 * Scheme, host and effective port of a URL, or null when any is missing.
	 *
	 * @param string $url URL.
	 * @return string|null
	 */
	private function tuple( string $url ): ?string {
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return null;
		}

		$scheme = strtolower( (string) $parts['scheme'] );
		$port   = isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === $scheme ? 443 : 80 );

		return $scheme . '://' . strtolower( (string) $parts['host'] ) . ':' . $port;
	}

	/**
	 * Whether a header value is a serialized origin: scheme, host and optional port only.
	 *
	 * User information, a path (even a single slash), a query and a fragment make it invalid.
	 *
	 * @param string $value Origin header value.
	 * @return bool
	 */
	private function is_origin( string $value ): bool {
		return 1 === preg_match( '#^https?://[A-Za-z0-9.-]+(:[0-9]{1,5})?$#', $value );
	}

	/**
	 * A request field as text: a string or an integer. Arrays, objects and other types are malformed (null).
	 *
	 * @param array<string,mixed> $post Request data.
	 * @param string              $key  Field name.
	 * @return string|null Empty when absent, null when malformed.
	 */
	private function field( array $post, string $key ): ?string {
		if ( ! array_key_exists( $key, $post ) ) {
			return '';
		}

		$value = $post[ $key ];

		if ( is_string( $value ) ) {
			return $value;
		}

		return is_int( $value ) ? (string) $value : null;
	}

	/**
	 * Whether a text value is empty or only digits, up to a length.
	 *
	 * @param string $value Text.
	 * @param int    $max   Maximum length.
	 * @return bool
	 */
	private function digits( string $value, int $max ): bool {
		return '' === $value || ( strlen( $value ) <= $max && ctype_digit( $value ) );
	}

	/**
	 * A server value as text; anything that is not a string counts as absent.
	 *
	 * @param array<string,mixed> $server Server data.
	 * @param string              $key    Key.
	 * @return string
	 */
	private function header( array $server, string $key ): string {
		return isset( $server[ $key ] ) && is_string( $server[ $key ] ) ? $server[ $key ] : '';
	}
}
