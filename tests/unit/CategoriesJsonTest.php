<?php
/**
 * Contract tests for the categories.json import asset.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Unit;

use Rameshwari\Core\Support\Str;
use WP_UnitTestCase;

/**
 * The asset must match the locked Category Master: 306 terms, 5 roots, full-path
 * identity, duplicate visible names kept, and 22 resolver mappings.
 */
final class CategoriesJsonTest extends WP_UnitTestCase {

	private const FIELDS = array( 'path', 'name_en', 'name_hi', 'name_alt', 'slug', 'order', 'visible', 'featured', 'image', 'seo_title', 'seo_desc', 'seo_noindex' );

	/**
	 * Decoded records.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function records(): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local Stage 5 test fixture; no remote URL is involved.
		$json = file_get_contents( dirname( __DIR__, 2 ) . '/plugin/rameshwari-core/data/categories.json' );
		$data = json_decode( (string) $json, true );

		$this->assertIsArray( $data );

		return $data;
	}

	/**
	 * Exactly 306 records, each with every required field.
	 */
	public function test_306_records_with_required_fields(): void {
		$records = $this->records();

		$this->assertCount( 306, $records );

		foreach ( $records as $record ) {
			foreach ( self::FIELDS as $field ) {
				$this->assertArrayHasKey( $field, $record, (string) $record['path'] );
			}
		}
	}

	/**
	 * Five roots in the locked order.
	 */
	public function test_five_roots_in_locked_order(): void {
		$roots = array();

		foreach ( $this->records() as $record ) {
			if ( ! str_contains( $record['path'], '/' ) ) {
				$roots[] = $record['path'];
			}
		}

		$this->assertSame( array( 'jewellery', 'metals', 'wedding', 'for', 'others' ), $roots );
	}

	/**
	 * Full paths are unique and every parent exists.
	 */
	public function test_unique_paths_and_parent_integrity(): void {
		$paths = array_column( $this->records(), 'path' );

		$this->assertCount( 306, array_unique( $paths ) );

		foreach ( $paths as $path ) {
			$cut = strrpos( $path, '/' );

			if ( false !== $cut ) {
				$this->assertContains( substr( $path, 0, $cut ), $paths, $path );
			}
		}
	}

	/**
	 * The slug is the last path segment, Latin-only, and equals Str::slug of the name.
	 */
	public function test_slugs_are_latin_and_match_str_slug(): void {
		foreach ( $this->records() as $record ) {
			$source = '' !== $record['name_hi'] ? $record['name_hi'] : $record['name_en'];

			$this->assertMatchesRegularExpression( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $record['slug'], $record['path'] );
			$this->assertSame( Str::slug( $source ), $record['slug'], $record['path'] );
			$this->assertSame( $record['slug'], (string) substr( (string) strrchr( '/' . $record['path'], '/' ), 1 ), $record['path'] );
		}
	}

	/**
	 * Visible names repeat under different parents and stay separate records.
	 */
	public function test_duplicate_visible_names_are_preserved(): void {
		$names = array();

		foreach ( $this->records() as $record ) {
			$names[] = '' !== $record['name_hi'] ? $record['name_hi'] : $record['name_en'];
		}

		$this->assertLessThan( 306, count( array_unique( $names ) ) );
	}

	/**
	 * Branch sizes match the Category Master: 74, 98 and 98 including the branch term.
	 */
	public function test_metal_branch_sizes(): void {
		$counts = array();

		foreach ( $this->records() as $record ) {
			$parts = explode( '/', $record['path'] );

			if ( 'jewellery' === $parts[0] && isset( $parts[1] ) ) {
				$counts[ $parts[1] ] = ( $counts[ $parts[1] ] ?? 0 ) + 1;
			}
		}

		$this->assertSame( 74, $counts['gold-jewellery'] );
		$this->assertSame( 98, $counts['silver-jewellery'] );
		$this->assertSame( 98, $counts['black-polish-silver'] );
	}

	/**
	 * Exactly 22 resolver mappings: 19 Shape A and 3 Shape B.
	 */
	public function test_22_resolver_mappings(): void {
		$shape_a = 0;
		$shape_b = 0;

		foreach ( $this->records() as $record ) {
			if ( empty( $record['resolver'] ) ) {
				continue;
			}

			if ( isset( $record['resolver']['tax'] ) ) {
				$this->assertSame( array( 'tax', 'slug' ), array_keys( $record['resolver'] ), $record['path'] );
				++$shape_a;
			} else {
				$this->assertSame( array( 'terms', 'paths' ), array_keys( $record['resolver'] ), $record['path'] );
				$this->assertSame( array(), $record['resolver']['terms'], 'IDs are derived at import: ' . $record['path'] );
				++$shape_b;
			}
		}

		$this->assertSame( 19, $shape_a );
		$this->assertSame( 3, $shape_b );
	}

	/**
	 * Shape A targets: seven metals, eight audiences, four occasions.
	 */
	public function test_shape_a_targets(): void {
		$by_path = array_column( $this->records(), null, 'path' );

		$this->assertSame( array( 'rj_metal', 'rose-gold' ), array_values( $by_path['metals/rose-gold']['resolver'] ) );
		$this->assertSame( array( 'rj_audience', 'women' ), array_values( $by_path['for/women']['resolver'] ) );
		$this->assertSame( array( 'rj_occasion', 'bridal' ), array_values( $by_path['wedding/bridal-set']['resolver'] ) );
		$this->assertSame( array( 'rj_occasion', 'engagement' ), array_values( $by_path['wedding/engagement-rings']['resolver'] ) );
		$this->assertSame( array( 'rj_occasion', 'anniversary' ), array_values( $by_path['wedding/anniversary']['resolver'] ) );
		$this->assertSame( array( 'rj_occasion', 'couple' ), array_values( $by_path['wedding/couple-rings']['resolver'] ) );
	}

	/**
	 * Mangalsutra, Bridal Nath and Maang Tikka each name three leaves, one per metal branch.
	 */
	public function test_shape_b_targets(): void {
		$by_path  = array_column( $this->records(), null, 'path' );
		$branches = array( 'gold-jewellery', 'silver-jewellery', 'black-polish-silver' );
		$expected = array(
			'wedding/mangalsutra' => 'neck-jewellery/mangalsutra',
			'wedding/bridal-nath' => 'nose-jewellery/nath',
			'wedding/maang-tikka' => 'sir-ke-abhushan/tika',
		);

		foreach ( $expected as $term => $tail ) {
			$paths = $by_path[ $term ]['resolver']['paths'];

			$this->assertCount( 3, $paths, $term );

			foreach ( $branches as $index => $branch ) {
				$this->assertSame( "jewellery/{$branch}/mahila-ke-abhushan/{$tail}", $paths[ $index ], $term );
				$this->assertArrayHasKey( $paths[ $index ], $by_path, $term );
			}
		}
	}

	/**
	 * Maang Tikka never resolves under Neck Jewellery.
	 */
	public function test_maang_tikka_is_not_under_neck(): void {
		$by_path = array_column( $this->records(), null, 'path' );

		foreach ( $by_path['wedding/maang-tikka']['resolver']['paths'] as $path ) {
			$this->assertStringNotContainsString( 'neck-jewellery', $path );
			$this->assertStringContainsString( '/sir-ke-abhushan/', $path );
		}
	}
}
