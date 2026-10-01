<?php
/**
 * Category picker tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Integration;

use Rameshwari\Core\Category\AssignmentGuard;
use Rameshwari\Core\Category\CategoryImporter;
use Rameshwari\Core\Category\CategoryPath;
use Rameshwari\Core\Category\CategoryPicker;
use Rameshwari\Core\Category\CategoryPickerBox;
use Rameshwari\Core\Category\CategoryRepository;
use Rameshwari\Core\Category\ResolverEngine;
use WP_UnitTestCase;

/**
 * The picker offers editorial leaves only, and the guard still decides.
 */
final class CategoryPickerTest extends WP_UnitTestCase {

	private const LEAF = 'jewellery/gold-jewellery/mahila-ke-abhushan/kalai-ke-abhushan/kada';

	/**
	 * Repository.
	 *
	 * @var CategoryRepository
	 */
	private CategoryRepository $repository;

	/**
	 * Picker.
	 *
	 * @var CategoryPicker
	 */
	private CategoryPicker $picker;

	/**
	 * Imports the master.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->repository = new CategoryRepository();
		$this->picker     = new CategoryPicker( $this->repository, new AssignmentGuard( $this->repository, new ResolverEngine( $this->repository ) ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local Stage 5 test fixture; no remote URL is involved.
		$data = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/plugin/rameshwari-core/data/categories.json' ), true );

		$this->assertIsArray( $data );
		( new CategoryImporter( $this->repository ) )->import( $data );
	}

	/**
	 * Term ID of a path.
	 *
	 * @param string $path Slug path.
	 * @return int
	 */
	private function id( string $path ): int {
		$id = $this->repository->find( CategoryPath::from_string( $path ) );

		$this->assertNotNull( $id, $path );

		return (int) $id;
	}

	/**
	 * The option for a path.
	 *
	 * @param string $path Slug path.
	 * @return array{path: string, label: string, trail: string, depth: int, selectable: bool}
	 */
	private function option( string $path ): array {
		foreach ( $this->picker->options() as $option ) {
			if ( $path === $option['path'] ) {
				return $option;
			}
		}

		$this->fail( "No option for {$path}" );
	}

	/**
	 * Roots, navigation terms and groups are not selectable; editorial leaves are.
	 */
	public function test_only_editorial_leaves_are_selectable(): void {
		foreach ( array( 'jewellery', 'metals', 'wedding', 'for', 'others', 'metals/gold', 'for/women', 'wedding/maang-tikka', 'wedding/mangalsutra', 'jewellery/gold-jewellery/mahila-ke-abhushan' ) as $path ) {
			$this->assertFalse( $this->option( $path )['selectable'], $path );
		}

		$this->assertTrue( $this->option( self::LEAF )['selectable'] );
	}

	/**
	 * The value is the canonical path, and the trail tells duplicate names apart.
	 */
	public function test_values_are_canonical_paths_and_trails_differ(): void {
		$gold   = $this->option( 'jewellery/gold-jewellery/mahila-ke-abhushan/neck-jewellery/mangalsutra' );
		$silver = $this->option( 'jewellery/silver-jewellery/mahila-ke-abhushan/neck-jewellery/mangalsutra' );

		$this->assertSame( $gold['label'], $silver['label'] );
		$this->assertNotSame( $gold['path'], $silver['path'] );
		$this->assertNotSame( $gold['trail'], $silver['trail'] );
	}

	/**
	 * Hidden categories are not offered.
	 */
	public function test_hidden_categories_are_not_offered(): void {
		update_term_meta( $this->id( 'jewellery/gold-jewellery' ), '_rj_visible', false );

		foreach ( $this->picker->options() as $option ) {
			$this->assertNotSame( 'jewellery/gold-jewellery', $option['path'] );
			$this->assertStringStartsNotWith( 'jewellery/gold-jewellery/', $option['path'] );
		}
	}

	/**
	 * A submitted root, navigation term, missing or invalid path is rejected by the server.
	 */
	public function test_server_rejects_what_the_picker_never_offers(): void {
		foreach ( array( 'jewellery', 'metals/gold', 'wedding/maang-tikka', 'others/no-such-category', 'Not A Path' ) as $path ) {
			$result = $this->picker->resolve( array( $path ) );

			$this->assertNotSame( array(), $result['violations'], $path );
		}

		$ok = $this->picker->resolve( array( self::LEAF ) );

		$this->assertSame( array(), $ok['violations'] );
		$this->assertSame( array( $this->id( self::LEAF ) ), $ok['ids'] );
	}

	/**
	 * A valid choice is assigned; a rejected one changes nothing.
	 */
	public function test_apply_assigns_valid_and_keeps_old_on_rejection(): void {
		$post_id = self::factory()->post->create( array( 'post_type' => 'rj_product' ) );

		$this->assertSame( array(), CategoryPickerBox::apply( $post_id, array( self::LEAF ) ) );
		$this->assertSame( array( $this->id( self::LEAF ) ), wp_get_object_terms( $post_id, 'rj_category', array( 'fields' => 'ids' ) ) );

		$this->assertNotSame( array(), CategoryPickerBox::apply( $post_id, array( self::LEAF, 'metals/gold' ) ) );
		$this->assertSame( array( $this->id( self::LEAF ) ), wp_get_object_terms( $post_id, 'rj_category', array( 'fields' => 'ids' ) ) );
	}

	/**
	 * The markup escapes names, checks assigned paths, and gives roots no checkbox.
	 */
	public function test_markup_is_escaped_and_roots_have_no_checkbox(): void {
		update_term_meta( $this->id( self::LEAF ), '_rj_name_hi', 'Kada & Bangle' );

		$html = CategoryPickerBox::render( $this->picker, array( self::LEAF ) );

		$this->assertStringContainsString( 'Kada &amp; Bangle', $html );
		$this->assertStringNotContainsString( 'Kada & Bangle', $html );
		$this->assertStringContainsString( 'value="' . self::LEAF . '" checked="checked"', $html );
		$this->assertStringNotContainsString( 'value="jewellery"', $html );
		$this->assertStringNotContainsString( 'value="metals/gold"', $html );
	}
}
