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

/**
 * Schedules debounced index rebuilds when content changes.
 */
class Scolta_Auto_Rebuild {

	/** Action hook for debounced rebuild. */
	const DEBOUNCE_ACTION = 'scolta_debounced_rebuild';

	/**
	 * Register content-change hooks.
	 *
	 * Hooks are registered unconditionally so init() never reads options:
	 * `scolta_settings` is not autoloaded (it is a growing serialized array
	 * that can hold a legacy API key), and reading it here cost one DB
	 * query on every page view of hosts without a persistent object cache.
	 * The auto_rebuild flag is checked inside the callbacks instead, where
	 * a content change has actually happened.
	 *
	 * @since 0.2.0
	 */
	public static function init(): void {
		add_action( 'save_post', array( __CLASS__, 'on_content_change' ), 20, 2 );
		add_action( 'before_delete_post', array( __CLASS__, 'on_content_change' ), 20 );
		add_action( self::DEBOUNCE_ACTION, array( __CLASS__, 'trigger_rebuild' ) );
	}

	/**
	 * Handle a content change event.
	 *
	 * Bails immediately unless the auto_rebuild setting is enabled (the
	 * hooks themselves are always registered — see init()). Cancels any
	 * pending debounced rebuild and schedules a new one after the
	 * configured delay. Only triggers for indexed post types.
	 *
	 * @since 0.2.0
	 *
	 * @param int           $post_id Post ID.
	 * @param \WP_Post|null $post    Post object (null on before_delete_post).
	 */
	public static function on_content_change( $post_id, $post = null ): void {
		$settings = get_option( 'scolta_settings', array() );
		if ( empty( $settings['auto_rebuild'] ) ) {
			return;
		}

		if ( ! $post ) {
			$post = get_post( $post_id );
		}
		if ( ! $post || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

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
	 * Re-checks the auto_rebuild flag: a debounce event queued while the
	 * feature was enabled can fire after an administrator turns it off,
	 * and disabling auto-rebuild must stop queued rebuilds too. (Before
	 * hooks were registered unconditionally, the same outcome fell out of
	 * the DEBOUNCE_ACTION callback never being attached while disabled.)
	 *
	 * @since 0.2.0
	 */
	public static function trigger_rebuild(): void {
		$settings = get_option( 'scolta_settings', array() );
		if ( empty( $settings['auto_rebuild'] ) ) {
			return;
		}
		Scolta_Rebuild_Scheduler::start_rebuild();
	}
}
