<?php
/**
 * The WhatsApp engine.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\WhatsApp;

use Rameshwari\Core\Data\Options;
use Rameshwari\Core\Domain\Showroom\Showroom;
use Rameshwari\Core\Domain\Showroom\ShowroomOpenNow;

/**
 * The only place a WhatsApp URL is built. Every enquiry surface calls link().
 *
 * It reads the saved rj_whatsapp settings, decides the route from the primary
 * showroom's open-now state, fills the template and returns the link. It writes
 * nothing and never throws to the caller.
 */
final class WhatsAppEngine {

	private const HOST = 'https://wa.me/';

	/**
	 * Returns the rj_whatsapp settings.
	 *
	 * @var callable(): array<string,mixed>
	 */
	private $options;

	/**
	 * Returns the open-now state of a showroom ID.
	 *
	 * @var callable(int): string
	 */
	private $state;

	/**
	 * Returns the showroom ID that decides availability.
	 *
	 * @var callable(): int
	 */
	private $showroom_id;

	/**
	 * Reads the stored contact phones.
	 *
	 * @var callable
	 */
	private $phones;

	/**
	 * Builds the engine.
	 *
	 * @param callable|null $options     Settings reader. Defaults to Options.
	 * @param callable|null $state       State reader. Defaults to ShowroomOpenNow.
	 * @param callable|null $showroom_id Showroom ID reader. Defaults to the primary showroom.
	 * @param callable|null $phones      Contact phones reader. Defaults to rj_contact.phones.
	 */
	public function __construct( ?callable $options = null, ?callable $state = null, ?callable $showroom_id = null, ?callable $phones = null ) {
		$this->options     = $options ?? static fn(): array => Options::get( 'rj_whatsapp' );
		$this->state       = $state ?? static fn( int $id ): string => ( new ShowroomOpenNow() )->state( $id );
		$this->showroom_id = $showroom_id ?? static function (): int {
			$contact = Options::get( 'rj_contact' );

			return (int) ( $contact['primary_showroom'] ?? 0 );
		};

		$this->phones = $phones ?? static function (): array {
			$contact = Options::get( 'rj_contact' );

			return is_array( $contact['phones'] ?? null ) ? $contact['phones'] : array();
		};
	}

	/**
	 * Builds the enquiry link for a type.
	 *
	 * @param string               $type     product, reel, collection, bridal or showroom.
	 * @param array<string,string> $values   Token values.
	 * @param string               $override Per-product message, used only for products.
	 * @return WhatsAppLink
	 */
	public function link( string $type, array $values = array(), string $override = '' ): WhatsAppLink {
		if ( ! TemplateRenderer::knows( $type ) ) {
			return new WhatsAppLink( '' );
		}

		try {
			$options = ( $this->options )();
		} catch ( \Throwable $failure ) {
			return new WhatsAppLink( '' );
		}

		$availability = is_array( $options['availability'] ?? null ) ? $options['availability'] : array();
		$route        = Availability::route( $availability, $this->state_now( $availability ) );
		$active       = NumberNormalizer::normalize( (string) ( $options['active_number'] ?? '' ) );
		$fallback     = NumberNormalizer::normalize( (string) ( $options['fallback_number'] ?? '' ) );
		$number       = Availability::FALLBACK === $route ? ( '' !== $fallback ? $fallback : $active ) : ( '' !== $active ? $active : $fallback );

		if ( '' === $number ) {
			return $this->call_fallback( $type, $values, $override, $options );
		}

		$templates = is_array( $options['templates'] ?? null ) ? $options['templates'] : array();
		$template  = ( 'product' === $type && '' !== trim( $override ) ) ? $override : (string) ( $templates[ $type ] ?? '' );
		$message   = TemplateRenderer::render( $type, $template, $values );

		if ( Availability::CALL === $route ) {
			return $this->call_fallback( $type, $values, $override, $options );
		}

		return $this->finish( new WhatsAppLink( self::HOST . $number . '?text=' . rawurlencode( $message ), $route, $number, $message ), $type );
	}

	/**
	 * The primary showroom's state, or open when hours are not in use or cannot be read.
	 *
	 * @param array<string,mixed> $availability Availability settings.
	 * @return string
	 */
	private function state_now( array $availability ): string {
		if ( 'always_available' === ( $availability['mode'] ?? 'showroom_hours' ) ) {
			return Showroom::STATE_OPEN;
		}

		try {
			return (string) ( $this->state )( (int) ( $this->showroom_id )() );
		} catch ( \Throwable $failure ) {
			return Showroom::STATE_UNAVAILABLE;
		}
	}

	/**
	 * Call Us link from the first valid stored contact phone, used when no WhatsApp number exists.
	 *
	 * @param string               $type     Message type.
	 * @param array<string,string> $values   Token values.
	 * @param string               $override Product override.
	 * @param array<string,mixed>  $options  The rj_whatsapp option.
	 * @return WhatsAppLink
	 */
	private function call_fallback( string $type, array $values, string $override, array $options ): WhatsAppLink {
		try {
			$records = ( $this->phones )();
		} catch ( \Throwable $failure ) {
			return new WhatsAppLink( '' );
		}

		foreach ( $records as $record ) {
			$number = is_array( $record ) ? (string) ( $record['number'] ?? '' ) : '';

			if ( 1 !== preg_match( '/^\+[1-9]\d{7,14}$/', $number ) ) {
				continue;
			}

			$templates = is_array( $options['templates'] ?? null ) ? $options['templates'] : array();
			$template  = ( 'product' === $type && '' !== trim( $override ) ) ? $override : (string) ( $templates[ $type ] ?? '' );
			$digits    = substr( $number, 1 );

			return $this->finish( new WhatsAppLink( 'tel:' . $number, Availability::CALL, $digits, TemplateRenderer::render( $type, $template, $values ) ), $type );
		}

		return new WhatsAppLink( '' );
	}

	/**
	 * Applies the one interception filter. A result that is not a wa.me or tel: link is ignored.
	 *
	 * @param WhatsAppLink $link Built link.
	 * @param string       $type Message type.
	 * @return WhatsAppLink
	 */
	private function finish( WhatsAppLink $link, string $type ): WhatsAppLink {
		if ( ! function_exists( 'apply_filters' ) ) {
			return $link;
		}

		$changed = apply_filters(
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- rj_whatsapp_generated_url is the approved, documented public filter name; renaming it would break the locked contract.
			'rj_whatsapp_generated_url',
			$link->url(),
			array(
				'type'    => $type,
				'route'   => $link->route(),
				'number'  => $link->number(),
				'message' => $link->message(),
			)
		);

		if ( is_string( $changed ) && $changed !== $link->url() && $this->valid_target( $changed ) ) {
			return new WhatsAppLink( $changed, $link->route(), $link->number(), $link->message() );
		}

		return $link;
	}

	/**
	 * Whether a filtered target is a complete https wa.me link or a tel: number.
	 *
	 * Anything after the number other than a text query, any other host, and any
	 * malformed number is refused, so the engine's own URL stays in force.
	 *
	 * @param string $url Candidate URL.
	 * @return bool
	 */
	private function valid_target( string $url ): bool {
		return 1 === preg_match( '#^https://wa\.me/[1-9]\d{7,14}(\?text=(?:[A-Za-z0-9._~-]|%[0-9A-Fa-f]{2})*)?$#', $url )
			|| 1 === preg_match( '#^tel:\+[1-9]\d{7,14}$#', $url );
	}
}
