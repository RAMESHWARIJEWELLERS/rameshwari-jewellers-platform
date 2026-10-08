<?php
/**
 * Page section module tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Integration\Domain;

use Rameshwari\Core\Data\Meta;
use Rameshwari\Core\Data\PostTypes;
use Rameshwari\Core\Domain\PageSection\HeroReader;
use Rameshwari\Core\Domain\PageSection\HeroSelection;
use Rameshwari\Core\Domain\PageSection\PageSection;
use Rameshwari\Core\Domain\PageSection\PageSectionModule;
use Rameshwari\Core\Domain\PageSection\PageSectionRepository;
use Rameshwari\Core\Plugin;
use Rameshwari\Core\Support\Cache;
use Rameshwari\Core\Support\Logger;

/**
 * Every change that can alter the hero answer invalidates the cache, and nothing else does.
 */
final class PageSectionModuleTest extends \WP_UnitTestCase {

	/**
	 * Fixed clock in the Kolkata timezone.
	 *
	 * @param string $time Y-m-d H:i:s.
	 * @return \DateTimeImmutable
	 */
	private function now( string $time = '2026-10-06 12:00:00' ): \DateTimeImmutable {
		return new \DateTimeImmutable( $time, new \DateTimeZone( 'Asia/Kolkata' ) );
	}

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
	 * IDs of a selection's desktop list.
	 *
	 * @param HeroSelection $selection Selection.
	 * @return array<int,int>
	 */
	private function ids( HeroSelection $selection ): array {
		return array_map( static fn( PageSection $section ): int => $section->id, $selection->desktop() );
	}

	/**
	 * A cache with the hero answer already stored.
	 *
	 * @return Cache
	 */
	private function warm(): Cache {
		$cache  = new Cache( HeroReader::GROUP, false );
		$moment = $this->now();
		$reader = new HeroReader( new PageSectionRepository( $this->quiet() ), $cache, $this->quiet(), static fn(): \DateTimeImmutable => $moment );

		$reader->selection();

		$this->assertTrue( $cache->has( HeroReader::KEY ) );

		return $cache;
	}

	/**
	 * The module is registered and its services resolve.
	 */
	public function test_module_is_wired_into_the_plugin(): void {
		$plugin = Plugin::instance();

		if ( null === $plugin ) {
			$this->fail( 'The plugin did not boot.' );
		}

		$container = $plugin->container();

		$this->assertInstanceOf( HeroReader::class, $container->get( HeroReader::class ) );
		$this->assertInstanceOf( Cache::class, $container->get( PageSectionModule::CACHE_ID ) );
		$this->assertSame( array( PostTypes::id(), Meta::id() ), PageSectionModule::requires() );
		$this->assertSame( 'page_section', PageSectionModule::id() );
	}

	/**
	 * Saving, a meta write, a delete and a status change each bump the cache.
	 */
	public function test_each_change_invalidates(): void {
		$id = $this->section();

		$actions = array(
			'save'       => static function () use ( $id ): void {
				wp_update_post(
					array(
						'ID'         => $id,
						'post_title' => 'Changed',
					)
				);
			},
			'meta add'   => static function () use ( $id ): void {
				delete_post_meta( $id, '_rj_cta_label' );
				add_post_meta( $id, '_rj_cta_label', 'Shop' );
			},
			'meta set'   => static function () use ( $id ): void {
				update_post_meta( $id, '_rj_order', 77 );
			},
			'meta unset' => static function () use ( $id ): void {
				delete_post_meta( $id, '_rj_active' );
			},
			'status'     => static function () use ( $id ): void {
				wp_transition_post_status( 'draft', 'publish', get_post( $id ) );
			},
			'trash'      => static function () use ( $id ): void {
				wp_trash_post( $id );
			},
			'delete'     => static function () use ( $id ): void {
				wp_delete_post( $id, true );
			},
		);

		foreach ( $actions as $name => $action ) {
			$cache = $this->warm();

			$action();

			$this->assertFalse( $cache->has( HeroReader::KEY ), $name );

			if ( 'trash' === $name ) {
				$cache = $this->warm();

				wp_untrash_post( $id );

				$this->assertFalse( $cache->has( HeroReader::KEY ), 'untrash' );
			}
		}
	}

	/**
	 * A REST-style meta write is seen by the very next read.
	 */
	public function test_meta_write_is_visible_to_the_next_read(): void {
		$cache  = new Cache( HeroReader::GROUP, false );
		$id     = $this->section(
			array(
				'_rj_order' => 5,
			)
		);
		$other  = $this->section(
			array(
				'_rj_order' => 9,
			)
		);
		$moment = $this->now();
		$reader = new HeroReader( new PageSectionRepository( $this->quiet() ), $cache, $this->quiet(), static fn(): \DateTimeImmutable => $moment );

		$this->assertSame( array( $id, $other ), $this->ids( $reader->selection() ) );

		update_post_meta( $id, '_rj_order', 50 );

		$this->assertSame( array( $other, $id ), $this->ids( $reader->selection() ) );
	}

	/**
	 * Unrelated writes leave the cache alone.
	 */
	public function test_unrelated_changes_do_not_invalidate(): void {
		$id    = $this->section();
		$post  = self::factory()->post->create();
		$cache = $this->warm();

		update_post_meta( $id, '_unrelated_key', 1 );
		update_post_meta( $post, '_rj_order', 3 );
		wp_update_post(
			array(
				'ID'         => $post,
				'post_title' => 'Elsewhere',
			)
		);

		$this->assertTrue( $cache->has( HeroReader::KEY ) );
		$this->assertInstanceOf( HeroSelection::class, new HeroSelection( array(), 1 ) );
		$this->assertInstanceOf( PageSection::class, ( new PageSectionRepository( $this->quiet() ) )->candidates()[0] );
	}
}
