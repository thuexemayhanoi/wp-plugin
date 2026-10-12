<?php
/**
 * Plugin Name: Multi AI SEO Content Factory
 * Description: Chiến dịch nội dung SEO, nhập Excel/CSV, hàng đợi an toàn và Claude / Gemini / OpenAI API.
 * Version: 1.1.0
 * Requires at least: 6.9
 * Requires PHP: 8.1
 * Author: Multi AI SEO Content Factory
 * License: GPL-3.0-or-later
 * Text Domain: multi-ai-seo-content-factory
 *
 * @package MultiAISEOContentFactory
 */

defined( 'ABSPATH' ) || exit;
define( 'MASF_VERSION', '1.1.0' );
define( 'MASF_FILE', __FILE__ );
define( 'MASF_DIR', plugin_dir_path( __FILE__ ) );

$masf_missing_extensions = array();
foreach ( array( 'mbstring', 'dom', 'openssl' ) as $masf_extension ) {
	if ( ! extension_loaded( $masf_extension ) ) {
		$masf_missing_extensions[] = $masf_extension; }
}
if ( $masf_missing_extensions ) {
	$masf_requirement_message = 'Multi AI SEO Content Factory cần PHP extensions: ' . implode( ', ', $masf_missing_extensions ) . '. Bật extension trong cấu hình PHP của đúng website.';
	add_action(
		'admin_notices',
		function () use ( $masf_requirement_message ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $masf_requirement_message ) . '</p></div>';
		}
	);
	register_activation_hook(
		__FILE__,
		function () use ( $masf_requirement_message ) {
			wp_die( esc_html( $masf_requirement_message ) );
		}
	);
	return;
}

// Action Scheduler performs its own version negotiation across all plugins.
require_once MASF_DIR . 'vendor/action-scheduler/action-scheduler.php';
foreach ( array( 'store', 'settings', 'providers', 'importer', 'qa', 'engine', 'queue', 'admin' ) as $masf_module ) {
	require_once MASF_DIR . 'includes/class-' . $masf_module . '.php';
}

register_activation_hook( __FILE__, array( '\MASF\Store', 'install' ) );
register_deactivation_hook( __FILE__, array( '\MASF\Queue', 'deactivate' ) );
add_action(
	'plugins_loaded',
	function () {
		if ( get_option( 'masf_version' ) !== MASF_VERSION ) {
			\MASF\Store::install();
		}
		\MASF\Queue::boot();
		\MASF\Admin::boot();
	}
);
