<?php
/**
 * Media module tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Integration\Media;

use Rameshwari\Core\Admin\Media\BulkUploader;
use Rameshwari\Core\Category\CategoryPickerBox;
use Rameshwari\Core\Category\CategoryRules;
use Rameshwari\Core\Container;
use Rameshwari\Core\Cron\MediaAudit;
use Rameshwari\Core\Data\Meta;
use Rameshwari\Core\Data\Options;
use Rameshwari\Core\Data\PostTypes;
use Rameshwari\Core\Data\Rewrites;
use Rameshwari\Core\Data\Taxonomies;
use Rameshwari\Core\Module;
use Rameshwari\Core\ModuleRegistry;
use Rameshwari\Core\Plugin;
use Rameshwari\Core\Product\ProductModule;
use Rameshwari\Core\Services\Media\Formats;
use Rameshwari\Core\Services\Media\MediaModule;
use Rameshwari\Core\Services\Media\ReferenceGuard;
use Rameshwari\Core\Services\Media\RetentionStore;
use Rameshwari\Core\Services\Media\Sizes;
use Rameshwari\Core\Support\Logger;

/**
 * The media module's contract, ordering, hooks and boot path.
 */
final class MediaModuleTest extends \WP_UnitTestCase {

	/**
	 * Hooks added by the module under test.
	 *
	 * @var array<int,array{0:string,1:string,2:int}>
	 */
	private const HOOKS = array(
		array( 'init', 'register_sizes', 10 ),
		array( 'wp_generate_attachment_metadata', 'convert_formats', 10 ),
		array( 'pre_delete_attachment', 'guard_delete', 10 ),
		array( 'delete_attachment', 'purge_artifacts', 10 ),
		array( 'wp_ajax_rj_media_upload', 'bulk_upload', 10 ),
		array( MediaModule::AUDIT_HOOK, 'run_audit', 10 ),
		array( 'admin_init', 'ensure_audit_scheduled', 10 ),
	);

	/**
	 * Module instances to unhook after each test.
	 *
	 * @var array<int,MediaModule>
	 */
	private array $modules = array();

	/**
	 * Removes the hooks and the schedule this test added.
	 */
	public function tear_down(): void {
		foreach ( $this->modules as $module ) {
			foreach ( self::HOOKS as $hook ) {
				remove_filter( $hook[0], array( $module, $hook[1] ), $hook[2] );
			}
		}

		wp_clear_scheduled_hook( MediaModule::AUDIT_HOOK );

		parent::tear_down();
	}

	/**
	 * A container with a logger, as Plugin builds it.
	 *
	 * @return Container
	 */
	private function container(): Container {
		$container = new Container();

		$container->set( Logger::class, static fn(): Logger => new Logger( null, false ) );

		return $container;
	}

	/**
	 * A registered module that will be unhooked afterwards.
	 *
	 * @param Container $container Container to register into.
	 * @return MediaModule
	 */
	private function registered( Container $container ): MediaModule {
		$module          = new MediaModule();
		$this->modules[] = $module;

		$module->register( $container );

		return $module;
	}

	/**
	 * How many callbacks of a module sit on a hook.
	 *
	 * @param string      $hook   Hook name.
	 * @param MediaModule $module Module instance.
	 * @return int
	 */
	private function callbacks_on( string $hook, MediaModule $module ): int {
		global $wp_filter;

		$count = 0;

		if ( ! isset( $wp_filter[ $hook ] ) ) {
			return 0;
		}

		foreach ( $wp_filter[ $hook ]->callbacks as $priority ) {
			foreach ( $priority as $entry ) {
				if ( is_array( $entry['function'] ) && $entry['function'][0] === $module ) {
					++$count;
				}
			}
		}

		return $count;
	}

	/**
	 * It is a module.
	 */
	public function test_satisfies_the_module_contract(): void {
		$this->assertInstanceOf( Module::class, new MediaModule() );
		$this->assertNull( ( new \ReflectionClass( MediaModule::class ) )->getConstructor() );
	}

	/**
	 * Its id is exactly media.
	 */
	public function test_id_is_media(): void {
		$this->assertSame( 'media', MediaModule::id() );
	}

	/**
	 * It requires the registries and the product module, and nothing from Stage 5.
	 */
	public function test_requires_the_documented_modules(): void {
		$this->assertSame( array( PostTypes::id(), Meta::id(), ProductModule::id() ), MediaModule::requires() );
		$this->assertNotContains( CategoryRules::id(), MediaModule::requires() );
	}

	/**
	 * The registry puts media after every module it needs.
	 */
	public function test_registry_orders_media_after_its_dependencies(): void {
		$registry = new ModuleRegistry();

		foreach ( array( MediaModule::class, ProductModule::class, CategoryPickerBox::class, Rewrites::class, CategoryRules::class, Meta::class, Options::class, Taxonomies::class, PostTypes::class ) as $module_class ) {
			$registry->add( $module_class );
		}

		$ids = array_map( static fn( string $module_class ): string => $module_class::id(), $registry->sorted() );

		$this->assertCount( 9, $ids );

		foreach ( MediaModule::requires() as $needed ) {
			$this->assertLessThan( array_search( 'media', $ids, true ), array_search( $needed, $ids, true ), $needed );
		}

		$this->assertGreaterThan( array_search( ProductModule::id(), $ids, true ), array_search( 'media', $ids, true ) );
	}

	/**
	 * The real boot path registers the media module.
	 */
	public function test_plugin_boot_registers_the_media_module(): void {
		Plugin::boot();

		$plugin = Plugin::instance();

		$this->assertNotNull( $plugin );

		foreach ( array( Sizes::class, Formats::class, RetentionStore::class, ReferenceGuard::class, MediaAudit::class, BulkUploader::class ) as $service ) {
			$this->assertTrue( $plugin->container()->has( $service ), $service );
		}

		$this->assertNotFalse( has_filter( 'pre_delete_attachment' ) );
		$this->assertNotFalse( has_action( 'wp_ajax_' . BulkUploader::ACTION ) );
	}

	/**
	 * Existing registrations are unaffected.
	 */
	public function test_existing_modules_remain_intact(): void {
		Plugin::boot();

		foreach ( array( 'rj_product', 'rj_reel', 'rj_collection', 'rj_showroom', 'rj_testimonial', 'rj_page_section' ) as $type ) {
			$this->assertTrue( post_type_exists( $type ), $type );
		}

		$this->assertTrue( taxonomy_exists( 'rj_category' ) );
		$this->assertNotNull( Plugin::instance()->container()->get( Logger::class ) );
	}

	/**
	 * Each hook is added once, with its documented priority, and calling register again adds none.
	 */
	public function test_hooks_are_registered_once(): void {
		$module = $this->registered( $this->container() );

		$module->register( $this->container() );

		foreach ( self::HOOKS as $hook ) {
			$this->assertSame( $hook[2], has_filter( $hook[0], array( $module, $hook[1] ) ), $hook[0] );
			$this->assertSame( 1, $this->callbacks_on( $hook[0], $module ), $hook[0] );
		}

		$this->assertFalse( has_action( 'wp_ajax_nopriv_' . BulkUploader::ACTION ) );
	}

	/**
	 * The audit is scheduled from admin_init only, never by register(), and only once.
	 */
	public function test_audit_is_scheduled_lazily_and_once(): void {
		wp_clear_scheduled_hook( MediaModule::AUDIT_HOOK );

		$module = $this->registered( $this->container() );

		$this->assertFalse( wp_next_scheduled( MediaModule::AUDIT_HOOK ) );

		$module->ensure_audit_scheduled();

		$first = (int) wp_next_scheduled( MediaModule::AUDIT_HOOK );

		$this->assertGreaterThan( 0, $first );

		$module->ensure_audit_scheduled();

		$second = (int) wp_next_scheduled( MediaModule::AUDIT_HOOK );

		$this->assertSame( $first, $second );
		$this->assertSame( 'daily', wp_get_schedule( MediaModule::AUDIT_HOOK ) );
	}

	/**
	 * Stage 7 adds no media REST route.
	 */
	public function test_no_media_rest_routes(): void {
		Plugin::boot();

		foreach ( array_keys( rest_get_server()->get_routes() ) as $route ) {
			$this->assertFalse( str_starts_with( $route, '/rj/' ) && false !== stripos( $route, 'media' ), $route );
		}
	}

	/**
	 * The delete guard hook delegates to the reference guard: free attachments pass, referenced ones are blocked.
	 */
	public function test_guard_delete_delegates_to_the_reference_guard(): void {
		$module = $this->registered( $this->container() );
		$free   = (int) wp_insert_attachment(
			array(
				'post_mime_type' => 'image/png',
				'post_title'     => 'Free',
				'post_status'    => 'inherit',
			)
		);
		$used   = (int) wp_insert_attachment(
			array(
				'post_mime_type' => 'image/png',
				'post_title'     => 'Used',
				'post_status'    => 'inherit',
			)
		);
		$owner  = self::factory()->post->create();

		// WordPress only accepts an attachment as a featured image when it has an image file name.
		update_attached_file( $used, 'rjfixture-' . $used . '.png' );

		$this->assertNotFalse( set_post_thumbnail( $owner, $used ) );
		$this->assertSame( $used, (int) get_post_meta( $owner, '_thumbnail_id', true ) );

		$this->assertNull( $module->guard_delete( null, get_post( $free ), true ) );
		$this->assertFalse( $module->guard_delete( null, get_post( $used ), true ) );
	}

	/**
	 * The artifact clean-up hook ignores anything that is not a positive ID.
	 */
	public function test_purge_hook_ignores_bad_input(): void {
		$this->expectNotToPerformAssertions();

		$module = $this->registered( $this->container() );

		$module->purge_artifacts( 0 );
		$module->purge_artifacts( 'x' );
		$module->purge_artifacts( null );
	}

	/**
	 * Using a hook method before register() is a clear logic error, not a fatal.
	 */
	public function test_unregistered_module_refuses_to_run_services(): void {
		$this->expectException( \LogicException::class );

		( new MediaModule() )->register_sizes();
	}
}
