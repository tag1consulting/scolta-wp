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

// Remove index directories from uploads.
$upload_dir = wp_upload_dir();
$scolta_dir = $upload_dir['basedir'] . '/scolta';
if (is_dir($scolta_dir)) {
    // Use WP_Filesystem if available; otherwise fall back to recursive rmdir.
    if (function_exists('WP_Filesystem') && WP_Filesystem()) {
        global $wp_filesystem;
        $wp_filesystem->rmdir($scolta_dir, true);
    } else {
        // Manual recursive removal as a fallback.
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($scolta_dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }
        @rmdir($scolta_dir);
    }
}
