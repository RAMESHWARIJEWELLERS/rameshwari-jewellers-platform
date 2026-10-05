<?php
/**
 * Code matcher tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Integration\Media;

use Rameshwari\Core\Capabilities;
use Rameshwari\Core\Product\ProductRepository;
use Rameshwari\Core\Product\ProductSystemScope;
use Rameshwari\Core\Services\Media\CodeMatcher;

/**
 * Suggestions come from real product codes and nothing is ever written.
 */
final class CodeMatcherTest extends \WP_UnitTestCase {

	/**
	 * Registers the plugin's types for each test.
	 */
	public function set_up(): void {
		parent::set_up();
		do_action( 'init' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Exercises the plugin's normal init registration.
	}

	/**
	 * Creates a product holding a code.
	 *
	 * @param string $code   Code.
	 * @param string $status Post status.
	 * @return int
	 */
	private function product( string $code, string $status = 'draft' ): int {
		$id = (int) self::factory()->post->create(
			array(
				'post_type'   => 'rj_product',
				'post_status' => $status,
			)
		);

		ProductSystemScope::run( static fn() => update_post_meta( $id, '_rj_code', $code ) );

		return $id;
	}

	/**
	 * Builds the matcher.
	 *
	 * @return CodeMatcher
	 */
	private function matcher(): CodeMatcher {
		return new CodeMatcher( new ProductRepository() );
	}

	/**
	 * A name with no known code gives no candidate.
	 */
	public function test_zero_candidates(): void {
		$this->product( 'ZC-1' );

		$result = $this->matcher()->suggest( 'holiday-photo_front.jpg' );

		$this->assertTrue( $result->is_empty() );
		$this->assertNull( $result->suggestion() );
	}

	/**
	 * One known code gives exactly one suggestion.
	 */
	public function test_exactly_one_candidate(): void {
		$this->product( 'RJ-1001' );

		$result = $this->matcher()->suggest( 'RJ-1001_front.jpg' );

		$this->assertSame( array( 'RJ-1001' ), $result->candidates() );
		$this->assertSame( 'RJ-1001', $result->suggestion() );
	}

	/**
	 * Several known codes give several suggestions and no single one.
	 */
	public function test_multiple_candidates(): void {
		$this->product( 'RJ-2001' );
		$this->product( 'RJ-2002' );

		$result = $this->matcher()->suggest( 'RJ-2001 and RJ-2002 pair.jpg' );

		$this->assertSame( array( 'RJ-2001', 'RJ-2002' ), $result->candidates() );
		$this->assertTrue( $result->is_ambiguous() );
		$this->assertNull( $result->suggestion() );
	}

	/**
	 * ProductCode's normalisation applies: case does not matter, and a repeat is one candidate.
	 */
	public function test_normalisation_is_respected(): void {
		$this->product( 'RJ-3001' );

		$this->assertSame( array( 'RJ-3001' ), $this->matcher()->suggest( 'rj-3001_Rj-3001.PNG' )->candidates() );
	}

	/**
	 * Ordinary words are not candidates even though they are valid code characters.
	 */
	public function test_words_without_a_product_are_ignored(): void {
		$this->product( 'RJ-4001' );

		$this->assertSame( array( 'RJ-4001' ), $this->matcher()->suggest( 'ring-front-RJ-4001-large.jpg' )->candidates() );
		$this->assertTrue( $this->matcher()->suggest( 'ring-front-large.jpg' )->is_empty() );
	}

	/**
	 * Folders and the extension are not searched.
	 */
	public function test_directory_and_extension_are_ignored(): void {
		$this->product( 'RJ-5001' );
		$this->product( 'JPG' );

		$this->assertTrue( $this->matcher()->suggest( 'C:\\RJ-5001\\x\\plain.png' )->is_empty() );
		$this->assertTrue( $this->matcher()->suggest( 'photos/RJ-5001/plain.jpg' )->is_empty() );
		$this->assertSame( array( 'RJ-5001' ), $this->matcher()->suggest( 'photos/other/RJ-5001.JPG' )->candidates() );
	}

	/**
	 * A piece longer than the code limit is skipped rather than cut down to a stored code.
	 */
	public function test_overlong_piece_is_not_truncated_into_a_match(): void {
		$long = str_repeat( 'A', 64 );

		$this->product( $long );

		$this->assertTrue( $this->matcher()->suggest( $long . 'B.jpg' )->is_empty() );
		$this->assertSame( array( $long ), $this->matcher()->suggest( $long . '.jpg' )->candidates() );
	}

	/**
	 * Blank names and names with no usable piece give no candidate.
	 */
	public function test_degenerate_names(): void {
		$this->product( 'RJ-6001' );

		foreach ( array( '', '.jpg', '---.png', '___', "\u{0939}\u{093F}.jpg" ) as $name ) {
			$this->assertTrue( $this->matcher()->suggest( $name )->is_empty(), $name );
		}
	}

	/**
	 * A trashed product still holds its code, so the code is still suggested.
	 */
	public function test_trashed_product_code_is_still_suggested(): void {
		$id = $this->product( 'RJ-7001', 'publish' );

		wp_trash_post( $id );

		$this->assertSame( array( 'RJ-7001' ), $this->matcher()->suggest( 'RJ-7001.jpg' )->candidates() );
	}

	/**
	 * Suggesting writes nothing: no post, no meta, no featured image, no gallery.
	 */
	public function test_suggestion_changes_nothing(): void {
		$id      = $this->product( 'RJ-8001' );
		$meta    = get_post_meta( $id );
		$thumb   = get_post_meta( $id, '_thumbnail_id', true );
		$gallery = get_post_meta( $id, '_rj_gallery', true );

		$post_query = array(
			'post_type'   => 'any',
			'post_status' => 'any',
			'numberposts' => -1,
			'fields'      => 'ids',
		);

		$image_query = array(
			'post_type'   => 'attachment',
			'post_status' => 'any',
			'numberposts' => -1,
			'fields'      => 'ids',
		);

		$posts  = count( get_posts( $post_query ) );
		$images = count( get_posts( $image_query ) );

		$this->assertSame( array( 'RJ-8001' ), $this->matcher()->suggest( 'RJ-8001.jpg' )->candidates() );

		$this->assertSame( $meta, get_post_meta( $id ) );
		$this->assertSame( $thumb, get_post_meta( $id, '_thumbnail_id', true ) );
		$this->assertSame( $gallery, get_post_meta( $id, '_rj_gallery', true ) );
		$this->assertSame( $posts, count( get_posts( $post_query ) ) );
		$this->assertSame( $images, count( get_posts( $image_query ) ) );
	}

	/**
	 * The matcher has no way to assign: it exposes only suggest().
	 */
	public function test_matcher_offers_no_assignment_method(): void {
		$names = array_map( static fn ( \ReflectionMethod $m ): string => $m->getName(), ( new \ReflectionClass( CodeMatcher::class ) )->getMethods( \ReflectionMethod::IS_PUBLIC ) );

		sort( $names );

		$this->assertSame( array( '__construct', 'suggest' ), $names );
	}

	/**
	 * A code inside descriptive hyphenated text is found; the text around it is not.
	 */
	public function test_hyphenated_code_is_found_inside_descriptive_text(): void {
		$this->product( 'RJ-9001' );

		$this->assertSame( array( 'RJ-9001' ), $this->matcher()->suggest( 'ring-front-RJ-9001-large.jpg' )->candidates() );
		$this->assertSame( array( 'RJ-9001' ), $this->matcher()->suggest( 'RJ-9001-front.jpg' )->candidates() );
		$this->assertSame( array( 'RJ-9001' ), $this->matcher()->suggest( 'front-rj-9001.jpg' )->candidates() );
	}

	/**
	 * The longest run wins, so a shorter code inside it is not suggested as well.
	 */
	public function test_longest_run_is_taken_not_its_parts(): void {
		$this->product( 'RJ-9101' );
		$this->product( '9101' );

		$this->assertSame( array( 'RJ-9101' ), $this->matcher()->suggest( 'front-RJ-9101.jpg' )->candidates() );
		$this->assertSame( array( '9101' ), $this->matcher()->suggest( 'front-9101.jpg' )->candidates() );
	}

	/**
	 * Two embedded codes keep their order in the name.
	 */
	public function test_embedded_codes_keep_name_order(): void {
		$this->product( 'RJ-9201' );
		$this->product( 'RJ-9202' );

		$this->assertSame( array( 'RJ-9202', 'RJ-9201' ), $this->matcher()->suggest( 'set-RJ-9202-with-RJ-9201-box.jpg' )->candidates() );
	}

	/**
	 * A double hyphen is part of the text and is not collapsed into a single one.
	 */
	public function test_double_hyphen_is_not_collapsed(): void {
		$this->product( 'RJ-9301' );

		$this->assertTrue( $this->matcher()->suggest( 'RJ--9301.jpg' )->is_empty() );
	}
}
