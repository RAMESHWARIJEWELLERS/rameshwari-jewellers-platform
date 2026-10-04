<?php
/**
 * Gallery array operation tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Unit\Media;

use PHPUnit\Framework\TestCase;
use Rameshwari\Core\Services\Media\Gallery;

/**
 * The pure operations: add, remove and move return new arrays and write nothing.
 */
final class GalleryTest extends TestCase {

	/**
	 * A new attachment is appended and the input is untouched.
	 */
	public function test_add_appends_new_attachment(): void {
		$input = array( 5, 9 );

		$this->assertSame( array( 5, 9, 12 ), ( new Gallery() )->add( $input, 12 ) );
		$this->assertSame( array( 5, 9 ), $input );
	}

	/**
	 * An attachment already present is not added twice and order is kept.
	 */
	public function test_add_does_not_duplicate_and_keeps_order(): void {
		$this->assertSame( array( 5, 9, 2 ), ( new Gallery() )->add( array( 5, 9, 2 ), 9 ) );
	}

	/**
	 * A non-positive ID is not added.
	 */
	public function test_add_ignores_non_positive_ids(): void {
		$gallery = new Gallery();

		$this->assertSame( array( 5 ), $gallery->add( array( 5 ), 0 ) );
		$this->assertSame( array( 5 ), $gallery->add( array( 5 ), -4 ) );
	}

	/**
	 * Removing takes the ID out and keeps the order of the rest.
	 */
	public function test_remove_removes_and_keeps_remaining_order(): void {
		$input = array( 5, 9, 2, 7 );

		$this->assertSame( array( 5, 2, 7 ), ( new Gallery() )->remove( $input, 9 ) );
		$this->assertSame( array( 5, 9, 2, 7 ), $input );
	}

	/**
	 * Removing an absent ID gives the same logical list.
	 */
	public function test_remove_absent_id_is_unchanged(): void {
		$this->assertSame( array( 5, 9 ), ( new Gallery() )->remove( array( 5, 9 ), 77 ) );
	}

	/**
	 * Moving forward places the ID at the position and shifts the rest.
	 */
	public function test_move_forward(): void {
		$this->assertSame( array( 9, 2, 5, 7 ), ( new Gallery() )->move( array( 5, 9, 2, 7 ), 5, 2 ) );
	}

	/**
	 * Moving backward places the ID at the position and shifts the rest.
	 */
	public function test_move_backward(): void {
		$this->assertSame( array( 5, 7, 9, 2 ), ( new Gallery() )->move( array( 5, 9, 2, 7 ), 7, 1 ) );
	}

	/**
	 * Moving to the first and to the last place.
	 */
	public function test_move_to_first_and_last(): void {
		$gallery = new Gallery();

		$this->assertSame( array( 7, 5, 9, 2 ), $gallery->move( array( 5, 9, 2, 7 ), 7, 0 ) );
		$this->assertSame( array( 9, 2, 7, 5 ), $gallery->move( array( 5, 9, 2, 7 ), 5, 3 ) );
	}

	/**
	 * Moving to where it already is changes nothing.
	 */
	public function test_move_to_same_position(): void {
		$this->assertSame( array( 5, 9, 2 ), ( new Gallery() )->move( array( 5, 9, 2 ), 9, 1 ) );
	}

	/**
	 * An absent ID is never invented.
	 */
	public function test_move_absent_id_is_unchanged(): void {
		$this->assertSame( array( 5, 9 ), ( new Gallery() )->move( array( 5, 9 ), 77, 0 ) );
	}

	/**
	 * An out-of-range position is clamped: below zero to the start, past the end to the end.
	 */
	public function test_move_clamps_out_of_range_position(): void {
		$gallery = new Gallery();

		$this->assertSame( array( 2, 5, 9 ), $gallery->move( array( 5, 9, 2 ), 2, -10 ) );
		$this->assertSame( array( 9, 2, 5 ), $gallery->move( array( 5, 9, 2 ), 5, 99 ) );
	}

	/**
	 * Move leaves its input untouched.
	 */
	public function test_move_does_not_mutate_input(): void {
		$input = array( 5, 9, 2 );

		( new Gallery() )->move( $input, 2, 0 );

		$this->assertSame( array( 5, 9, 2 ), $input );
	}

	/**
	 * The service holds no state, so it has nothing to write with.
	 */
	public function test_service_is_stateless(): void {
		$class = new \ReflectionClass( Gallery::class );

		$this->assertTrue( $class->isFinal() );
		$this->assertSame( array(), $class->getProperties() );
	}
}
