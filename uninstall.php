<?php
/**
 * Scolta uninstall handler.
 *
 * Runs when the user deletes the plugin from the WordPress admin.
 * Removes all plugin data: options, tracker table, and transients.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Remove plugin options.
delete_option( 'scolta_settings' );
delete_option( 'scolta_resolved_prompts' );
delete_option( 'scolta_prompt_cache_version' );
delete_option( 'scolta_generation' );
delete_option( 'scolta_build_status' );
delete_option( 'scolta_build_force' );
delete_option( 'scolta_trust_proxy_headers' );
delete_option( 'scolta_amazee_credentials' );

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

// Clean up user meta for all users.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup: bulk delete user meta; no single-user API for wildcard deletion.
$wpdb->query(
	"DELETE FROM {$wpdb->usermeta} WHERE meta_key IN"
	. " ('scolta_dismissed_rebuild_notice', 'scolta_amazee_flow')"
);

// Clear Action Scheduler actions (if available).
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'scolta_rebuild_start', array(), 'scolta' );
	as_unschedule_all_actions( 'scolta_process_chunk', null, 'scolta' );
	as_unschedule_all_actions( 'scolta_finalize_build', array(), 'scolta' );
	as_unschedule_all_actions( 'scolta_debounced_rebuild', array(), 'scolta' );
	as_unschedule_all_actions( 'scolta_amazee_provision', array(), 'scolta' );
}

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
