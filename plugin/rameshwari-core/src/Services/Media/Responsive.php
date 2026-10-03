<?php
/**
 * Responsive image sources.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Services\Media;

/**
 * Lists the image files that serve one registered size (blueprint §8).
 *
 * Modern formats come first when their files exist, then the original-format
 * entry, which is always present for a real image. It builds no markup; a
 * picture or srcset element is the Stage 13 renderer's job. Consumers pass the
 * contract name, never a pixel size.
 */
final class Responsive {

	/**
	 * Builds the service.
	 *
	 * @param Sizes   $sizes   The registered size contract.
	 * @param Formats $formats Modern-format sibling discovery.
	 */
	public function __construct( private readonly Sizes $sizes, private readonly Formats $formats ) {
	}

	/**
	 * Sources for an attachment at a registered size.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $contract_name Registered size contract name.
	 * @return array<int,array{mime:string,url:string,width:int,height:int}> Empty for a missing or non-image attachment.
	 * @throws \InvalidArgumentException When the contract name is not registered.
	 */
	public function sources( int $attachment_id, string $contract_name ): array {
		$key = $this->sizes->key( $contract_name );

		if ( $attachment_id < 1 || ! wp_attachment_is_image( $attachment_id ) ) {
			return array();
		}

		$image = wp_get_attachment_image_src( $attachment_id, $key );
		$mime  = get_post_mime_type( $attachment_id );

		if ( ! is_array( $image ) || ! is_string( $mime ) ) {
			return array();
		}

		$url     = (string) $image[0];
		$width   = (int) $image[1];
		$height  = (int) $image[2];
		$entries = $this->modern_entries( $url, $width, $height, $this->derivative_path( $attachment_id, $key, $width ) );

		$entries[] = array(
			'mime'   => $mime,
			'url'    => $url,
			'width'  => $width,
			'height' => $height,
		);

		return $entries;
	}

	/**
	 * Modern-format entries for a derivative whose URL WordPress returned.
	 *
	 * A sibling URL is the returned URL with its file name swapped for the
	 * sibling's. Any query string or fragment is set aside first and put back
	 * unchanged. If the URL's file name is not the derivative's own file name
	 * (for example a CDN rewrote it), nothing is guessed and no modern entry is
	 * returned, so only the original is offered.
	 *
	 * @param string $url             URL WordPress returned for the size.
	 * @param int    $width           Width.
	 * @param int    $height          Height.
	 * @param string $derivative_path Path of the file behind that URL.
	 * @return array<int,array{mime:string,url:string,width:int,height:int}>
	 */
	private function modern_entries( string $url, int $width, int $height, string $derivative_path ): array {
		$siblings = $this->formats->siblings( $derivative_path );
		$parts    = preg_split( '/(?=[?#])/', $url, 2 );
		$base     = is_array( $parts ) ? $parts[0] : '';
		$suffix   = is_array( $parts ) ? ( $parts[1] ?? '' ) : '';
		$slash    = strrpos( $base, '/' );

		if ( array() === $siblings || false === $slash || rawurldecode( substr( $base, $slash + 1 ) ) !== basename( $derivative_path ) ) {
			return array();
		}

		$entries = array();

		foreach ( $siblings as $mime => $sibling_path ) {
			$entries[] = array(
				'mime'   => $mime,
				'url'    => substr( $base, 0, $slash + 1 ) . rawurlencode( basename( $sibling_path ) ) . $suffix,
				'width'  => $width,
				'height' => $height,
			);
		}

		return $entries;
	}

	/**
	 * Path of the file behind a size. Used only to look for siblings; never exposed.
	 *
	 * WordPress returns the full-size file when a size was not generated, so the
	 * derivative is used only when its width matches what was returned.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $key           WordPress size key.
	 * @param int    $width         Width WordPress returned.
	 * @return string
	 */
	private function derivative_path( int $attachment_id, string $key, int $width ): string {
		$full     = get_attached_file( $attachment_id );
		$full     = is_string( $full ) ? $full : '';
		$metadata = wp_get_attachment_metadata( $attachment_id );
		$sizes    = is_array( $metadata ) ? ( $metadata['sizes'] ?? null ) : null;
		$entry    = is_array( $sizes ) ? ( $sizes[ $key ] ?? null ) : null;

		if ( is_array( $entry ) && isset( $entry['file'], $entry['width'] ) && is_string( $entry['file'] ) && (int) $entry['width'] === $width ) {
			return dirname( $full ) . '/' . basename( $entry['file'] );
		}

		return $full;
	}
}
