<?php
/**
 * Page section module.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Domain\PageSection;

use Rameshwari\Core\Container;
use Rameshwari\Core\Data\Meta;
use Rameshwari\Core\Data\PostTypes;
use Rameshwari\Core\Module;
use Rameshwari\Core\Support\Cache;
use Rameshwari\Core\Support\Logger;

/**
 * Declares the page section services and keeps the hero cache correct.
 *
 * Any change that can alter the hero answer bumps the page_sections cache
 * group: saving, trashing, restoring, deleting, a status change, or a write to
 * one of the section meta keys. The meta hooks fire after the write, so a
 * REST update cannot leave pre-update data cached. It registers nothing new in
 * WordPress and flushes no other cache.
 */
final class PageSectionModule implements Module {

	public const CACHE_ID = 'page_sections.cache';

	/**
	 * Shared container, set by register().
	 *
	 * @var Container|null
	 */
	private ?Container $container = null;

	/**
	 * The section meta keys, read from the registered Meta contract.
	 *
	 * @var array<int,string>|null
	 */
	private ?array $keys = null;

	/**
	 * Module id.
	 *
	 * @return string
	 */
	public static function id(): string {
		return 'page_section';
	}

	/**
	 * Needs the registered post types and meta keys.
	 *
	 * @return array<int,string>
	 */
	public static function requires(): array {
		return array( PostTypes::id(), Meta::id() );
	}

	/**
	 * Declares the services and adds the invalidation hooks.
	 *
	 * @param Container $container Shared container.
	 * @return void
	 */
	public function register( Container $container ): void {
		$this->container = $container;

		$container->set( self::CACHE_ID, static fn(): Cache => self::new_cache() );
		$container->set(
			PageSectionRepository::class,
			static fn( Container $c ): PageSectionRepository => new PageSectionRepository( self::logger( $c ) )
		);
		$container->set(
			HeroReader::class,
			static fn( Container $c ): HeroReader => new HeroReader( self::repository( $c ), self::shared_cache( $c ), self::logger( $c ) )
		);

		add_action( 'save_post_' . PageSectionRepository::TYPE, array( $this, 'on_post' ), 10, 1 );
		add_action( 'wp_trash_post', array( $this, 'on_post' ), 10, 1 );
		add_action( 'trashed_post', array( $this, 'on_post' ), 10, 1 );
		add_action( 'untrashed_post', array( $this, 'on_post' ), 10, 1 );
		add_action( 'deleted_post', array( $this, 'on_deleted' ), 10, 2 );
		add_action( 'transition_post_status', array( $this, 'on_transition' ), 10, 3 );
		add_action( 'added_post_meta', array( $this, 'on_meta' ), 10, 3 );
		add_action( 'updated_post_meta', array( $this, 'on_meta' ), 10, 3 );
		add_action( 'deleted_post_meta', array( $this, 'on_meta' ), 10, 3 );
	}

	/**
	 * A post changed, was trashed or was restored.
	 *
	 * @param mixed $post_id Post ID.
	 * @return void
	 */
	public function on_post( mixed $post_id ): void {
		if ( is_int( $post_id ) && PageSectionRepository::TYPE === get_post_type( $post_id ) ) {
			$this->cache()->bump();
		}
	}

	/**
	 * A post was deleted.
	 *
	 * @param mixed $post_id Post ID.
	 * @param mixed $post    The deleted post.
	 * @return void
	 */
	public function on_deleted( mixed $post_id, mixed $post ): void {
		if ( $post instanceof \WP_Post && PageSectionRepository::TYPE === $post->post_type ) {
			$this->cache()->bump();
		}
	}

	/**
	 * A post changed status.
	 *
	 * @param mixed $new_status New status.
	 * @param mixed $old_status Old status.
	 * @param mixed $post       Post.
	 * @return void
	 */
	public function on_transition( mixed $new_status, mixed $old_status, mixed $post ): void {
		if ( $post instanceof \WP_Post && PageSectionRepository::TYPE === $post->post_type && $new_status !== $old_status ) {
			$this->cache()->bump();
		}
	}

	/**
	 * A post meta value was added, updated or deleted.
	 *
	 * @param mixed $meta_id   Meta ID, or IDs for a delete.
	 * @param mixed $object_id Post ID.
	 * @param mixed $meta_key  Meta key.
	 * @return void
	 */
	public function on_meta( mixed $meta_id, mixed $object_id, mixed $meta_key ): void {
		if ( ! is_string( $meta_key ) || ! is_int( $object_id ) || ! in_array( $meta_key, $this->keys(), true ) ) {
			return;
		}

		if ( PageSectionRepository::TYPE === get_post_type( $object_id ) ) {
			$this->cache()->bump();
		}
	}

	/**
	 * Meta keys registered for page sections.
	 *
	 * @return array<int,string>
	 */
	private function keys(): array {
		if ( null === $this->keys ) {
			$this->keys = array_keys( Meta::post_meta()[ PageSectionRepository::TYPE ] );
		}

		return $this->keys;
	}

	/**
	 * The shared cache.
	 *
	 * @return Cache
	 */
	private function cache(): Cache {
		return null === $this->container ? self::new_cache() : self::shared_cache( $this->container );
	}

	/**
	 * A cache for the page_sections group with an explicit storage mode.
	 *
	 * WordPress's detection function can return null before the object cache
	 * global is set, and Cache's mode property accepts only a bool, so the
	 * answer is cast here rather than passed through as null.
	 *
	 * @return Cache
	 */
	private static function new_cache(): Cache {
		return new Cache( HeroReader::GROUP, (bool) wp_using_ext_object_cache() );
	}

	/**
	 * The shared logger.
	 *
	 * @param Container $c Container.
	 * @return Logger
	 */
	private static function logger( Container $c ): Logger {
		$logger = $c->get( Logger::class );

		return $logger instanceof Logger ? $logger : new Logger( null, false );
	}

	/**
	 * The shared page_sections cache.
	 *
	 * @param Container $c Container.
	 * @return Cache
	 */
	private static function shared_cache( Container $c ): Cache {
		$cache = $c->get( self::CACHE_ID );

		return $cache instanceof Cache ? $cache : self::new_cache();
	}

	/**
	 * The repository.
	 *
	 * @param Container $c Container.
	 * @return PageSectionRepository
	 */
	private static function repository( Container $c ): PageSectionRepository {
		$repository = $c->get( PageSectionRepository::class );

		return $repository instanceof PageSectionRepository ? $repository : new PageSectionRepository( self::logger( $c ) );
	}
}
