<?php
/**
 * Migration fixtures.
 *
 * @package Rameshwari
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Three tiny fixtures read as one unit.

namespace Rameshwari\Tests\Fixtures\Migrations;

use Rameshwari\Core\Data\Migrations\Migration;

/**
 * Records that it ran.
 */
final class FirstMigration implements Migration {

	/**
	 * Names of the fixtures that ran, in order.
	 *
	 * @var array<int, string>
	 */
	public static array $ran = array();

	/**
	 * Records the run.
	 */
	public function up(): void {
		self::$ran[] = 'first';
	}
}

/**
 * Records that it ran, after FirstMigration.
 */
final class SecondMigration implements Migration {

	/**
	 * Records the run.
	 */
	public function up(): void {
		FirstMigration::$ran[] = 'second';
	}
}

/**
 * Always fails.
 */
final class FailingMigration implements Migration {

	/**
	 * Fails.
	 *
	 * @throws \RuntimeException Always.
	 */
	public function up(): void {
		throw new \RuntimeException( 'Injected failure.' );
	}
}
