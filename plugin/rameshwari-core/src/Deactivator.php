<?php
/**
 * Deactivation.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core;

/**
 * Runs on deactivation. Touches no data: tables, rows, the schema version,
 * roles and capabilities all stay, so re-activating changes nothing.
 *
 * Unscheduling cron, clearing cache groups and flushing rewrites are added
 * by the stages that create them; in Stage 3 none exist yet.
 */
final class Deactivator {

	/**
	 * Deactivation hook.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		// Nothing to undo in Stage 3.
	}
}
