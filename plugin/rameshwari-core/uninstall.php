<?php
/**
 * Uninstall entry point. WordPress loads this file alone, without the main
 * plugin file.
 *
 * No owner opt-in mechanism exists until the settings of a later stage, so
 * this removes nothing.
 *
 * @package Rameshwari
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/src/Support/Autoloader.php';

\Rameshwari\Core\Support\Autoloader::register( 'Rameshwari\\Core\\', __DIR__ . '/src/' );

\Rameshwari\Core\Uninstaller::uninstall( false );
