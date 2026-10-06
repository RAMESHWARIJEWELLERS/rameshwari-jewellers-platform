<?php
/**
 * Collection repository tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Integration\Domain;

use Rameshwari\Core\Domain\Collection\Collection;
use Rameshwari\Core\Domain\Collection\CollectionRepository;

/**
 * Reading and writing a real collection through its registered meta.
 */
final class CollectionRepositoryTest extends \WP_UnitTestCase {

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
	 * A new collection.
	 *
	 * @return int
	 */
	private function post(): int {
		return self::factory()->post->create(
			array(
				'post_type'   => 'rj_collection',
				'post_status' => 'publish',
			)
		);
	}

	/**
	 * Nothing stored reads back with the registered defaults.
	 */
	public function test_defaults_for_a_fresh_collection(): void {
		$collection = ( new CollectionRepository() )->find( $this->post() );

		$this->assertInstanceOf( Collection::class, $collection );
		$this->assertSame( array( 0, '', 0, array() ), array( $collection->cover_id, $collection->badge, $collection->order, $collection->auto_rule ) );
		$this->assertFalse( $collection->is_automatic() );
	}

	/**
	 * Cover, badge, order and rule survive a save.
	 */
	public function test_round_trip(): void {
		$id    = $this->post();
		$cover = $this->attachment( 'image/png' );
		$repo  = new CollectionRepository();

		$this->assertTrue( $repo->save( new Collection( $id, 'publish', $cover, 'Festive', 4, array( 'metal' => 'gold' ) ) ) );

		$back = $repo->find( $id );

		$this->assertInstanceOf( Collection::class, $back );
		$this->assertSame( array( $cover, 'Festive', 4 ), array( $back->cover_id, $back->badge, $back->order ) );
		$this->assertSame( array( 'metal' => 'gold' ), $back->auto_rule );
		$this->assertTrue( $back->is_automatic() );
	}

	/**
	 * A post of another type is not a collection and cannot be saved as one.
	 */
	public function test_other_post_types_are_not_collections(): void {
		$page = self::factory()->post->create();
		$repo = new CollectionRepository();

		$this->assertNull( $repo->find( $page ) );
		$this->assertFalse( $repo->save( new Collection( $page, 'publish', 0, '', 0, array() ) ) );
	}

	/**
	 * A collection with a trashed status stays readable and addressable.
	 */
	public function test_retired_collection_stays_readable(): void {
		$id = $this->post();

		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'draft',
			)
		);

		$collection = ( new CollectionRepository() )->find( $id );

		$this->assertInstanceOf( Collection::class, $collection );
		$this->assertSame( 'draft', $collection->status );
	}

	/**
	 * Saving adds no membership meta and no other key.
	 */
	public function test_save_writes_only_registered_collection_keys(): void {
		$id = $this->post();

		( new CollectionRepository() )->save( new Collection( $id, 'publish', 0, '', 1, array() ) );

		$keys = array_filter( array_keys( get_post_meta( $id ) ), static fn( string $key ): bool => str_starts_with( $key, '_rj_' ) );

		$this->assertEqualsCanonicalizing( array( '_rj_cover_id', '_rj_badge', '_rj_order', '_rj_auto_rule' ), array_values( $keys ) );
	}
}
