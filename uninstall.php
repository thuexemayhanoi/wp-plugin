<?php
/**
 * QuickCall Connect uninstall.
 *
 * Runs when the plugin is deleted from the Plugins screen. Removes all of
 * this plugin's stored data. Settings are kept on deactivation (so a
 * deactivate/reactivate cycle is lossless) and removed only on deletion.
 *
 * @package QuickCallConnect
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Single-site cleanup. Multisite-wide cleanup is out of scope for v1.
delete_option( 'quickcall_connect_settings' );
