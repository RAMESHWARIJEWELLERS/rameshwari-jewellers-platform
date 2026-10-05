<?php
/**
 * Alt text apply tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Integration\Media;

use Rameshwari\Core\Services\Media\AltText;
use Rameshwari\Core\Services\Media\Value\AltContext;

/**
 * The native alt field is filled only when blank and nothing else is stored.
 */
final class AltTextApplyTest extends \WP_UnitTestCase {

	/**
	 * Creates an attachment.
	 *
	 * @return int
	 */
	private function attachment(): int {
		return (int) self::factory()->attachment->create(
			array(
				'post_mime_type' => 'image/png',
				'post_title'     => 'Fixture',
			)
		);
	}

	/**
	 * A context used in most tests.
	 *
	 * @return AltContext
	 */
	private function context(): AltContext {
		return new AltContext( 'Gold Ring', 'अंगूठी', 'Rings', 'front view' );
	}

	/**
	 * A blank field is filled with the generated text.
	 */
	public function test_blank_alt_is_generated_and_stored(): void {
		$id = $this->attachment();

		$this->assertTrue( ( new AltText() )->apply( $id, $this->context() ) );
		$this->assertSame( 'Gold Ring (अंगूठी), front view, Rings', get_post_meta( $id, '_wp_attachment_image_alt', true ) );
	}

	/**
	 * Whitespace-only values count as blank, including non-breaking space.
	 */
	public function test_whitespace_only_alt_is_treated_as_blank(): void {
		foreach ( array( '   ', "\t\n", "\u{00A0}\u{00A0}" ) as $blank ) {
			$id = $this->attachment();

			update_post_meta( $id, '_wp_attachment_image_alt', $blank );

			$this->assertTrue( ( new AltText() )->apply( $id, $this->context() ), bin2hex( $blank ) );
			$this->assertSame( 'Gold Ring (अंगूठी), front view, Rings', get_post_meta( $id, '_wp_attachment_image_alt', true ) );
		}
	}

	/**
	 * A manual value is kept exactly as written and nothing is written.
	 */
	public function test_manual_alt_is_preserved(): void {
		$id = $this->attachment();

		update_post_meta( $id, '_wp_attachment_image_alt', '  Hand-written alt  ' );

		$this->assertFalse( ( new AltText() )->apply( $id, $this->context() ) );
		$this->assertSame( '  Hand-written alt  ', get_post_meta( $id, '_wp_attachment_image_alt', true ) );
	}

	/**
	 * Applying twice writes once.
	 */
	public function test_second_apply_does_not_overwrite(): void {
		$id  = $this->attachment();
		$alt = new AltText();

		$this->assertTrue( $alt->apply( $id, $this->context() ) );
		$this->assertFalse( $alt->apply( $id, new AltContext( 'Other', '' ) ) );
		$this->assertSame( 'Gold Ring (अंगूठी), front view, Rings', get_post_meta( $id, '_wp_attachment_image_alt', true ) );
	}

	/**
	 * Unknown IDs and posts that are not attachments are refused.
	 */
	public function test_non_attachments_are_refused(): void {
		$post = (int) self::factory()->post->create();

		$this->assertFalse( ( new AltText() )->apply( 99999999, $this->context() ) );
		$this->assertFalse( ( new AltText() )->apply( 0, $this->context() ) );
		$this->assertFalse( ( new AltText() )->apply( $post, $this->context() ) );
		$this->assertSame( '', (string) get_post_meta( $post, '_wp_attachment_image_alt', true ) );
	}

	/**
	 * Quotes and backslashes survive the round trip.
	 */
	public function test_special_characters_round_trip(): void {
		$id = $this->attachment();

		( new AltText() )->apply( $id, new AltContext( "O'Brien \\ \"Ring\"", '' ) );

		$this->assertSame( "O'Brien \\ \"Ring\"", get_post_meta( $id, '_wp_attachment_image_alt', true ) );
	}

	/**
	 * Only the native field is written: no new key, no option.
	 */
	public function test_only_the_native_field_is_written(): void {
		$id      = $this->attachment();
		$keys    = array_keys( get_post_meta( $id ) );
		$options = wp_load_alloptions( true );

		( new AltText() )->apply( $id, $this->context() );

		$after = array_keys( get_post_meta( $id ) );

		sort( $after );

		$expected = array_merge( $keys, array( '_wp_attachment_image_alt' ) );

		sort( $expected );

		$this->assertSame( $expected, $after );
		$this->assertSame( $options, wp_load_alloptions( true ) );
	}
}
