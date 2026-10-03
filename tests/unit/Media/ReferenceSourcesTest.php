<?php
/**
 * Reference source list tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Unit\Media;

use PHPUnit\Framework\TestCase;
use Rameshwari\Core\Data\Meta;
use Rameshwari\Core\Services\Media\ReferenceSources;
use Rameshwari\Core\Services\Media\Value\ReferenceSource;

/**
 * The drift test: every attachment-typed key in the real registry is listed.
 */
final class ReferenceSourcesTest extends TestCase {

	/**
	 * Keys of one kind in the source list.
	 *
	 * @param string $kind Source kind.
	 * @return array<string,bool> Key => is list.
	 */
	private function listed( string $kind ): array {
		$keys = array();

		foreach ( ( new ReferenceSources() )->all() as $source ) {
			if ( $kind === $source->kind() ) {
				$keys[ $source->key() ] = $source->is_list();
			}
		}

		return $keys;
	}

	/**
	 * Registry keys that a source list does not contain.
	 *
	 * @param array<string,bool> $listed   Listed keys.
	 * @param array<string,bool> $expected Registry keys.
	 * @return array<int,string>
	 */
	private function missing( array $listed, array $expected ): array {
		return array_keys( array_diff_key( $expected, $listed ) );
	}

	/**
	 * Every attachment-typed post-meta key in the real registry is listed.
	 */
	public function test_every_registered_attachment_post_meta_key_is_listed(): void {
		$sources  = new ReferenceSources();
		$expected = array();

		foreach ( Meta::post_meta() as $keys ) {
			$expected += $sources->attachment_keys( $keys );
		}

		$this->assertNotEmpty( $expected );
		$this->assertSame( array(), $this->missing( $this->listed( ReferenceSource::POST_META ), $expected ) );
		$this->assertSame( array_keys( $expected ), array_values( array_intersect( array_keys( $expected ), array_keys( $this->listed( ReferenceSource::POST_META ) ) ) ) );
	}

	/**
	 * The drift check catches a key that is registered but not listed.
	 */
	public function test_drift_check_detects_an_unlisted_key(): void {
		$fake     = array( '_rj_new_media' => array( 'attachment', 0, true ) );
		$expected = ( new ReferenceSources() )->attachment_keys( $fake );

		$this->assertSame( array( '_rj_new_media' ), $this->missing( $this->listed( ReferenceSource::POST_META ), $expected ) );
	}

	/**
	 * Image and video rules count as attachment IDs, and a list rule is marked as a list.
	 */
	public function test_single_and_list_registrations_are_told_apart(): void {
		$keys = ( new ReferenceSources() )->attachment_keys(
			array(
				'_a' => array( 'attachment', 0, true ),
				'_b' => array( 'image', 0, true ),
				'_c' => array( 'video', 0, true ),
				'_d' => array( 'attachments:12', array(), true ),
				'_e' => array( 'text:10', '', true ),
				'_f' => array( 'products:8', array(), true ),
			)
		);

		$this->assertSame(
			array(
				'_a' => false,
				'_b' => false,
				'_c' => false,
				'_d' => true,
			),
			$keys
		);
	}

	/**
	 * The gallery is a list and the poster is a single ID.
	 */
	public function test_known_keys_have_the_right_shape(): void {
		$meta = $this->listed( ReferenceSource::POST_META );

		$this->assertTrue( $meta['_rj_gallery'] );
		$this->assertFalse( $meta['_rj_poster_id'] );
	}

	/**
	 * Every attachment-typed term-meta key is listed.
	 */
	public function test_term_meta_attachment_fields_are_listed(): void {
		$expected = ( new ReferenceSources() )->attachment_keys( Meta::term_meta() );

		$this->assertArrayHasKey( '_rj_image_id', $expected );
		$this->assertSame( array(), $this->missing( $this->listed( ReferenceSource::TERM_META ), $expected ) );
	}

	/**
	 * The registered attachment option field is listed.
	 */
	public function test_option_attachment_field_is_listed(): void {
		$fields = array();

		foreach ( ( new ReferenceSources() )->all() as $source ) {
			if ( ReferenceSource::OPTION === $source->kind() ) {
				$fields[] = $source->field();
			}
		}

		$this->assertContains( 'rj_seo.open_graph.default_image_id', $fields );
	}

	/**
	 * The featured image is the first source.
	 */
	public function test_featured_image_is_listed_first(): void {
		$first = ( new ReferenceSources() )->all()[0];

		$this->assertSame( ReferenceSource::FEATURED, $first->kind() );
		$this->assertSame( '_thumbnail_id', $first->key() );
	}

	/**
	 * The order is the same on every call.
	 */
	public function test_order_is_deterministic(): void {
		$sources = new ReferenceSources();
		$labels  = static fn ( ReferenceSource $s ): string => $s->kind() . ':' . $s->field();

		$this->assertSame( array_map( $labels, $sources->all() ), array_map( $labels, ( new ReferenceSources() )->all() ) );
	}
}
