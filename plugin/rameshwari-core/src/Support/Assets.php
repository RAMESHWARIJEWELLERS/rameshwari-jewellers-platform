<?php
/**
 * Asset registration.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Support;

/**
 * Registers scripts and styles once, and enqueues them only where a
 * component renders. Versions come from the file's modification time in
 * development and from the plugin version in production, so caches bust on
 * every release without busting on every page load.
 */
final class Assets {

	/**
	 * Sets up the registrar.
	 *
	 * @param string $base_path   Directory holding the asset files.
	 * @param string $base_url    URL of that directory.
	 * @param string $version     Version used in production.
	 * @param bool   $development Use file modification times as versions.
	 */
	public function __construct(
		private string $base_path,
		private string $base_url,
		private string $version,
		private bool $development
	) {
		$this->base_path = rtrim( $base_path, '/\\' ) . '/';
		$this->base_url  = rtrim( $base_url, '/' ) . '/';
	}

	/**
	 * The registrar for the core plugin's assets directory.
	 *
	 * @return self
	 */
	public static function for_plugin(): self {
		return new self(
			RJ_PATH . 'assets/',
			plugins_url( 'assets/', RJ_FILE ),
			RJ_VERSION,
			defined( 'WP_DEBUG' ) && WP_DEBUG
		);
	}

	/**
	 * Registers a script.
	 *
	 * @param string             $handle    Handle starting with rameshwari-.
	 * @param string             $file      Path relative to the base directory, ending in .js.
	 * @param array<int, string> $deps      Dependency handles.
	 * @param bool               $in_footer Load in the footer.
	 * @return bool
	 */
	public function script( string $handle, string $file, array $deps = array(), bool $in_footer = true ): bool {
		$this->validate( $handle, $file, 'js' );

		return wp_register_script( $handle, $this->base_url . $file, $deps, $this->version_for( $file ), array( 'in_footer' => $in_footer ) );
	}

	/**
	 * Registers a style.
	 *
	 * @param string             $handle Handle starting with rameshwari-.
	 * @param string             $file   Path relative to the base directory, ending in .css.
	 * @param array<int, string> $deps   Dependency handles.
	 * @param string             $media  Media query.
	 * @return bool
	 */
	public function style( string $handle, string $file, array $deps = array(), string $media = 'all' ): bool {
		$this->validate( $handle, $file, 'css' );

		return wp_register_style( $handle, $this->base_url . $file, $deps, $this->version_for( $file ), $media );
	}

	/**
	 * Enqueues a registered script or style.
	 *
	 * @param string $handle Handle.
	 * @return bool False when nothing is registered under the handle.
	 */
	public function enqueue( string $handle ): bool {
		if ( wp_script_is( $handle, 'registered' ) ) {
			wp_enqueue_script( $handle );
			return true;
		}

		if ( wp_style_is( $handle, 'registered' ) ) {
			wp_enqueue_style( $handle );
			return true;
		}

		return false;
	}

	/**
	 * Enqueues only when the condition holds.
	 *
	 * @param string        $handle    Handle.
	 * @param bool|callable $condition A boolean, or a callable returning one.
	 * @return bool Whether the asset was enqueued.
	 */
	public function enqueue_when( string $handle, bool|callable $condition ): bool {
		$wanted = is_callable( $condition ) ? (bool) $condition() : $condition;

		return $wanted && $this->enqueue( $handle );
	}

	/**
	 * Version string for a file.
	 *
	 * @param string $file Relative path.
	 * @return string
	 */
	private function version_for( string $file ): string {
		$path = $this->base_path . $file;

		if ( $this->development && is_readable( $path ) ) {
			$modified = filemtime( $path );

			if ( false !== $modified ) {
				return (string) $modified;
			}
		}

		return $this->version;
	}

	/**
	 * Rejects unsafe handles and paths.
	 *
	 * @param string $handle    Handle.
	 * @param string $file      Relative path.
	 * @param string $extension Required extension.
	 * @return void
	 * @throws \InvalidArgumentException For an invalid handle or path.
	 */
	private function validate( string $handle, string $file, string $extension ): void {
		if ( 1 !== preg_match( '/^rameshwari-[a-z0-9-]+$/', $handle ) ) {
			throw new \InvalidArgumentException( esc_html( 'Invalid asset handle: ' . $handle ) );
		}

		$pattern = '/^[a-z0-9_-][a-z0-9_.-]*(?:\/[a-z0-9_-][a-z0-9_.-]*)*\.' . $extension . '$/';

		if ( str_contains( $file, '..' ) || 1 !== preg_match( $pattern, $file ) ) {
			throw new \InvalidArgumentException( esc_html( 'Invalid asset path: ' . $file ) );
		}
	}
}
