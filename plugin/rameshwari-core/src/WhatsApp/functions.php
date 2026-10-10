<?php
/**
 * Public WhatsApp helper.
 *
 * @package Rameshwari
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- `rj` is the approved project prefix; WPCS rejects prefixes under three characters. Scoped to the approved public helper below.

if ( ! function_exists( 'rj_whatsapp_url' ) ) {
	/**
	 * Builds a WhatsApp enquiry link for a type and object ID.
	 *
	 * Callers pass only type and object_id. The link comes from the one registered
	 * engine; an empty string means no safe link is available.
	 *
	 * @param array<mixed> $context Keys: type (product, reel, collection, bridal, showroom) and object_id.
	 * @return string The link, or an empty string.
	 */
	function rj_whatsapp_url( array $context ): string {
		return ( new \Rameshwari\Core\WhatsApp\PublicBridge() )->url( $context );
	}
}
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
