<?php
/**
 * Automatic rebuild triggering for Scolta search index.
 *
 * Listens for content changes (post save/delete) and schedules a debounced
 * rebuild via Action Scheduler. The delay prevents rapid-fire rebuilds
 * when multiple posts are saved in quick succession (e.g., bulk edits).
 *
 * @package Scolta
 * @since 0.2.0
 */

defined( 'ABSPATH' ) || exit;

class Scolta_Auto_Rebuild {

	/** Action hook for debounced rebuild. */
	const DEBOUNCE_ACTION = 'scolta_debounced_rebuild';

	/**
	 * Initialize auto-rebuild hooks if enabled in settings.
	 *
	 * Only hooks into content change events when the auto_rebuild setting
	 * is enabled. Requires Action Scheduler to be available.
	 *
	 * @since 0.2.0
	 */
	public static function init(): void {
		$settings = get_option( 'scolta_settings', array() );
		if ( empty( $settings['auto_rebuild'] ) ) {
			return;
		}
		add_action( 'save_post', array( __CLASS__, 'on_content_change' ), 20, 2 );
		add_action( 'before_delete_post', array( __CLASS__, 'on_content_change' ), 20 );
		add_action( self::DEBOUNCE_ACTION, array( __CLASS__, 'trigger_rebuild' ) );
	}

	/**
	 * Handle a content change event.
	 *
	 * Cancels any pending debounced rebuild and schedules a new one after
	 * the configured delay. Only triggers for indexed post types.
	 *
	 * @since 0.2.0
	 *
	 * @param int          $post_id Post ID.
	 * @param \WP_Post|null $post    Post object (null on before_delete_post).
	 */
	public static function on_content_change( $post_id, $post = null ): void {
		if ( ! $post ) {
			$post = get_post( $post_id );
		}
		if ( ! $post || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		$settings      = get_option( 'scolta_settings', array() );
		$indexed_types = $settings['post_types'] ?? array( 'post', 'page' );
		if ( ! in_array( $post->post_type, $indexed_types, true ) ) {
			return;
		}

		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}

		as_unschedule_all_actions( self::DEBOUNCE_ACTION, array(), 'scolta' );
		$delay = max( 60, (int) ( $settings['auto_rebuild_delay'] ?? 300 ) );
		as_schedule_single_action( time() + $delay, self::DEBOUNCE_ACTION, array(), 'scolta' );
	}

	/**
	 * Trigger a rebuild via the scheduler.
	 *
	 * Called by Action Scheduler after the debounce delay has elapsed.
	 *
	 * @since 0.2.0
	 */
	public static function trigger_rebuild(): void {
		Scolta_Rebuild_Scheduler::start_rebuild();
	}
}
