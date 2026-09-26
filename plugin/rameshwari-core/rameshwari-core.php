<?php
/**
 * Plugin Name:       Rameshwari Core
 * Description:       Business data and logic for the Rameshwari Jewellers platform.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.2
 * Author:            Rameshwari Jewellers
 * Text Domain:       rameshwari
 * Domain Path:       /languages
 * License:           Proprietary
 *
 * This file must stay parseable on old PHP so the version guard can run.
 * No syntax newer than PHP 7.0 belongs here; everything else lives in src/.
 *
 * @package Rameshwari
 */

defined( 'ABSPATH' ) || exit;

define( 'RJ_VERSION', '0.1.0' );
define( 'RJ_DB_VERSION', 0 );
define( 'RJ_FILE', __FILE__ );
define( 'RJ_PATH', plugin_dir_path( __FILE__ ) );
define( 'RJ_URL', plugin_dir_url( __FILE__ ) );
define( 'RJ_MIN_PHP', '8.2' );
define( 'RJ_MIN_WP', '6.5' );
define( 'RJ_TEXTDOMAIN', 'rameshwari' );

/**
 * Lists unmet platform requirements.
 *
 * Returns data, not translated text, so it is safe to call before init.
 *
 * @param string $php_version Running PHP version.
 * @param string $wp_version  Running WordPress version.
 * @return array<int, array{component: string, required: string, current: string}> Empty when every requirement is met.
 */
function rj_requirement_errors( $php_version, $wp_version ) {
	$errors = array();

	if ( version_compare( $php_version, RJ_MIN_PHP, '<' ) ) {
		$errors[] = array(
			'component' => 'PHP',
			'required'  => RJ_MIN_PHP,
			'current'   => $php_version,
		);
	}

	if ( version_compare( $wp_version, RJ_MIN_WP, '<' ) ) {
		$errors[] = array(
			'component' => 'WordPress',
			'required'  => RJ_MIN_WP,
			'current'   => $wp_version,
		);
	}

	return $errors;
}

/**
 * Builds one translated, escaped message per unmet requirement.
 *
 * @return array<int, string> Escaped messages.
 */
function rj_requirement_messages() {
	$messages = array();

	foreach ( rj_requirement_errors( PHP_VERSION, get_bloginfo( 'version' ) ) as $error ) {
		$messages[] = esc_html(
			sprintf(
				/* translators: 1: PHP or WordPress, 2: required version, 3: running version. */
				__( 'Rameshwari Core needs %1$s %2$s or newer. This site runs %3$s, so the plugin has been deactivated.', 'rameshwari' ),
				$error['component'],
				$error['required'],
				$error['current']
			)
		);
	}

	return $messages;
}

/**
 * Prints the requirement notice in the admin.
 *
 * @return void
 */
function rj_render_requirement_notice() {
	foreach ( rj_requirement_messages() as $message ) {
		printf( '<div class="notice notice-error"><p>%s</p></div>', $message ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in rj_requirement_messages().
	}
}

/**
 * Deactivates this plugin when an administrator loads the dashboard below the floors.
 *
 * @return void
 */
function rj_deactivate_self() {
	if ( current_user_can( 'activate_plugins' ) ) {
		deactivate_plugins( plugin_basename( RJ_FILE ) );
	}
}

/**
 * Activation hook. Stops activation below the floors instead of failing later.
 *
 * Stage 1 writes nothing on activation, so deactivation has nothing to undo.
 *
 * @return void
 */
function rj_activate() {
	$messages = rj_requirement_messages();

	if ( empty( $messages ) ) {
		return;
	}

	deactivate_plugins( plugin_basename( RJ_FILE ) );
	wp_die(
		implode( '<br>', $messages ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each message is escaped in rj_requirement_messages().
		esc_html__( 'Plugin activation stopped', 'rameshwari' ),
		array( 'back_link' => true )
	);
}

register_activation_hook( __FILE__, 'rj_activate' );

if ( ! empty( rj_requirement_errors( PHP_VERSION, get_bloginfo( 'version' ) ) ) ) {
	add_action( 'admin_notices', 'rj_render_requirement_notice' );
	add_action( 'admin_init', 'rj_deactivate_self' );
	return;
}

require_once RJ_PATH . 'src/Support/Autoloader.php';

\Rameshwari\Core\Support\Autoloader::register( 'Rameshwari\\Core\\', RJ_PATH . 'src/' );

add_action( 'plugins_loaded', array( \Rameshwari\Core\Plugin::class, 'boot' ) );
