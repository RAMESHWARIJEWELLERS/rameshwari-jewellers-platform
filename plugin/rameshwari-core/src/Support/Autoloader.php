<?php
/**
 * Bundled PSR-4 autoloader.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Support;

/**
 * Maps one namespace prefix to one directory. Composer is optional because
 * some shared hosts block it, so the plugin ships its own loader.
 */
final class Autoloader {

	/**
	 * Registers a loader for a namespace prefix.
	 *
	 * @param string $prefix   Namespace prefix, ending in a backslash.
	 * @param string $base_dir Directory holding the prefix's classes.
	 * @return void
	 */
	public static function register( string $prefix, string $base_dir ): void {
		$loader = new self( $prefix, rtrim( $base_dir, '/\\' ) . '/' );
		spl_autoload_register( array( $loader, 'load' ) );
	}

	/**
	 * Stores the mapping. Does no other work.
	 *
	 * @param string $prefix   Namespace prefix.
	 * @param string $base_dir Directory with a trailing slash.
	 */
	private function __construct(
		private string $prefix,
		private string $base_dir
	) {}

	/**
	 * Loads a class if it belongs to this prefix.
	 *
	 * Each namespace segment must be letters, digits or underscores, so a
	 * class name can never produce a path outside the base directory.
	 *
	 * @param string $class_name Fully qualified class name.
	 * @return void
	 */
	public function load( string $class_name ): void {
		if ( ! str_starts_with( $class_name, $this->prefix ) ) {
			return;
		}

		$segments = explode( '\\', substr( $class_name, strlen( $this->prefix ) ) );

		foreach ( $segments as $segment ) {
			if ( 1 !== preg_match( '/^[A-Za-z0-9_]+$/', $segment ) ) {
				return;
			}
		}

		$file = $this->base_dir . implode( '/', $segments ) . '.php';

		if ( is_readable( $file ) ) {
			require $file;
		}
	}
}
