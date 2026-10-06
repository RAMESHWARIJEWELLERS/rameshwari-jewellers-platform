<?php
/**
 * Showroom repository tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Integration\Domain;

use Rameshwari\Core\Domain\Showroom\Showroom;
use Rameshwari\Core\Domain\Showroom\ShowroomRepository;

/**
 * Reading and writing a real showroom through its registered meta.
 */
final class ShowroomRepositoryTest extends \WP_UnitTestCase {

	/**
	 * Creates an attachment of a MIME type.
	 *
	 * @param string $mime MIME type.
	 * @return int
	 */
	private function attachment( string $mime ): int {
		$id = wp_insert_attachment(
			array(
				'post_mime_type' => $mime,
				'post_title'     => 'Fixture',
				'post_status'    => 'inherit',
			),
			'rjdomain-' . wp_generate_uuid4()
		);

		$this->assertIsInt( $id );
		$this->assertGreaterThan( 0, $id );

		return $id;
	}

	/**
	 * A new showroom.
	 *
	 * @return int
	 */
	private function post(): int {
		return self::factory()->post->create(
			array(
				'post_type'   => 'rj_showroom',
				'post_status' => 'publish',
			)
		);
	}

	/**
	 * Nothing stored reads back empty, with no hours.
	 */
	public function test_defaults_for_a_fresh_showroom(): void {
		$showroom = ( new ShowroomRepository() )->find( $this->post() );

		$this->assertInstanceOf( Showroom::class, $showroom );
		$this->assertSame( array(), $showroom->timings );
		$this->assertSame( array(), $showroom->gallery );
		$this->assertFalse( $showroom->has_location() );
	}

	/**
	 * Address, contact, map, location, gallery and timings survive a save.
	 */
	public function test_round_trip(): void {
		$id      = $this->post();
		$gallery = array( $this->attachment( 'image/png' ), $this->attachment( 'image/png' ) );
		$timings = array(
			'mon' => array(
				'open'  => '10:00',
				'close' => '20:00',
			),
			'sun' => array( 'closed' => true ),
		);
		$repo    = new ShowroomRepository();

		$this->assertTrue( $repo->save( new Showroom( $id, 'publish', 'Near Example Road', 'Jaipur', 'Rajasthan', '302012', '911234567890', '919876543210', $timings, 'https://maps.example.test/x', 26.9124, 75.7873, $gallery ) ) );

		$back = $repo->find( $id );

		$this->assertInstanceOf( Showroom::class, $back );
		$this->assertSame( array( 'Near Example Road', 'Jaipur', 'Rajasthan', '302012' ), array( $back->address, $back->city, $back->state, $back->pincode ) );
		$this->assertSame( array( '911234567890', '919876543210' ), array( $back->phone, $back->whatsapp ) );
		$this->assertSame( $timings, $back->timings );
		$this->assertSame( $gallery, $back->gallery );
		$this->assertEqualsWithDelta( 26.9124, $back->lat, 0.0000001 );
		$this->assertEqualsWithDelta( 75.7873, $back->lng, 0.0000001 );
	}

	/**
	 * Hours are stored under _rj_timings and no other hours key exists on the post.
	 */
	public function test_hours_are_stored_only_in_timings(): void {
		$id = $this->post();

		( new ShowroomRepository() )->save( new Showroom( $id, 'publish', '', '', '', '', '', '', array( 'mon' => array( 'closed' => true ) ), '', 0.0, 0.0, array() ) );

		$meta = get_post_meta( $id );

		$this->assertArrayHasKey( '_rj_timings', $meta );
		$this->assertSame( array(), array_values( preg_grep( '/hour|opening|open_/i', array_keys( $meta ) ) ) );
	}

	/**
	 * A gallery longer than twelve is kept: the showroom gallery has no limit.
	 */
	public function test_gallery_is_unbounded(): void {
		$id      = $this->post();
		$gallery = array();

		for ( $i = 0; $i < 15; $i++ ) {
			$gallery[] = $this->attachment( 'image/png' );
		}

		( new ShowroomRepository() )->save( new Showroom( $id, 'publish', '', '', '', '', '', '', array(), '', 0.0, 0.0, $gallery ) );

		$back = ( new ShowroomRepository() )->find( $id );

		$this->assertInstanceOf( Showroom::class, $back );
		$this->assertCount( 15, $back->gallery );
	}

	/**
	 * A post of another type is not a showroom and cannot be saved as one.
	 */
	public function test_other_post_types_are_not_showrooms(): void {
		$page = self::factory()->post->create();
		$repo = new ShowroomRepository();

		$this->assertNull( $repo->find( $page ) );
		$this->assertFalse( $repo->save( new Showroom( $page, 'publish', '', '', '', '', '', '', array(), '', 0.0, 0.0, array() ) ) );
	}
}
