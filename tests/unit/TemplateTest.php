<?php
/**
 * Template lookup tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Unit;

use Rameshwari\Core\Support\Template;
use WP_UnitTestCase;

/**
 * Lookup order child theme, parent theme, plugin, against fixture directories.
 */
final class TemplateTest extends WP_UnitTestCase {

	/**
	 * A fixture template directory.
	 *
	 * @param string $name child, parent or plugin.
	 * @return string
	 */
	private function dir( string $name ): string {
		return __DIR__ . '/../fixtures/templates/' . $name;
	}

	/**
	 * All three directories in order.
	 *
	 * @return Template
	 */
	private function full(): Template {
		return new Template( $this->dir( 'child' ), $this->dir( 'parent' ), $this->dir( 'plugin' ) );
	}

	/**
	 * The child theme wins over the parent theme and the plugin.
	 */
	public function test_child_overrides_parent_and_plugin(): void {
		$this->assertSame( 'child:card', $this->full()->render( 'card' ) );
	}

	/**
	 * The parent theme is used when the child lacks the template.
	 */
	public function test_parent_used_when_child_lacks_template(): void {
		$this->assertSame( 'parent:banner', $this->full()->render( 'banner' ) );
	}

	/**
	 * Without a child theme, the parent theme comes first.
	 */
	public function test_works_without_child_theme(): void {
		$template = new Template( '', $this->dir( 'parent' ), $this->dir( 'plugin' ) );

		$this->assertSame( 'parent:card', $template->render( 'card' ) );
	}

	/**
	 * Without a parent theme, the plugin follows the child theme.
	 */
	public function test_works_without_parent_theme(): void {
		$template = new Template( $this->dir( 'child' ), '', $this->dir( 'plugin' ) );

		$this->assertSame( 'plugin:banner', $template->render( 'banner' ) );
	}

	/**
	 * The plugin's template is the final fallback.
	 */
	public function test_plugin_fallback(): void {
		$this->assertSame( 'plugin:footer', $this->full()->render( 'footer' ) );
	}

	/**
	 * A template found nowhere renders nothing.
	 */
	public function test_missing_template(): void {
		$this->assertNull( $this->full()->locate( 'nowhere' ) );
		$this->assertSame( '', $this->full()->render( 'nowhere' ) );
	}

	/**
	 * Names that could leave the template directories are refused.
	 */
	public function test_rejects_traversal(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->full()->locate( '../card' );
	}

	/**
	 * The view-model reaches the template through load_template().
	 */
	public function test_view_model_reaches_template(): void {
		$received = null;
		$capture  = static function ( ...$params ) use ( &$received ): void {
			$received = $params[2] ?? null;
		};

		add_action( 'wp_before_load_template', $capture, 10, 3 );
		$this->full()->render( 'card', array( 'label' => 'Gold' ) );
		remove_action( 'wp_before_load_template', $capture, 10 );

		$this->assertSame( array( 'label' => 'Gold' ), $received );
	}
}
