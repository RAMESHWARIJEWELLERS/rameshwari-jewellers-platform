<?php
/**
 * Alt text generation and the native alt field.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Services\Media;

use Rameshwari\Core\Services\Media\Value\AltContext;

/**
 * Builds alt text from a context and fills the native alt field when it is blank (blueprint §11.9).
 *
 * The generate() method is pure: it uses only the context it is given. The
 * apply() method uses WordPress's own attachment alt field and no other
 * storage. A manual value
 * is never overwritten, and whitespace alone counts as blank. One attachment
 * may have several owners; the first one to attach it supplies the context.
 */
final class AltText {

	/**
	 * The native attachment alt field.
	 */
	public const META_KEY = '_wp_attachment_image_alt';

	/**
	 * Builds the alt text: the name, then the role, then the category.
	 *
	 * With both names it reads "English name (Hindi name)"; with one, that one.
	 *
	 * @param AltContext $c Context.
	 * @return string Never blank.
	 */
	public function generate( AltContext $c ): string {
		$name = '' !== $c->owner_en() ? $c->owner_en() : $c->owner_hi();

		if ( '' !== $c->owner_en() && '' !== $c->owner_hi() && $c->owner_en() !== $c->owner_hi() ) {
			$name .= ' (' . $c->owner_hi() . ')';
		}

		return implode( ', ', array_filter( array( $name, $c->role(), $c->category() ), static fn ( string $part ): bool => '' !== $part ) );
	}

	/**
	 * Writes generated alt text to the attachment, but only if its alt field is blank.
	 *
	 * @param int        $attachment_id Attachment ID.
	 * @param AltContext $c             Context.
	 * @return bool True when a value was written; false when nothing was written
	 *              because the attachment is unknown or already has an alt text.
	 */
	public function apply( int $attachment_id, AltContext $c ): bool {
		if ( $attachment_id < 1 || 'attachment' !== get_post_type( $attachment_id ) ) {
			return false;
		}

		if ( ! self::is_blank( get_post_meta( $attachment_id, self::META_KEY, true ) ) ) {
			return false;
		}

		$alt = $this->generate( $c );

		update_post_meta( $attachment_id, self::META_KEY, wp_slash( $alt ) );

		return get_post_meta( $attachment_id, self::META_KEY, true ) === $alt;
	}

	/**
	 * Whether a stored value counts as blank: empty, or whitespace only.
	 *
	 * Anything that is not text is kept as it is.
	 *
	 * @param mixed $value Stored value.
	 * @return bool
	 */
	private static function is_blank( mixed $value ): bool {
		if ( ! is_string( $value ) ) {
			return null === $value || false === $value;
		}

		$stripped = preg_replace( '/[\p{Z}\s\p{Cc}]+/u', '', $value );

		return '' === ( null === $stripped ? trim( $value ) : $stripped );
	}
}
