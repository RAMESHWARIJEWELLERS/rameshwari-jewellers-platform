<?php
/**
 * Media module.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Services\Media;

use Rameshwari\Core\Admin\Media\BulkUploader;
use Rameshwari\Core\Container;
use Rameshwari\Core\Cron\MediaAudit;
use Rameshwari\Core\Data\Meta;
use Rameshwari\Core\Data\PostTypes;
use Rameshwari\Core\Module;
use Rameshwari\Core\Product\ProductModule;
use Rameshwari\Core\Support\Logger;

/**
 * Wires the media pipeline into WordPress (blueprint §5).
 *
 * The constructor does nothing. register() stores the container, declares lazy
 * service factories and adds hooks; no service is built until a hook fires.
 * It adds no table, option, capability, meta key or REST route.
 */
final class MediaModule implements Module {

	/**
	 * Cron hook that runs the audit.
	 */
	public const AUDIT_HOOK = 'rj_media_audit';

	/**
	 * Shared container, set by register().
	 *
	 * @var Container|null
	 */
	private ?Container $container = null;

	/**
	 * Module id.
	 *
	 * @return string
	 */
	public static function id(): string {
		return 'media';
	}

	/**
	 * Needs the registered post types and meta keys, and the product module.
	 *
	 * @return array<int,string>
	 */
	public static function requires(): array {
		return array( PostTypes::id(), Meta::id(), ProductModule::id() );
	}

	/**
	 * Declares the services and adds the hooks.
	 *
	 * @param Container $container Shared container.
	 * @return void
	 */
	public function register( Container $container ): void {
		$this->container = $container;

		$container->set( Sizes::class, static fn(): Sizes => new Sizes() );
		$container->set( Formats::class, static fn(): Formats => new Formats() );
		$container->set( RetentionStore::class, static fn(): RetentionStore => new RetentionStore() );
		$container->set(
			ReferenceGuard::class,
			static fn( Container $c ): ReferenceGuard => new ReferenceGuard( new ReferenceSources(), self::logger( $c ) )
		);
		$container->set(
			MediaAudit::class,
			static fn( Container $c ): MediaAudit => new MediaAudit( self::guard( $c ), self::retention( $c ), self::logger( $c ) )
		);
		$container->set(
			BulkUploader::class,
			static fn( Container $c ): BulkUploader => new BulkUploader( new UploadValidator(), new AltText(), self::logger( $c ) )
		);

		add_action( 'init', array( $this, 'register_sizes' ) );
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'convert_formats' ), 10, 2 );
		add_filter( 'pre_delete_attachment', array( $this, 'guard_delete' ), 10, 3 );
		add_action( 'delete_attachment', array( $this, 'purge_artifacts' ), 10, 1 );
		add_action( 'wp_ajax_' . BulkUploader::ACTION, array( $this, 'bulk_upload' ) );
		add_action( self::AUDIT_HOOK, array( $this, 'run_audit' ) );
		add_action( 'admin_init', array( $this, 'ensure_audit_scheduled' ) );
	}

	/**
	 * Registers the five image sizes.
	 *
	 * @return void
	 */
	public function register_sizes(): void {
		$this->service( Sizes::class, Sizes::class )->register();
	}

	/**
	 * Adds modern-format siblings to freshly generated metadata.
	 *
	 * @param mixed $metadata      Attachment metadata.
	 * @param mixed $attachment_id Attachment ID.
	 * @return mixed Metadata, unchanged when it is not an array.
	 */
	public function convert_formats( mixed $metadata, mixed $attachment_id ): mixed {
		if ( ! is_array( $metadata ) || ! is_int( $attachment_id ) ) {
			return $metadata;
		}

		return $this->service( Formats::class, Formats::class )->convert( $metadata, $attachment_id );
	}

	/**
	 * Lets the reference guard decide whether an attachment may be deleted.
	 *
	 * @param mixed $check        Earlier filter result.
	 * @param mixed $post         Attachment post.
	 * @param mixed $force_delete Whether the delete bypasses the trash.
	 * @return mixed
	 */
	public function guard_delete( mixed $check, mixed $post, mixed $force_delete ): mixed {
		return $this->service( ReferenceGuard::class, ReferenceGuard::class )->filter( $check, $post, $force_delete );
	}

	/**
	 * Removes an attachment's retained artifacts once it is hard deleted.
	 *
	 * @param mixed $attachment_id Attachment ID.
	 * @return void
	 */
	public function purge_artifacts( mixed $attachment_id ): void {
		if ( is_int( $attachment_id ) && $attachment_id > 0 ) {
			$this->service( RetentionStore::class, RetentionStore::class )->purge_for( $attachment_id );
		}
	}

	/**
	 * Handles one bulk-upload request.
	 *
	 * @return void
	 */
	public function bulk_upload(): void {
		$this->service( BulkUploader::class, BulkUploader::class )->handle();
	}

	/**
	 * The scheduled audit.
	 *
	 * @return void
	 */
	public function run_audit(): void {
		$this->service( MediaAudit::class, MediaAudit::class )->cron();
	}

	/**
	 * Schedules the daily audit if it is not scheduled yet.
	 *
	 * Runs only in the admin, so the front end never writes the cron option. The audit
	 * is also callable on demand; cron is not trusted for correctness.
	 *
	 * @return void
	 */
	public function ensure_audit_scheduled(): void {
		if ( false === wp_next_scheduled( self::AUDIT_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::AUDIT_HOOK );
		}
	}

	/**
	 * A service from the container.
	 *
	 * @template T of object
	 * @param string $id Service id.
	 * @param string $expected_class Expected class.
	 * @phpstan-param class-string<T> $expected_class
	 * @return T
	 * @throws \LogicException When called before register().
	 */
	private function service( string $id, string $expected_class ): object {
		if ( null === $this->container ) {
			throw new \LogicException( 'The media module is not registered.' );
		}

		$service = $this->container->get( $id );

		if ( ! $service instanceof $expected_class ) {
			throw new \LogicException( 'Unexpected service type.' );
		}

		return $service;
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
	 * The reference guard.
	 *
	 * @param Container $c Container.
	 * @return ReferenceGuard
	 */
	private static function guard( Container $c ): ReferenceGuard {
		$guard = $c->get( ReferenceGuard::class );

		return $guard instanceof ReferenceGuard ? $guard : new ReferenceGuard( new ReferenceSources(), self::logger( $c ) );
	}

	/**
	 * The retention store.
	 *
	 * @param Container $c Container.
	 * @return RetentionStore
	 */
	private static function retention( Container $c ): RetentionStore {
		$store = $c->get( RetentionStore::class );

		return $store instanceof RetentionStore ? $store : new RetentionStore();
	}
}
