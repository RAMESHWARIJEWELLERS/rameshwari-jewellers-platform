<?php
/**
 * Reference source definition.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Services\Media\Value;

/**
 * One place an attachment ID can be stored: what kind of store, which key, and
 * whether it holds one ID or a list. It describes; it never scans.
 */
final class ReferenceSource {

	public const FEATURED  = 'featured';
	public const POST_META = 'post_meta';
	public const TERM_META = 'term_meta';
	public const OPTION    = 'option';

	private const KINDS = array( self::FEATURED, self::POST_META, self::TERM_META, self::OPTION );

	/**
	 * Builds a definition.
	 *
	 * @param string $kind    One of the four kinds.
	 * @param string $key     Meta key, or option group for an option.
	 * @param string $path    Dotted path inside an option value; empty otherwise.
	 * @param bool   $is_list True when the store holds a list of IDs.
	 * @throws \InvalidArgumentException When the kind or key is invalid.
	 */
	public function __construct( private readonly string $kind, private readonly string $key, private readonly string $path = '', private readonly bool $is_list = false ) {
		if ( ! in_array( $kind, self::KINDS, true ) || '' === $key ) {
			throw new \InvalidArgumentException( 'A reference source needs a known kind and a key.' );
		}
	}

	/**
	 * The kind.
	 *
	 * @return string
	 */
	public function kind(): string {
		return $this->kind;
	}

	/**
	 * Meta key or option group.
	 *
	 * @return string
	 */
	public function key(): string {
		return $this->key;
	}

	/**
	 * Dotted path inside an option value.
	 *
	 * @return string
	 */
	public function path(): string {
		return $this->path;
	}

	/**
	 * Whether the store holds a list of IDs.
	 *
	 * @return bool
	 */
	public function is_list(): bool {
		return $this->is_list;
	}

	/**
	 * Field label for reports: the key, or group.path for an option.
	 *
	 * @return string
	 */
	public function field(): string {
		return '' === $this->path ? $this->key : $this->key . '.' . $this->path;
	}
}
