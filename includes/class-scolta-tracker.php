<?php
/**
 * Change tracker for Scolta content indexing.
 *
 * WordPress doesn't have an equivalent to Drupal's Search API tracker,
 * so we build one. This is a simple, focused table that records which
 * posts need reindexing and which need removal.
 *
 * Design: WordPress hooks (save_post, before_delete_post,
 * transition_post_status) populate this table. WP-CLI and admin UI
 * consume it. Event-driven, not polling-based.
 */

defined('ABSPATH') || exit;

class Scolta_Tracker {

    /** @var string Table name (without prefix). */
    const TABLE = 'scolta_tracker';

    /**
     * Create the tracker table on activation.
     *
     * Uses dbDelta() — handles creation and upgrades automatically.
     * The UNIQUE KEY on (content_id, content_type) prevents duplicates
     * and enables atomic upserts via $wpdb->replace().
     */
    public static function create_table(): void {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            content_id bigint(20) unsigned NOT NULL,
            content_type varchar(20) NOT NULL DEFAULT 'post',
            action varchar(10) NOT NULL DEFAULT 'index',
            changed_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY content_unique (content_id, content_type),
            KEY action (action)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Record a content change.
     *
     * Uses REPLACE INTO for atomic upsert on the (content_id, content_type)
     * unique key. No race conditions, no lost records on partial failure.
     */
    public static function track(int $content_id, string $content_type, string $action = 'index'): void {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        $wpdb->replace($table, [
            'content_id'   => $content_id,
            'content_type' => $content_type,
            'action'       => $action,
            'changed_at'   => current_time('mysql', true),
        ], ['%d', '%s', '%s', '%s']);
    }

    /**
     * Get all pending records.
     *
     * @return object[] Rows with content_id, content_type, action, changed_at.
     */
    public static function get_pending(?string $action = null): array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        if ($action) {
            return $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table} WHERE action = %s ORDER BY changed_at ASC",
                $action
            ));
        }

        return $wpdb->get_results("SELECT * FROM {$table} ORDER BY changed_at ASC");
    }

    /**
     * Get count of pending items.
     */
    public static function get_pending_count(?string $action = null): int {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        if ($action) {
            return (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE action = %s",
                $action
            ));
        }

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    }

    /**
     * Clear processed records.
     *
     * Called after a successful index build.
     */
    public static function clear(): void {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $wpdb->query("TRUNCATE TABLE {$table}");
    }

    /**
     * Mark all published posts as needing reindex.
     *
     * Used for full rebuilds — populates the tracker with every
     * published post of the configured types.
     */
    public static function mark_all_for_reindex(): int {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        self::clear();

        $settings = get_option('scolta_settings', []);
        $post_types = $settings['post_types'] ?? ['post', 'page'];

        $placeholders = implode(',', array_fill(0, count($post_types), '%s'));
        $query = $wpdb->prepare(
            "INSERT INTO {$table} (content_id, content_type, action, changed_at)
             SELECT ID, post_type, 'index', NOW()
             FROM {$wpdb->posts}
             WHERE post_status = 'publish'
             AND post_type IN ({$placeholders})",
            ...$post_types
        );

        $wpdb->query($query);

        return self::get_pending_count('index');
    }

    /**
     * Check if the tracker table exists.
     */
    public static function table_exists(): bool {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        return $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;
    }
}
