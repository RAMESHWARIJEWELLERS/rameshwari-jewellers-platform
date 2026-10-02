<?php
/**
 * System-write scope for product internals.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Product;

/**
 * Marks code that is allowed to write system-owned product meta (the
 * publication marker) and to clear a losing Product Code. Both the service
 * and the guard depend on this class, so neither depends on the other for it.
 */
final class ProductSystemScope {

	/**
	 * Depth of scopes currently open.
	 *
	 * @var int
	 */
	private static int $depth = 0;

	/**
	 * Runs a callback inside a system scope. The scope always closes.
	 *
	 * @param callable $callback Callback.
	 * @return mixed
	 */
	public static function run( callable $callback ): mixed {
		++self::$depth;

		try {
			return $callback();
		} finally {
			--self::$depth;
		}
	}

	/**
	 * Whether a system scope is open.
	 *
	 * @return bool
	 */
	public static function active(): bool {
		return self::$depth > 0;
	}
}
