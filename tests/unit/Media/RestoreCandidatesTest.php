<?php
/**
 * Restore candidates tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Unit\Media;

use PHPUnit\Framework\TestCase;
use Rameshwari\Core\Services\Media\Value\RestoreCandidates;

/**
 * The list keeps the order it was given and checks its names.
 */
final class RestoreCandidatesTest extends TestCase {

	/**
	 * Names keep their order, newest first.
	 */
	public function test_order_is_preserved(): void {
		$list = new RestoreCandidates( 5, array( 'c', 'a', 'b' ) );

		$this->assertSame( array( 'c', 'a', 'b' ), $list->names() );
		$this->assertSame( 'c', $list->newest() );
		$this->assertSame( 3, $list->count() );
		$this->assertSame( 5, $list->attachment_id() );
		$this->assertTrue( $list->has( 'a' ) );
		$this->assertFalse( $list->has( 'z' ) );
	}

	/**
	 * An empty list has no newest version.
	 */
	public function test_empty_list(): void {
		$list = new RestoreCandidates( 5, array() );

		$this->assertNull( $list->newest() );
		$this->assertSame( 0, $list->count() );
	}

	/**
	 * A bad ID, a blank name or a repeated name is refused.
	 */
	public function test_invalid_construction_is_rejected(): void {
		foreach ( array( array( 0, array( 'a' ) ), array( 1, array( ' ' ) ), array( 1, array( 'a', 'a' ) ) ) as $args ) {
			try {
				new RestoreCandidates( $args[0], $args[1] );

				$this->fail( 'An invalid list was accepted.' );
			} catch ( \InvalidArgumentException $expected ) {
				$this->assertNotSame( '', $expected->getMessage() );
			}
		}
	}

	/**
	 * The list cannot be changed after construction.
	 */
	public function test_names_are_read_only(): void {
		$this->assertTrue( ( new \ReflectionClass( RestoreCandidates::class ) )->getProperty( 'names' )->isReadOnly() );
	}
}
