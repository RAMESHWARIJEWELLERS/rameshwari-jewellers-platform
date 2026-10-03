<?php
/**
 * Attachment reference sources.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Services\Media;

use Rameshwari\Core\Data\Meta;
use Rameshwari\Core\Data\Options;
use Rameshwari\Core\Services\Media\Value\ReferenceSource;

/**
 * The one list of places an attachment ID can be stored (blueprint §11.5).
 *
 * It is built from the registered Meta and Options contracts, never from a
 * hand-written key list, so a key added to either registry is picked up. It
 * defines WHAT can reference an attachment; ReferenceGuard decides WHETHER one
 * does. It reads no database.
 */
final class ReferenceSources {

	/**
	 * Meta rule names that hold one attachment ID.
	 *
	 * @var array<int,string>
	 */
	public const SINGLE_RULES = array( 'attachment', 'image', 'video' );

	/**
	 * Meta rule name that holds a list of attachment IDs.
	 *
	 * @var string
	 */
	public const LIST_RULE = 'attachments';

	/**
	 * Every source, in a stable order: featured image, post meta, term meta, options.
	 *
	 * @return array<int,ReferenceSource>
	 */
	public function all(): array {
		$sources = array( new ReferenceSource( ReferenceSource::FEATURED, '_thumbnail_id' ) );

		$post_meta = array();

		foreach ( Meta::post_meta() as $keys ) {
			$post_meta += $this->attachment_keys( $keys );
		}

		$sources = array_merge( $sources, $this->from_keys( ReferenceSource::POST_META, $post_meta ) );
		$sources = array_merge( $sources, $this->from_keys( ReferenceSource::TERM_META, $this->attachment_keys( Meta::term_meta() ) ) );

		$options = array();

		foreach ( Options::schemas() as $group => $schema ) {
			$this->walk( $schema, array(), $group, $options );
		}

		ksort( $options );

		foreach ( $options as $label => $parts ) {
			$sources[] = new ReferenceSource( ReferenceSource::OPTION, $parts[0], $parts[1] );
		}

		return $sources;
	}

	/**
	 * Attachment-typed keys of a meta registry: key => true when the key holds a list.
	 *
	 * @param array<string,array{0:string,1:mixed,2:bool}> $keys Registry rows.
	 * @return array<string,bool>
	 */
	public function attachment_keys( array $keys ): array {
		$found = array();

		foreach ( $keys as $key => $spec ) {
			$rule = explode( ':', $spec[0], 2 )[0];

			if ( in_array( $rule, self::SINGLE_RULES, true ) ) {
				$found[ $key ] = false;
			} elseif ( self::LIST_RULE === $rule ) {
				$found[ $key ] = true;
			}
		}

		return $found;
	}

	/**
	 * Sorted definitions for a key map.
	 *
	 * @param string             $kind Source kind.
	 * @param array<string,bool> $keys Key => is list.
	 * @return array<int,ReferenceSource>
	 */
	private function from_keys( string $kind, array $keys ): array {
		ksort( $keys );

		$sources = array();

		foreach ( $keys as $key => $is_list ) {
			$sources[] = new ReferenceSource( $kind, $key, '', $is_list );
		}

		return $sources;
	}

	/**
	 * Finds attachment post-reference fields in an option schema.
	 *
	 * @param array<mixed>                           $node  Schema node.
	 * @param array<int,string>                      $path  Path so far.
	 * @param string                                 $group Option group.
	 * @param array<string,array{0:string,1:string}> $found Found fields.
	 * @return void
	 */
	private function walk( array $node, array $path, string $group, array &$found ): void {
		foreach ( $node as $key => $child ) {
			if ( ! is_array( $child ) ) {
				continue;
			}

			if ( 'fields' === $key ) {
				$this->walk( $child, $path, $group, $found );
				continue;
			}

			$next = array_merge( $path, array( (string) $key ) );

			if ( 'post_ref' === ( $child['type'] ?? null ) && 'attachment' === ( $child['post_type'] ?? null ) ) {
				$found[ $group . '.' . implode( '.', $next ) ] = array( $group, implode( '.', $next ) );
				continue;
			}

			$this->walk( $child, $next, $group, $found );
		}
	}
}
