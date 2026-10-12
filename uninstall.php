<?php
/** Retain user campaigns, settings and posts on uninstall; explicit cleanup is not provided. *
 *
 * @package MultiAISEOContentFactory
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
// Deactivation has already paused and canceled this plugin's jobs.
// Deliberately preserve all user data, shared Action Scheduler tables and WordPress posts.
