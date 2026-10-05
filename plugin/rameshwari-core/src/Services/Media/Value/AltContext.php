<?php
/**
 * Strings an alt text is built from.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Services\Media\Value;

/**
 * The owner name in both languages, an optional category and an optional role.
 *
 * The caller already holds these strings. Each one is flattened to plain text:
 * markup and control characters are removed and runs of whitespace collapse.
 */
final class AltContext {

	private const SPACE = '/[\p{Z}\s\p{Cc}]+/u';

	/**
	 * English owner name.
	 *
	 * @var string
	 */
	private readonly string $owner_en;

	/**
	 * Hindi owner name.
	 *
	 * @var string
	 */
	private readonly string $owner_hi;

	/**
	 * Category name.
	 *
	 * @var string
	 */
	private readonly string $category;

	/**
	 * Role, such as "front view".
	 *
	 * @var string
	 */
	private readonly string $role;

	/**
	 * Builds the context.
	 *
	 * @param string $owner_en English owner name.
	 * @param string $owner_hi Hindi owner name.
	 * @param string $category Category name.
	 * @param string $role     Role.
	 * @throws \InvalidArgumentException When neither owner name has any text.
	 */
	public function __construct( string $owner_en, string $owner_hi, string $category = '', string $role = '' ) {
		$this->owner_en = self::flatten( $owner_en );
		$this->owner_hi = self::flatten( $owner_hi );
		$this->category = self::flatten( $category );
		$this->role     = self::flatten( $role );

		if ( '' === $this->owner_en && '' === $this->owner_hi ) {
			throw new \InvalidArgumentException( 'An alt text needs an owner name in English or Hindi.' );
		}
	}

	/**
	 * English owner name.
	 *
	 * @return string
	 */
	public function owner_en(): string {
		return $this->owner_en;
	}

	/**
	 * Hindi owner name.
	 *
	 * @return string
	 */
	public function owner_hi(): string {
		return $this->owner_hi;
	}

	/**
	 * Category name.
	 *
	 * @return string
	 */
	public function category(): string {
		return $this->category;
	}

	/**
	 * Role.
	 *
	 * @return string
	 */
	public function role(): string {
		return $this->role;
	}

	/**
	 * Plain text with single spaces and no surrounding space.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function flatten( string $value ): string {
		$text = preg_replace( self::SPACE, ' ', wp_strip_all_tags( $value ) );

		return trim( null === $text ? '' : $text );
	}
}
