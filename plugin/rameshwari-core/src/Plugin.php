<?php
/**
 * Plugin bootstrap.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core;

use Rameshwari\Core\Support\Logger;

/**
 * Builds the container and registers modules in dependency order.
 */
final class Plugin {

	/**
	 * The booted instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Shared container.
	 *
	 * @var Container
	 */
	private Container $container;

	/**
	 * Entry point, hooked to plugins_loaded. Runs once.
	 *
	 * @return void
	 */
	public static function boot(): void {
		if ( null !== self::$instance ) {
			return;
		}

		$registry = new ModuleRegistry();

		// Stage 4: the core registries. The registry orders them by their requires().
		foreach ( array( Data\PostTypes::class, Data\Taxonomies::class, Data\Options::class, Data\Meta::class, Data\Rewrites::class ) as $module ) {
			$registry->add( $module );
		}

		self::$instance = new self( $registry );
		self::$instance->register_modules();
	}

	/**
	 * The booted instance, or null before plugins_loaded.
	 *
	 * @return Plugin|null
	 */
	public static function instance(): ?Plugin {
		return self::$instance;
	}

	/**
	 * Builds the container. Registers no hooks.
	 *
	 * @param ModuleRegistry $registry Modules to register.
	 */
	public function __construct( private ModuleRegistry $registry ) {
		$this->container = new Container();
		$this->container->set(
			Logger::class,
			static fn(): Logger => new Logger( null, defined( 'WP_DEBUG' ) && WP_DEBUG )
		);
	}

	/**
	 * Registers every module in dependency order.
	 *
	 * A registry error stops all module registration: a half-registered
	 * plugin is harder to diagnose than one that registered nothing.
	 *
	 * @return array<int, string> Ids of the modules registered, in order.
	 */
	public function register_modules(): array {
		/**
		 * The shared logger.
		 *
		 * @var Logger $logger
		 */
		$logger = $this->container->get( Logger::class );

		try {
			$ordered = $this->registry->sorted();
		} catch ( \LogicException $e ) {
			$logger->error( 'Module registry invalid; no modules registered.', array( 'reason' => $e->getMessage() ) );
			return array();
		}

		$registered = array();

		foreach ( $ordered as $module_class ) {
			( new $module_class() )->register( $this->container );
			$registered[] = $module_class::id();
		}

		return $registered;
	}

	/**
	 * The shared container.
	 *
	 * @return Container
	 */
	public function container(): Container {
		return $this->container;
	}
}
