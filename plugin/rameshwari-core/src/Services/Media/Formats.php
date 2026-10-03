<?php
/**
 * Modern image formats.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Services\Media;

/**
 * Detects WebP and AVIF support and writes modern-format siblings (blueprint §7).
 *
 * Modern formats are additive only. The original and its original-format
 * derivatives are never replaced or deleted, and any conversion failure leaves
 * the upload exactly as WordPress produced it. Siblings are found by file
 * existence, so nothing is stored. Nothing is wired to a hook here; convert()
 * is the callback the future module attaches to wp_generate_attachment_metadata.
 */
final class Formats {

	/**
	 * Modern MIME types in delivery order, mapped to file extension.
	 *
	 * @var array<string,string>
	 */
	private const MODERN = array(
		'image/avif' => 'avif',
		'image/webp' => 'webp',
	);

	/**
	 * Original MIME types worth converting. A WebP original is converted only to
	 * the other modern format, never to itself.
	 *
	 * @var array<int,string>
	 */
	private const CONVERTIBLE = array( 'image/jpeg', 'image/png', 'image/webp' );

	/**
	 * Whether this host can write WebP.
	 *
	 * @return bool
	 */
	public function supports_webp(): bool {
		return $this->supports( 'image/webp' );
	}

	/**
	 * Whether this host can write AVIF.
	 *
	 * @return bool
	 */
	public function supports_avif(): bool {
		return $this->supports( 'image/avif' );
	}

	/**
	 * Findings for the Health screen. Pure: it only reads host support.
	 *
	 * @return array<int,string>
	 */
	public function health(): array {
		$findings = array();

		if ( ! $this->supports_webp() ) {
			$findings[] = 'WebP is not supported by this host.';
		}

		if ( ! $this->supports_avif() ) {
			$findings[] = 'AVIF is not supported by this host.';
		}

		return $findings;
	}

	/**
	 * Existing modern siblings of a derivative, AVIF first, found by file existence.
	 *
	 * @param string $derivative_path Path of an original-format derivative.
	 * @return array<string,string> MIME type => sibling path.
	 */
	public function siblings( string $derivative_path ): array {
		$found = array();
		$stem  = pathinfo( $derivative_path, PATHINFO_DIRNAME ) . '/' . pathinfo( $derivative_path, PATHINFO_FILENAME );

		foreach ( self::MODERN as $mime => $extension ) {
			$candidate = $stem . '.' . $extension;

			if ( $candidate !== $derivative_path && is_file( $candidate ) ) {
				$found[ $mime ] = $candidate;
			}
		}

		return $found;
	}

	/**
	 * Writes a modern sibling for each generated size. Always returns the metadata unchanged.
	 *
	 * Filter callback for wp_generate_attachment_metadata (accepts two arguments).
	 *
	 * @param array<string,mixed> $metadata      Attachment metadata.
	 * @param int                 $attachment_id Attachment ID.
	 * @return array<string,mixed>
	 */
	public function convert( array $metadata, int $attachment_id ): array {
		$sizes = $metadata['sizes'] ?? null;
		$full  = get_attached_file( $attachment_id );

		if ( ! is_array( $sizes ) || ! is_string( $full ) || '' === $full ) {
			return $metadata;
		}

		$targets = array_filter(
			self::MODERN,
			fn ( string $extension, string $mime ): bool => $this->supports( $mime ),
			ARRAY_FILTER_USE_BOTH
		);

		if ( array() === $targets ) {
			return $metadata;
		}

		foreach ( $sizes as $entry ) {
			$file = is_array( $entry ) ? ( $entry['file'] ?? null ) : null;
			$mime = is_array( $entry ) ? ( $entry['mime-type'] ?? null ) : null;

			if ( ! is_string( $file ) || ! is_string( $mime ) || ! in_array( $mime, self::CONVERTIBLE, true ) ) {
				continue;
			}

			$source = dirname( $full ) . '/' . basename( $file );

			if ( ! is_file( $source ) ) {
				continue;
			}

			foreach ( $targets as $target_mime => $extension ) {
				if ( $target_mime === $mime ) {
					continue;
				}

				$destination = dirname( $source ) . '/' . pathinfo( $source, PATHINFO_FILENAME ) . '.' . $extension;

				if ( ! file_exists( $destination ) ) {
					$this->write( $source, $destination, $target_mime );
				}
			}
		}

		return $metadata;
	}

	/**
	 * Whether the active image editor can write a MIME type.
	 *
	 * @param string $mime MIME type.
	 * @return bool
	 */
	private function supports( string $mime ): bool {
		return (bool) wp_image_editor_supports( array( 'mime_type' => $mime ) );
	}

	/**
	 * Writes one sibling. A failure is swallowed on purpose: the original stays usable.
	 *
	 * @param string $source      Source derivative path.
	 * @param string $destination Sibling path.
	 * @param string $mime        Target MIME type.
	 * @return void
	 */
	private function write( string $source, string $destination, string $mime ): void {
		try {
			$editor = wp_get_image_editor( $source );

			if ( ! is_wp_error( $editor ) ) {
				$editor->save( $destination, $mime );
			}
		} catch ( \Throwable $failure ) {
			return;
		}
	}
}
