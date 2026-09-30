<?php
/**
 * String helper tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Unit;

use Rameshwari\Core\Support\Str;
use WP_UnitTestCase;

/**
 * Transliteration, slugs and truncation.
 */
final class StrTest extends WP_UnitTestCase {

	/**
	 * Known names transliterate as expected.
	 */
	public function test_transliterates_devanagari(): void {
		$expected = array(
			'बोरला'     => 'borla',
			'झुमका'     => 'jhumka',
			'पायल'      => 'payal',
			'नथ'        => 'nath',
			'टिका'      => 'tika',
			'हालरो'     => 'halro',
			'रानीहार'   => 'ranihar',
			'शीशफूल'    => 'shishphul',
			'मंगलसूत्र' => 'mangalsutra',
		);

		foreach ( $expected as $hindi => $latin ) {
			$this->assertSame( $latin, Str::transliterate( $hindi ), $hindi );
		}
	}

	/**
	 * Nukta letters give the same result precomposed or decomposed.
	 */
	public function test_nukta_forms(): void {
		$this->assertSame( 'kada', Str::transliterate( "\u{0915}\u{0921}\u{093C}\u{093E}" ) );
		$this->assertSame( 'kada', Str::transliterate( "\u{0915}\u{095C}\u{093E}" ) );
	}

	/**
	 * Multi-word names become hyphenated slugs.
	 */
	public function test_slug_multiword(): void {
		$this->assertSame( 'nak-ki-bali', Str::slug( 'नाक की बाली' ) );
	}

	/**
	 * Latin text and digits pass through a slug.
	 */
	public function test_slug_latin(): void {
		$this->assertSame( 'gold-jewellery-22k', Str::slug( 'Gold Jewellery 22K' ) );
	}

	/**
	 * Nothing sluggable gives an empty slug.
	 */
	public function test_slug_empty(): void {
		$this->assertSame( '', Str::slug( '' ) );
		$this->assertSame( '', Str::slug( '!!!' ) );
	}

	/**
	 * Every one of the 306 Category Master terms gives a clean, stable slug.
	 *
	 * Names repeat under different parents on purpose, so the fixture keys each
	 * occurrence by its full path: 306 distinct paths, far fewer distinct names.
	 */
	public function test_all_category_terms_slug(): void {
		$terms = require __DIR__ . '/../fixtures/category-names.php';
		$paths = array_column( $terms, 'path' );
		$names = array_column( $terms, 'name' );

		$this->assertCount( 306, $terms );
		$this->assertCount( 306, array_unique( $paths ) );
		$this->assertLessThan( 306, count( array_unique( $names ) ) );

		foreach ( $terms as $term ) {
			$slug = Str::slug( $term['name'] );

			$this->assertMatchesRegularExpression( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug, $term['path'] );
			$this->assertSame( $slug, Str::slug( $term['name'] ), $term['path'] );
		}
	}

	/**
	 * Truncation prefers the last word boundary.
	 */
	public function test_truncate_word_boundary(): void {
		$this->assertSame( 'हथफूल…', Str::truncate( 'हथफूल कड़ा बाली', 8 ) );
		$this->assertSame( 'Gold Jewellery…', Str::truncate( 'Gold Jewellery Set', 15 ) );
	}

	/**
	 * A hard cut never separates a letter from its marks.
	 */
	public function test_truncate_never_splits_marks(): void {
		$word = "\u{0915}\u{0921}\u{093C}\u{093E}\u{0915}\u{0921}\u{093C}\u{093E}";

		$this->assertSame( "\u{0915}", Str::truncate( $word, 3, '' ) );
	}

	/**
	 * Text that fits is returned unchanged.
	 */
	public function test_truncate_short_text_unchanged(): void {
		$this->assertSame( 'Gold', Str::truncate( 'Gold', 10 ) );
	}
}
