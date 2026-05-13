<?php
/**
 * Scolta uninstall handler.
 *
 * Runs when the user deletes the plugin from the WordPress admin.
 * Removes all plugin data: options, tracker table, and transients.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Remove plugin option.
delete_option( 'scolta_settings' );
delete_option( 'scolta_resolved_prompts' );
delete_option( 'scolta_prompt_cache_version' );
delete_option( 'scolta_generation' );
delete_option( 'scolta_build_status' );
delete_option( 'scolta_build_force' );
delete_option( 'scolta_trust_proxy_headers' );

// Drop the tracker table.
global $wpdb;
$scolta_table = $wpdb->prefix . 'scolta_tracker';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared -- Uninstall cleanup: dropping custom tracker table.
$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $scolta_table ) . '`' );

// Clean up transients.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup: bulk delete transients; cannot use delete_transient() for wildcard patterns.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		'_transient_scolta_%',
		'_transient_timeout_scolta_%'
	)
);

// Remove index directories from uploads.
if ( ! function_exists( 'WP_Filesystem' ) ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
}
WP_Filesystem();
global $wp_filesystem;

$scolta_upload_dir = wp_upload_dir();
$scolta_dir        = $scolta_upload_dir['basedir'] . '/scolta';
if ( $wp_filesystem->exists( $scolta_dir ) ) {
	$wp_filesystem->delete( $scolta_dir, true );
}
