<?php
/**
 * Content gatherer for the PHP indexer pipeline.
 *
 * Queries WordPress for published posts of configured types and
 * converts them to ContentItem objects that the PHP indexer can process.
 * This replaces the mark-export-pagefind pipeline when using the PHP
 * indexer path.
 */

defined('ABSPATH') || exit;

use Tag1\Scolta\Export\ContentItem;

class Scolta_Content_Gatherer {

    /**
     * Gather all published content as ContentItem objects.
     *
     * Queries published posts of the configured post types and creates
     * ContentItem instances suitable for the PHP indexer pipeline.
     *
     * @return ContentItem[] Array of content items.
     */
    public static function gather(): array {
        $settings = get_option('scolta_settings', []);
        $post_types = $settings['post_types'] ?? ['post', 'page'];
        $site_name = $settings['site_name'] ?? get_bloginfo('name');

        $items = [];
        $query = new \WP_Query([
            'post_type' => $post_types,
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'no_found_rows' => true,
        ]);

        foreach ($query->posts as $post) {
            $items[] = new ContentItem(
                id: 'post-' . $post->ID,
                title: $post->post_title,
                bodyHtml: apply_filters('the_content', $post->post_content),
                url: get_permalink($post),
                date: get_the_date('Y-m-d', $post),
                siteName: $site_name,
            );
        }

        return $items;
    }
}
