<?php
/**
 * Plugin Name:       QuickCall Connect
 * Plugin URI:        https://github.com/thuexemayhanoi/wp-plugin
 * Description:       Lightweight contact launcher: call, Zalo and map buttons plus optional social, messaging and chatbot links. Vanilla JS, no trackers.
 * Version:           0.1.0
 * Requires at least:  6.0
 * Requires PHP:       7.4
 * Author:             thuexemayhanoi
 * License:            GPL-2.0-or-later
 * License URI:        https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:        quickcall-connect
 * Domain Path:        /languages
 *
 * @package QuickCallConnect
 */

defined( 'ABSPATH' ) || exit;

define( 'QUICKCALL_CONNECT_VERSION', '0.1.0' );
define( 'QUICKCALL_CONNECT_OPTION', 'quickcall_connect_settings' );

require_once __DIR__ . '/includes/class-registry.php';
require_once __DIR__ . '/includes/class-settings.php';
require_once __DIR__ . '/public/class-frontend.php';

if ( is_admin() ) {
	require_once __DIR__ . '/admin/class-settings-page.php';
}

/**
 * Activation: store defaults. Deactivation intentionally keeps settings.
 */
function quickcall_connect_activate() {
	if ( false === get_option( QUICKCALL_CONNECT_OPTION ) ) {
		add_option( QUICKCALL_CONNECT_OPTION, QuickCallConnect\Settings::defaults() );
	}
}
register_activation_hook( __FILE__, 'quickcall_connect_activate' );

/** Load translations. */
function quickcall_connect_init() {
	load_plugin_textdomain( 'quickcall-connect', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'init', 'quickcall_connect_init' );

// Frontend rendering and asset loading.
QuickCallConnect\Frontend::init();

// Admin settings page.
if ( is_admin() ) {
	QuickCallConnect\Settings_Page::init();
}
