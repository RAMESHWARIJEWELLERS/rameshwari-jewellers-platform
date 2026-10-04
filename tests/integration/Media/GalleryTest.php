<?php
/**
 * Gallery normalisation and ordering tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Integration\Media;

use Rameshwari\Core\Services\Media\Gallery;

/**
 * Normalisation and read-time ordering against real posts and attachments.
 */
final class GalleryTest extends \WP_UnitTestCase {

	/**
	 * Write statements seen while a test watches the database.
	 *
	 * @var array<int,string>
	 */
	private array $writes = array();

	/**
	 * Creates an attachment.
	 *
	 * @return int
	 */
	private function attachment(): int {
		return wp_insert_attachment(
			array(
				'post_mime_type' => 'image/png',
				'post_title'     => 'Fixture',
				'post_status'    => 'inherit',
			)
		);
	}

	/**
	 * Creates an ordinary post that is not an attachment.
	 *
	 * @return int
	 */
	private function post(): int {
		return wp_insert_post(
			array(
				'post_title'  => 'Owner',
				'post_status' => 'publish',
			)
		);
	}

	/**
	 * Starts recording write statements.
	 */
	private function watch(): void {
		$this->writes = array();

		add_filter(
			'query',
			function ( string $sql ): string {
				if ( 1 === preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/i', $sql ) ) {
					$this->writes[] = $sql;
				}

				return $sql;
			}
		);
	}

	/**
	 * IDs, numeric strings and repeats: kept in first-occurrence order.
	 */
	public function test_normalize_casts_and_keeps_first_occurrence(): void {
		$a = $this->attachment();
		$b = $this->attachment();

		$result = ( new Gallery() )->normalize(
			array( (string) $b, $a, $b, (string) $a ),
			12
		);

		$this->assertSame( array( $b, $a ), $result->ids() );
		$this->assertSame(
			array(
				array(
					'id'     => $b,
					'reason' => 'duplicate',
				),
				array(
					'id'     => $a,
					'reason' => 'duplicate',
				),
			),
			$result->dropped()
		);
	}

	/**
	 * Zero, negative and non-numeric values are dropped as invalid.
	 */
	public function test_normalize_drops_invalid_values(): void {
		$a = $this->attachment();

		$result = ( new Gallery() )->normalize(
			array( 0, -3, 'abc', null, 5.5, array(), $a ),
			12
		);

		$this->assertSame( array( $a ), $result->ids() );
		$this->assertSame(
			array(
				'invalid',
				'invalid',
				'invalid',
				'invalid',
				'invalid',
				'invalid',
			),
			array_column( $result->dropped(), 'reason' )
		);
		$this->assertSame( -3, $result->dropped()[1]['id'] );
	}

	/**
	 * IDs of missing posts and of posts that are not attachments are dropped.
	 */
	public function test_normalize_drops_non_attachments(): void {
		$a    = $this->attachment();
		$post = $this->post();

		$result = ( new Gallery() )->normalize(
			array( $post, 99999999, $a ),
			12
		);

		$this->assertSame( array( $a ), $result->ids() );
		$this->assertSame(
			array(
				array(
					'id'     => $post,
					'reason' => 'not_attachment',
				),
				array(
					'id'     => 99999999,
					'reason' => 'not_attachment',
				),
			),
			$result->dropped()
		);
	}

	/**
	 * The maximum trims from the end and reports what it cut.
	 */
	public function test_normalize_applies_the_supplied_maximum(): void {
		$ids = array(
			$this->attachment(),
			$this->attachment(),
			$this->attachment(),
		);

		$result = ( new Gallery() )->normalize( $ids, 2 );

		$this->assertSame( array( $ids[0], $ids[1] ), $result->ids() );
		$this->assertSame(
			array(
				array(
					'id'     => $ids[2],
					'reason' => 'over_limit',
				),
			),
			$result->dropped()
		);
	}

	/**
	 * Exactly the maximum is kept whole.
	 */
	public function test_normalize_maximum_boundary(): void {
		$ids = array(
			$this->attachment(),
			$this->attachment(),
		);

		$this->assertSame(
			$ids,
			( new Gallery() )->normalize( $ids, 2 )->ids()
		);
		$this->assertFalse(
			( new Gallery() )->normalize( $ids, 2 )->changed()
		);
	}

	/**
	 * A maximum of zero keeps nothing and reports every valid attachment as over the limit.
	 */
	public function test_normalize_zero_maximum_keeps_nothing(): void {
		$ids = array(
			$this->attachment(),
			$this->attachment(),
			$this->attachment(),
		);

		$result = ( new Gallery() )->normalize( $ids, 0 );

		$this->assertSame( array(), $result->ids() );
		$this->assertSame(
			array(
				array(
					'id'     => $ids[0],
					'reason' => 'over_limit',
				),
				array(
					'id'     => $ids[1],
					'reason' => 'over_limit',
				),
				array(
					'id'     => $ids[2],
					'reason' => 'over_limit',
				),
			),
			$result->dropped()
		);
	}

	/**
	 * A negative maximum is refused.
	 */
	public function test_normalize_negative_maximum_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		( new Gallery() )->normalize(
			array( $this->attachment() ),
			-1
		);
	}

	/**
	 * The same input always gives the same report.
	 */
	public function test_normalize_is_deterministic_and_does_not_mutate_input(): void {
		$a     = $this->attachment();
		$input = array( 'x', $a, $a, 0 );
		$first = ( new Gallery() )->normalize( $input, 12 );

		$this->assertEquals(
			$first,
			( new Gallery() )->normalize( $input, 12 )
		);
		$this->assertSame( array( 'x', $a, $a, 0 ), $input );
	}

	/**
	 * Fixture owner with a gallery.
	 *
	 * @param array<int,mixed> $gallery Stored gallery value.
	 * @param int              $thumb   Featured image ID; 0 for none.
	 * @return int Owner post ID.
	 */
	private function owner( array $gallery, int $thumb = 0 ): int {
		$id = $this->post();

		update_post_meta( $id, '_rj_gallery', $gallery );

		if ( $thumb > 0 ) {
			update_post_meta( $id, '_thumbnail_id', $thumb );
		}

		return $id;
	}

	/**
	 * The featured image comes first, then the gallery in stored order.
	 */
	public function test_ordered_puts_featured_first_then_stored_order(): void {
		$f = $this->attachment();
		$a = $this->attachment();
		$b = $this->attachment();

		$this->assertSame(
			array( $f, $b, $a ),
			( new Gallery() )->ordered(
				$this->owner( array( $b, $a ), $f )
			)
		);
	}

	/**
	 * A gallery entry equal to the featured image is not repeated.
	 */
	public function test_ordered_skips_gallery_entry_equal_to_featured(): void {
		$f = $this->attachment();
		$a = $this->attachment();

		$this->assertSame(
			array( $f, $a ),
			( new Gallery() )->ordered(
				$this->owner( array( $a, $f ), $f )
			)
		);
	}

	/**
	 * Deleted, missing and non-attachment entries are skipped at read time.
	 */
	public function test_ordered_skips_missing_deleted_and_non_attachments(): void {
		$a       = $this->attachment();
		$gone    = $this->attachment();
		$notfile = $this->post();

		wp_delete_attachment( $gone, true );

		$this->assertSame(
			array( $a ),
			( new Gallery() )->ordered(
				$this->owner(
					array( $gone, 99999999, $notfile, $a )
				)
			)
		);
	}

	/**
	 * Without a featured image, the gallery alone is returned; an empty gallery gives nothing.
	 */
	public function test_ordered_without_featured_and_with_empty_gallery(): void {
		$a = $this->attachment();

		$this->assertSame(
			array( $a ),
			( new Gallery() )->ordered(
				$this->owner( array( $a ) )
			)
		);
		$this->assertSame(
			array(),
			( new Gallery() )->ordered(
				$this->owner( array() )
			)
		);
	}

	/**
	 * A missing or invalid post gives an empty list, never an error.
	 */
	public function test_ordered_for_missing_or_invalid_post(): void {
		$gallery = new Gallery();

		$this->assertSame( array(), $gallery->ordered( 99999999 ) );
		$this->assertSame( array(), $gallery->ordered( 0 ) );
		$this->assertSame( array(), $gallery->ordered( -5 ) );
	}

	/**
	 * Repeated reads give the same answer, and a repeat inside the stored gallery shows once.
	 */
	public function test_ordered_is_deterministic_and_lists_a_repeat_once(): void {
		$a     = $this->attachment();
		$owner = $this->owner( array( $a, (string) $a ) );

		$this->assertSame(
			array( $a ),
			( new Gallery() )->ordered( $owner )
		);
		$this->assertSame(
			( new Gallery() )->ordered( $owner ),
			( new Gallery() )->ordered( $owner )
		);
	}

	/**
	 * Reading never rewrites the stored gallery, even when it holds junk.
	 */
	public function test_ordered_does_not_modify_stored_data(): void {
		$a     = $this->attachment();
		$raw   = array( $a, 99999999, 'x', $a );
		$owner = $this->owner( $raw );

		( new Gallery() )->ordered( $owner );

		$this->assertSame(
			$raw,
			get_post_meta( $owner, '_rj_gallery', true )
		);
	}

	/**
	 * None of the five operations issues a write statement.
	 */
	public function test_no_operation_writes_to_the_database(): void {
		$a       = $this->attachment();
		$owner   = $this->owner( array( $a ), $a );
		$gallery = new Gallery();
		$options = wp_load_alloptions( true );

		$this->watch();

		$gallery->normalize( array( $a, 0, 'x' ), 12 );

		$added   = $gallery->add( array( $a ), 7 );
		$removed = $gallery->remove( array( $a ), $a );

		$gallery->move( array( $a, 7 ), 7, 0 );
		$gallery->ordered( $owner );

		$this->assertSame( array( $a, 7 ), $added );
		$this->assertSame( array(), $removed );

		$this->assertSame( array(), $this->writes );
		$this->assertSame( $options, wp_load_alloptions( true ) );
	}
}
