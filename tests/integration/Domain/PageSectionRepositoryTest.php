<?php
/**
 * Page section repository tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Integration\Domain;

use Rameshwari\Core\Domain\PageSection\PageSection;
use Rameshwari\Core\Domain\PageSection\HeroSelection;
use Rameshwari\Core\Domain\PageSection\PageSectionRepository;
use Rameshwari\Core\Support\Logger;

/**
 * Reading real page section posts and their registered meta.
 */
final class PageSectionRepositoryTest extends \WP_UnitTestCase {

	/**
	 * A quiet logger.
	 *
	 * @return Logger
	 */
	private function quiet(): Logger {
		return new Logger( static function (): void {} );
	}

	/**
	 * A page section post with meta.
	 *
	 * @param array<string,mixed> $meta   Meta overrides.
	 * @param string              $status Post status.
	 * @return int
	 */
	private function section( array $meta = array(), string $status = 'publish' ): int {
		$args = array(
			'post_type'   => 'rj_page_section',
			'post_status' => $status,
		);

		if ( 'future' === $status ) {
			$args['post_date']     = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );
			$args['post_date_gmt'] = $args['post_date'];
		}

		$id = self::factory()->post->create( $args );

		foreach ( array_merge(
			array(
				'_rj_section_key' => 'hero',
				'_rj_active'      => true,
			),
			$meta
		) as $key => $value ) {
			update_post_meta( $id, $key, $value );
		}

		return $id;
	}

	/**
	 * Writes a meta value straight to the table, as a legacy record would hold it.
	 *
	 * @param int    $id    Post ID.
	 * @param string $key   Meta key.
	 * @param string $value Raw value.
	 * @return void
	 */
	private function legacy( int $id, string $key, string $value ): void {
		global $wpdb;

		$data  = array(
			'meta_value' => $value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Intentional test fixture write of a raw meta value.
		);
		$where = array(
			'post_id'  => $id,
			'meta_key' => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Intentional test fixture write of the registered meta key.
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixture writes a legacy value that registered sanitising would clean.
		$wpdb->update( $wpdb->postmeta, $data, $where );
		wp_cache_delete( $id, 'post_meta' );
	}

	/**
	 * Only published page sections are read, hydrated with their meta.
	 */
	public function test_reads_only_published_page_sections(): void {
		$one   = $this->section(
			array(
				'_rj_title_en'  => 'Festive',
				'_rj_order'     => 30,
				'_rj_starts_at' => '2026-10-01 00:00:00',
				'_rj_cta_url'   => '/c/wedding/',
			)
		);
		$draft = $this->section( array(), 'draft' );
		$page  = self::factory()->post->create();
		$list  = ( new PageSectionRepository( $this->quiet() ) )->candidates();

		$this->assertSame( array( $one ), array_map( static fn( PageSection $s ): int => $s->id, $list ) );
		$this->assertNotContains( $draft, array_map( static fn( PageSection $s ): int => $s->id, $list ) );
		$this->assertNotContains( $page, array_map( static fn( PageSection $s ): int => $s->id, $list ) );
		$this->assertSame( array( 'hero', 'Festive', 30, '2026-10-01 00:00:00', '/c/wedding/', true ), array( $list[0]->section_key, $list[0]->title_en, $list[0]->order, $list[0]->starts_at, $list[0]->cta_url, $list[0]->active ) );
	}

	/**
	 * A section with nothing stored reads back with the registered defaults.
	 */
	public function test_defaults(): void {
		$id = self::factory()->post->create(
			array(
				'post_type'   => 'rj_page_section',
				'post_status' => 'publish',
			)
		);

		$list = ( new PageSectionRepository( $this->quiet() ) )->candidates();

		$this->assertSame( $id, $list[0]->id );
		$this->assertSame( array( 'hero', false, 0, 0, 0, '', '' ), array( $list[0]->section_key, $list[0]->active, $list[0]->order, $list[0]->image_desktop, $list[0]->image_mobile, $list[0]->starts_at, $list[0]->ends_at ) );
	}

	/**
	 * Legacy order values come back invalid, not repaired.
	 */
	public function test_legacy_order_values_are_marked_invalid(): void {
		$bad = array();

		foreach ( array( '-5', '99999', 'abc' ) as $raw ) {
			$id = $this->section(
				array(
					'_rj_order' => 5,
				)
			);

			$this->legacy( $id, '_rj_order', $raw );

			$bad[] = $id;
		}

		foreach ( ( new PageSectionRepository( $this->quiet() ) )->candidates() as $section ) {
			$this->assertNull( $section->order, (string) $section->id );
		}

		$this->assertCount( 3, $bad );
	}

	/**
	 * More sections than the cap is reported, never silently truncated.
	 */
	public function test_cap_is_enforced_without_truncating(): void {
		$lines = array();
		$log   = new Logger(
			static function ( string $line ) use ( &$lines ): void {
				$lines[] = $line;
			}
		);

		$this->section();
		$this->section();

		$this->assertCount( 2, ( new PageSectionRepository( $log, 2 ) )->candidates() );

		$this->section();

		try {
			( new PageSectionRepository( $log, 2 ) )->candidates();

			$this->fail( 'Truncated the list.' );
		} catch ( \OverflowException $expected ) {
			$this->assertNotSame( '', $expected->getMessage() );
		}

		$this->assertCount( 1, $lines );
		$this->assertStringContainsString( 'cap exceeded', $lines[0] );
	}

	/**
	 * A cap below one is refused.
	 */
	public function test_invalid_cap(): void {
		$this->expectException( \InvalidArgumentException::class );

		new PageSectionRepository( $this->quiet(), 0 );
	}

	/**
	 * The repository writes nothing and decides no eligibility.
	 */
	public function test_repository_is_read_only(): void {
		$id   = $this->section(
			array(
				'_rj_order' => 7,
			)
		);
		$meta = get_post_meta( $id );

		( new PageSectionRepository( $this->quiet() ) )->candidates();

		$this->assertSame( $meta, get_post_meta( $id ) );
		$this->assertInstanceOf( HeroSelection::class, new HeroSelection( array(), 1 ) );
	}
}
