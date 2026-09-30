<?php
/**
 * Migration contract.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Data\Migrations;

/**
 * One numbered schema step. Its number is its key in the Upgrader's list.
 *
 * The up() method must be idempotent: running it against a database it has already
 * brought up to date changes nothing. It throws on failure; the Upgrader
 * then stops and records nothing for it.
 */
interface Migration {

	/**
	 * Applies the migration.
	 *
	 * @return void
	 * @throws \RuntimeException When the migration cannot complete.
	 */
	public function up(): void;
}
