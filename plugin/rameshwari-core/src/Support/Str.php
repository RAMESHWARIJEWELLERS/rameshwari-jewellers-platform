<?php
/**
 * String helpers: transliteration, slugs and safe truncation.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Support;

/**
 * Devanagari-to-Latin transliteration for slugs and search, and truncation
 * that never separates a character from its combining marks. No network,
 * no external service and no mbstring: characters are split with PCRE.
 *
 * Transliteration is deterministic and ASCII-only. It drops the inherent
 * vowel at the end of a word, and between two vowel-bearing syllables
 * (बोरला becomes borla), but keeps it after a conjunct (मंगलसूत्र becomes
 * mangalsutra). It is built for slugs, not scholarly romanisation.
 *
 * @phpstan-type Unit array{cons: string, vowel: string, inherent: bool, cluster: bool, coda: string}
 */
final class Str {

	private const VIRAMA = "\u{094D}";

	private const NUKTA = "\u{093C}";

	private const CONSONANTS = 'क:k|ख:kh|ग:g|घ:gh|ङ:n|च:ch|छ:chh|ज:j|झ:jh|ञ:n|ट:t|ठ:th|ड:d|ढ:dh|ण:n|त:t|थ:th|द:d|ध:dh|न:n|प:p|फ:ph|ब:b|भ:bh|म:m|य:y|र:r|ल:l|ळ:l|व:v|श:sh|ष:sh|स:s|ह:h'
		. "|\u{0958}:q|\u{0959}:kh|\u{095A}:g|\u{095B}:z|\u{095C}:d|\u{095D}:dh|\u{095E}:f|\u{095F}:y";

	private const NUKTA_FORMS = 'क:q|ख:kh|ग:g|ज:z|ड:d|ढ:dh|फ:f|य:y';

	private const VOWEL_SIGNS = "\u{093E}:a|\u{093F}:i|\u{0940}:i|\u{0941}:u|\u{0942}:u|\u{0943}:ri|\u{0945}:e|\u{0946}:e|\u{0947}:e|\u{0948}:ai|\u{0949}:o|\u{094A}:o|\u{094B}:o|\u{094C}:au";

	private const VOWELS = "\u{0905}:a|\u{0906}:a|\u{0907}:i|\u{0908}:i|\u{0909}:u|\u{090A}:u|\u{090B}:ri|\u{090D}:e|\u{090F}:e|\u{0910}:ai|\u{0911}:o|\u{0913}:o|\u{0914}:au|\u{0950}:om";

	private const DIGITS = "\u{0966}:0|\u{0967}:1|\u{0968}:2|\u{0969}:3|\u{096A}:4|\u{096B}:5|\u{096C}:6|\u{096D}:7|\u{096E}:8|\u{096F}:9";

	// Anusvara becomes n or m depending on the next consonant, so it is marked M until output.
	private const CODAS = "\u{0902}:M|\u{0901}:n|\u{0903}:h";

	/**
	 * Parsed maps, keyed by their source string.
	 *
	 * @var array<string, array<string, string>>
	 */
	private static array $maps = array();

	/**
	 * Replaces every Devanagari run with its Latin form. Other text is kept.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function transliterate( string $text ): string {
		$result = preg_replace_callback(
			'/[\x{0900}-\x{097F}]+/u',
			static fn( array $matches ): string => self::word( (string) $matches[0] ),
			$text
		);

		return null === $result ? '' : $result;
	}

	/**
	 * Lowercase ASCII slug: letters and digits joined by single hyphens.
	 *
	 * @param string $text Text in Devanagari, Latin, or both.
	 * @return string Empty when nothing sluggable remains.
	 */
	public static function slug( string $text ): string {
		$latin = strtolower( remove_accents( self::transliterate( $text ) ) );
		$slug  = preg_replace( '/[^a-z0-9]+/', '-', $latin );

		return null === $slug ? '' : trim( $slug, '-' );
	}

	/**
	 * Shortens text to at most $max characters, including the ellipsis.
	 *
	 * Cuts at the last word boundary when there is one. Otherwise it cuts
	 * between characters, never between a character and its combining
	 * marks, and never directly after a virama.
	 *
	 * @param string $text     Text.
	 * @param int    $max      Maximum characters, ellipsis included.
	 * @param string $ellipsis Appended when the text is shortened.
	 * @return string
	 */
	public static function truncate( string $text, int $max, string $ellipsis = '…' ): string {
		if ( $max < 1 ) {
			return '';
		}

		$chars = self::chars( $text );

		if ( count( $chars ) <= $max ) {
			return $text;
		}

		$tail = self::chars( $ellipsis );
		$room = $max - count( $tail );

		if ( $room < 1 ) {
			$tail = array();
			$room = $max;
		}

		$length = $room;

		if ( ! self::is_space( $chars[ $length ] ) ) {
			$length = self::cut_point( $chars, $length );
		}

		return rtrim( implode( '', array_slice( $chars, 0, $length ) ) ) . implode( '', $tail );
	}

	/**
	 * Transliterates one run of Devanagari.
	 *
	 * @param string $word Devanagari characters.
	 * @return string
	 */
	private static function word( string $word ): string {
		$chars  = self::chars( $word );
		$count  = count( $chars );
		$vowels = self::map( self::VOWELS );
		$digits = self::map( self::DIGITS );
		$signs  = self::map( self::VOWEL_SIGNS );
		$units  = array();
		$index  = 0;

		while ( $index < $count ) {
			$char      = $chars[ $index ];
			$consonant = self::consonant( $chars, $index );

			if ( null !== $consonant ) {
				$latin = $consonant[0];
				$raw   = $consonant[2];
				$index = $consonant[1];
				$size  = 1;

				while ( self::VIRAMA === ( $chars[ $index ] ?? '' ) ) {
					$joined = self::consonant( $chars, $index + 1 );

					if ( null === $joined ) {
						break;
					}

					$latin .= $joined[0];

					$raw .= $joined[2];

					$index = $joined[1];

					++$size;
				}

				if ( 'जञ' === $raw ) {
					$latin = 'gy';
				}

				$vowel    = 'a';
				$inherent = true;
				$next     = $chars[ $index ] ?? '';

				if ( self::VIRAMA === $next ) {
					$vowel    = '';
					$inherent = false;
					++$index;
				} elseif ( isset( $signs[ $next ] ) ) {
					$vowel    = $signs[ $next ];
					$inherent = false;
					++$index;
				}

				$coda  = self::coda( $chars, $index );
				$index = $coda[1];

				$units[] = array(
					'cons'     => $latin,
					'vowel'    => $vowel,
					'inherent' => $inherent,
					'cluster'  => $size > 1,
					'coda'     => $coda[0],
				);
				continue;
			}

			if ( isset( $vowels[ $char ] ) ) {
				$coda  = self::coda( $chars, $index + 1 );
				$index = $coda[1];

				$units[] = array(
					'cons'     => '',
					'vowel'    => $vowels[ $char ],
					'inherent' => false,
					'cluster'  => false,
					'coda'     => $coda[0],
				);
				continue;
			}

			if ( isset( $digits[ $char ] ) ) {
				$units[] = array(
					'cons'     => $digits[ $char ],
					'vowel'    => '',
					'inherent' => false,
					'cluster'  => false,
					'coda'     => '',
				);
			}

			++$index;
		}

		return self::assemble( self::drop_schwa( $units ) );
	}

	/**
	 * Drops the inherent vowel word-finally and between two vowel-bearing
	 * syllables, scanning right to left so two neighbours are never both dropped.
	 *
	 * @param array<int, Unit> $units Syllable units.
	 * @return array<int, Unit>
	 */
	private static function drop_schwa( array $units ): array {
		$total = count( $units );

		if ( $total > 1 && self::deletable( $units[ $total - 1 ] ) ) {
			$units[ $total - 1 ]['vowel'] = '';
		}

		for ( $position = $total - 2; $position >= 1; --$position ) {
			if ( self::deletable( $units[ $position ] ) && '' !== $units[ $position - 1 ]['vowel'] && '' !== $units[ $position + 1 ]['vowel'] ) {
				$units[ $position ]['vowel'] = '';
			}
		}

		return $units;
	}

	/**
	 * Joins units into Latin text, resolving anusvara to n or m.
	 *
	 * @param array<int, Unit> $units Syllable units.
	 * @return string
	 */
	private static function assemble( array $units ): string {
		$latin = '';

		foreach ( $units as $position => $unit ) {
			$next  = $units[ $position + 1 ]['cons'] ?? '';
			$nasal = ( '' !== $next && in_array( $next[0], array( 'p', 'b', 'm' ), true ) ) ? 'm' : 'n';

			$latin .= $unit['cons'] . $unit['vowel'] . str_replace( 'M', $nasal, $unit['coda'] );
		}

		return $latin;
	}

	/**
	 * Whether a unit's vowel is an inherent a that may be dropped.
	 *
	 * @param array{cons:string, vowel:string, coda:string, inherent:bool, cluster:bool} $unit Syllable unit.
	 * @return bool
	 */
	private static function deletable( array $unit ): bool {
		return $unit['inherent'] && ! $unit['cluster'] && '' === $unit['coda'] && 'a' === $unit['vowel'];
	}

	/**
	 * Reads a consonant, with an optional nukta, at an index.
	 *
	 * @param array<int, string> $chars Characters.
	 * @param int                $index Position.
	 * @return array{0: string, 1: int, 2: string}|null Latin, next index, base character.
	 */
	private static function consonant( array $chars, int $index ): ?array {
		$char = $chars[ $index ] ?? '';
		$map  = self::map( self::CONSONANTS );

		if ( ! isset( $map[ $char ] ) ) {
			return null;
		}

		if ( self::NUKTA === ( $chars[ $index + 1 ] ?? '' ) ) {
			$nukta = self::map( self::NUKTA_FORMS );

			return array( $nukta[ $char ] ?? $map[ $char ], $index + 2, $char );
		}

		return array( $map[ $char ], $index + 1, $char );
	}

	/**
	 * Reads any nasal or visarga signs after a syllable.
	 *
	 * @param array<int, string> $chars Characters.
	 * @param int                $index Position.
	 * @return array{0: string, 1: int} Coda text, next index.
	 */
	private static function coda( array $chars, int $index ): array {
		$map  = self::map( self::CODAS );
		$coda = '';

		while ( isset( $chars[ $index ], $map[ $chars[ $index ] ] ) ) {
			$coda .= $map[ $chars[ $index ] ];
			++$index;
		}

		return array( $coda, $index );
	}

	/**
	 * Where to cut a word that does not fit.
	 *
	 * @param array<int, string> $chars  Characters.
	 * @param int                $length Characters that fit.
	 * @return int
	 */
	private static function cut_point( array $chars, int $length ): int {
		for ( $position = $length - 1; $position > 0; --$position ) {
			if ( self::is_space( $chars[ $position ] ) ) {
				return $position;
			}
		}

		while ( $length > 0 && ( self::is_mark( $chars[ $length ] ) || self::VIRAMA === $chars[ $length - 1 ] ) ) {
			--$length;
		}

		return $length;
	}

	/**
	 * Whether a character is whitespace.
	 *
	 * @param string $char Character.
	 * @return bool
	 */
	private static function is_space( string $char ): bool {
		return 1 === preg_match( '/^\s$/u', $char );
	}

	/**
	 * Whether a character is a combining mark.
	 *
	 * @param string $char Character.
	 * @return bool
	 */
	private static function is_mark( string $char ): bool {
		return 1 === preg_match( '/^\p{M}$/u', $char );
	}

	/**
	 * Splits text into Unicode characters.
	 *
	 * @param string $text Text.
	 * @return array<int, string>
	 */
	private static function chars( string $text ): array {
		$chars = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );

		return false === $chars ? array() : $chars;
	}

	/**
	 * Parses a "from:to|from:to" map once and caches it.
	 *
	 * @param string $source Map source.
	 * @return array<string, string>
	 */
	private static function map( string $source ): array {
		if ( ! isset( self::$maps[ $source ] ) ) {
			$parsed = array();

			foreach ( explode( '|', $source ) as $pair ) {
				$parts = explode( ':', $pair, 2 );

				$parsed[ $parts[0] ] = $parts[1] ?? '';
			}

			self::$maps[ $source ] = $parsed;
		}

		return self::$maps[ $source ];
	}
}
