<?php
/**
 * Deactivation.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core;

use Rameshwari\Core\Data\Rewrites;

/**
 * Runs on deactivation. Touches no data: tables, rows, the schema version,
 * roles and capabilities all stay, so re-activating changes nothing.
 *
 * Stage 4 unregisters the post types, taxonomies and rewrite rules and
 * rebuilds the rules without them. Posts and terms stay in the database and
 * reappear on reactivation. Unscheduling cron and clearing cache groups are
 * added by the stages that create them.
 */
final class Deactivator {

	/**
	 * Deactivation hook.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		Rewrites::remove();
	}
}
