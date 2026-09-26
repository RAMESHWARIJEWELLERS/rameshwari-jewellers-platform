<?php
/**
 * Module contract.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core;

/**
 * One self-contained feature area. Constructors do no work and register()
 * only adds hooks, so load order never changes behaviour.
 */
interface Module {

	/**
	 * Stable identifier, unique across the plugin.
	 *
	 * @return string
	 */
	public static function id(): string;

	/**
	 * Identifiers of the modules this one needs registered first.
	 *
	 * @return array<int, string>
	 */
	public static function requires(): array;

	/**
	 * Adds this module's hooks and services.
	 *
	 * @param Container $container Shared service container.
	 * @return void
	 */
	public function register( Container $container ): void;
}
