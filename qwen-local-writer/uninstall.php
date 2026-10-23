<?php
/**
 * Uninstall: remove only this plugin's settings.
 * Drafts, posts and their _qlw_* meta are editorial content and are never deleted.
 *
 * @package QwenLocalWriter
 */
defined('WP_UNINSTALL_PLUGIN') || exit;

delete_option('qlw_settings');
delete_option('qlw_version');
