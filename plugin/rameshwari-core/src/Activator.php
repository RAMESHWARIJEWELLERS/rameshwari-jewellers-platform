<?php
/**
 * Activation.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core;

use Rameshwari\Core\Support\Logger;

/**
 * Runs on activation, after the requirement guard has passed.
 *
 * Stage 3 creates the tables, writes the schema version and applies the
 * roles and capabilities. Seeding the root categories and default
 * settings, scheduling cron and flushing rewrites belong to the stages that
 * register those things. Safe to run again: every step is idempotent.
 */
final class Activator {

	/**
	 * Activation hook.
	 *
	 * @return void
	 */
	public static function activate(): void {
		$ok = Installer::install( Installer::ACTIVATION );

		Installer::logger()->log(
			$ok ? Logger::INFO : Logger::ERROR,
			$ok ? 'Plugin activated; schema current.' : 'Plugin activated but the schema is not current.',
			array( 'version' => Upgrader::stored_version() )
		);
	}
}
