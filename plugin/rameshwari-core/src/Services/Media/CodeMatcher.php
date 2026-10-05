<?php
/**
 * Product code suggestions from file names.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Services\Media;

use Rameshwari\Core\Product\ProductCode;
use Rameshwari\Core\Product\ProductRepository;
use Rameshwari\Core\Services\Media\Value\MatchResult;

/**
 * Finds product codes in a file name and offers them as suggestions (blueprint §11.6).
 *
 * The name is split on anything that is not a letter, digit or hyphen. Each
 * piece is then read as hyphen-separated segments, so a code such as RJ-4001
 * is found inside "ring-front-RJ-4001-large". From each start the longest run
 * of segments that ProductCode accepts unchanged (apart from case) and that an
 * existing product holds is taken, and the scan continues after it. Ordinary
 * words never become suggestions because no product holds them. Zero or
 * several candidates mean the user must choose. This class only reads: it
 * never attaches an image or writes to a product. A trashed product still
 * holds its code, so its code can be suggested.
 */
final class CodeMatcher {

	/**
	 * Builds the matcher.
	 *
	 * @param ProductRepository $products Looks codes up.
	 */
	public function __construct( private readonly ProductRepository $products ) {
	}

	/**
	 * Suggests product codes found in a file name.
	 *
	 * @param string $filename File name or path.
	 * @return MatchResult Zero, one or several existing product codes.
	 */
	public function suggest( string $filename ): MatchResult {
		$stem   = pathinfo( basename( str_replace( '\\', '/', $filename ) ), PATHINFO_FILENAME );
		$pieces = preg_split( '/[^A-Za-z0-9-]+/', $stem, -1, PREG_SPLIT_NO_EMPTY );

		if ( false === $pieces ) {
			return MatchResult::none();
		}

		$seen  = array();
		$found = array();

		foreach ( $pieces as $piece ) {
			$segments = explode( '-', $piece );
			$count    = count( $segments );
			$start    = 0;

			while ( $start < $count ) {
				$hit = null;

				if ( '' !== $segments[ $start ] ) {
					for ( $end = $count - 1; $end >= $start; --$end ) {
						if ( '' === $segments[ $end ] ) {
							continue;
						}

						$value = $this->known_code( implode( '-', array_slice( $segments, $start, $end - $start + 1 ) ), $seen );

						if ( null !== $value ) {
							$found[] = $value;
							$hit     = $end;

							break;
						}
					}
				}

				$start = null === $hit ? $start + 1 : $hit + 1;
			}
		}

		return new MatchResult( $found );
	}

	/**
	 * The normalised code if the text is a clean code that a product holds, otherwise null.
	 *
	 * Text that normalisation would shorten or change beyond case is not a clean code.
	 *
	 * @param string             $text Text made of letters, digits and hyphens.
	 * @param array<string,bool> $seen Lookups already made, by normalised code.
	 * @return string|null
	 */
	private function known_code( string $text, array &$seen ): ?string {
		$code = ProductCode::from( $text );

		if ( null === $code || strlen( $code->value() ) !== strlen( $text ) ) {
			return null;
		}

		$value = $code->value();

		if ( ! isset( $seen[ $value ] ) ) {
			$seen[ $value ] = null !== $this->products->first_id_for_code( $value );
		}

		return $seen[ $value ] ? $value : null;
	}
}
