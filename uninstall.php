<?php
/**
 * Scolta uninstall handler.
 *
 * Runs when the user deletes the plugin from the WordPress admin.
 * Removes all plugin data: options, tracker table, and transients.
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

// Remove plugin option.
delete_option('scolta_settings');

// Drop the tracker table.
global $wpdb;
$table = $wpdb->prefix . 'scolta_tracker';
$wpdb->query("DROP TABLE IF EXISTS {$table}");

// Clean up transients.
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '_transient_scolta_expand_%'
        OR option_name LIKE '_transient_timeout_scolta_expand_%'"
);
