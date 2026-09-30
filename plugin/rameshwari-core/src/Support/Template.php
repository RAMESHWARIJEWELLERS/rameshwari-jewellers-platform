<?php
/**
 * Template lookup.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Support;

/**
 * Finds a template in the child theme, then the parent theme, then the
 * plugin, and renders it with a prepared view-model. The view-model reaches
 * the template as $args through WordPress's load_template(). Templates get
 * prepared data, never a raw post.
 */
final class Template {

	/**
	 * Directories searched, in order.
	 *
	 * @var array<int, string>
	 */
	private array $directories = array();

	/**
	 * Sets the lookup order.
	 *
	 * @param string ...$directories Directories in lookup order. Empty and repeated entries are skipped.
	 */
	public function __construct( string ...$directories ) {
		foreach ( $directories as $directory ) {
			if ( '' === $directory ) {
				continue;
			}

			$directory = rtrim( $directory, '/\\' ) . '/';

			if ( ! in_array( $directory, $this->directories, true ) ) {
				$this->directories[] = $directory;
			}
		}
	}

	/**
	 * Child theme, parent theme, then plugin. Without a child theme the first
	 * two are the same directory and it is searched once.
	 *
	 * @return self
	 */
	public static function for_site(): self {
		return new self(
			get_stylesheet_directory() . '/rameshwari/',
			get_template_directory() . '/rameshwari/',
			RJ_PATH . 'templates/'
		);
	}

	/**
	 * Path of the first matching template, or null.
	 *
	 * @param string $name Lowercase name such as parts/card-product, without .php.
	 * @return string|null
	 * @throws \InvalidArgumentException For a name that could leave the template directories.
	 */
	public function locate( string $name ): ?string {
		if ( 1 !== preg_match( '/^[a-z0-9_-]+(?:\/[a-z0-9_-]+)*$/', $name ) ) {
			throw new \InvalidArgumentException( esc_html( 'Invalid template name: ' . $name ) );
		}

		foreach ( $this->directories as $directory ) {
			$file = realpath( $directory . $name . '.php' );
			$base = realpath( $directory );

			if ( false !== $file && false !== $base && str_starts_with( $file, $base . DIRECTORY_SEPARATOR ) && is_readable( $file ) ) {
				return $file;
			}
		}

		return null;
	}

	/**
	 * Renders a template and returns its output.
	 *
	 * @param string               $name Template name.
	 * @param array<string, mixed> $view Prepared view-model.
	 * @return string Empty when no template is found.
	 */
	public function render( string $name, array $view = array() ): string {
		$file = $this->locate( $name );

		if ( null === $file ) {
			return '';
		}

		ob_start();
		load_template( $file, false, $view );

		return (string) ob_get_clean();
	}
}
