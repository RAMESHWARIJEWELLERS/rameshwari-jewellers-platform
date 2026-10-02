<?php
/**
 * Product module.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Product;

use Rameshwari\Core\Category\AssignmentGuard;
use Rameshwari\Core\Category\CategoryRepository;
use Rameshwari\Core\Category\CategoryRules;
use Rameshwari\Core\Category\ResolverEngine;
use Rameshwari\Core\Container;
use Rameshwari\Core\Data\Meta;
use Rameshwari\Core\Data\PostTypes;
use Rameshwari\Core\Data\Taxonomies;
use Rameshwari\Core\Module;
use Rameshwari\Core\Support\Lock;

/**
 * Registers the product write guard. Registers no post type, taxonomy, meta
 * key, option, route or table: Stage 4 owns those.
 */
final class ProductModule implements Module {

	/**
	 * Module id.
	 *
	 * @return string
	 */
	public static function id(): string {
		return 'product';
	}

	/**
	 * Needs the registries and the category rules.
	 *
	 * @return array<int,string>
	 */
	public static function requires(): array {
		return array( PostTypes::id(), Taxonomies::id(), Meta::id(), CategoryRules::id() );
	}

	/**
	 * Hooks the guard.
	 *
	 * @param Container $container Shared container.
	 * @return void
	 */
	public function register( Container $container ): void {
		ProductGuard::register();
	}

	/**
	 * A service wired to the Stage 5 category contracts.
	 *
	 * @return ProductService
	 */
	public static function service(): ProductService {
		$categories = new CategoryRepository();

		return new ProductService( new ProductRepository(), new AssignmentGuard( $categories, new ResolverEngine( $categories ) ), new Lock() );
	}
}
