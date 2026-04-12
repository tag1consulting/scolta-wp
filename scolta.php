<?php
/**
 * Plugin Name:       Scolta AI Search
 * Plugin URI:        https://www.tag1.com/scolta
 * Description:       Zero-infrastructure AI-powered search. Uses Pagefind for client-side full-text search with AI query expansion, result summarization, and conversational follow-up. Content stays on your server. No Elasticsearch, no Solr, no external search service required.
 * Version:           0.2.0-dev
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Tag1 Consulting
 * Author URI:        https://www.tag1.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       scolta
 * Domain Path:       /languages
 */

defined('ABSPATH') || exit;

define('SCOLTA_VERSION', '0.2.0-dev');
define('SCOLTA_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SCOLTA_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SCOLTA_PLUGIN_FILE', __FILE__);

// Composer autoloader (scolta-php).
$autoloader = SCOLTA_PLUGIN_DIR . 'vendor/autoload.php';
if (file_exists($autoloader)) {
    require_once $autoloader;
}

// Plugin includes.
require_once SCOLTA_PLUGIN_DIR . 'includes/class-scolta-tracker.php';
require_once SCOLTA_PLUGIN_DIR . 'includes/class-scolta-content-source.php';
require_once SCOLTA_PLUGIN_DIR . 'includes/class-scolta-ai-service.php';
require_once SCOLTA_PLUGIN_DIR . 'includes/class-scolta-cache-driver.php';
require_once SCOLTA_PLUGIN_DIR . 'includes/class-scolta-prompt-enricher.php';
require_once SCOLTA_PLUGIN_DIR . 'includes/class-scolta-content-gatherer.php';
require_once SCOLTA_PLUGIN_DIR . 'includes/class-scolta-rest-api.php';
require_once SCOLTA_PLUGIN_DIR . 'includes/class-scolta-shortcode.php';
require_once SCOLTA_PLUGIN_DIR . 'includes/class-scolta-rebuild-scheduler.php';
require_once SCOLTA_PLUGIN_DIR . 'includes/class-scolta-auto-rebuild.php';

// Admin.
if (is_admin()) {
    require_once SCOLTA_PLUGIN_DIR . 'admin/class-scolta-admin.php';
}

// WP-CLI commands.
if (defined('WP_CLI') && WP_CLI) {
    require_once SCOLTA_PLUGIN_DIR . 'cli/class-scolta-cli.php';
}

/**
 * Activation: create tracker table and set default options.
 */
function scolta_activate(): void {
    Scolta_Tracker::create_table();

    $defaults = [
        'ai_provider'               => 'anthropic',
        'ai_model'                  => 'claude-sonnet-4-5-20250929',
        'ai_base_url'               => '',
        'site_name'                 => get_bloginfo('name'),
        'site_description'          => 'website',
        'search_page_path'          => '/scolta-search',
        'pagefind_index_path'       => '/scolta-pagefind',
        'pagefind_binary'           => 'pagefind',
        'build_dir'                 => WP_CONTENT_DIR . '/scolta-build',
        'output_dir'                => ABSPATH . 'scolta-pagefind',
        'indexer'                   => 'auto',
        'auto_rebuild'              => true,
        'post_types'                => ['post', 'page'],
        'cache_ttl'                 => 2592000,
        'max_follow_ups'            => 3,
        'ai_expand_query'           => true,
        'ai_summarize'              => true,
        'ai_languages'              => ['en'],
        // Scoring.
        'title_match_boost'         => 1.0,
        'title_all_terms_multiplier' => 1.5,
        'content_match_boost'       => 0.4,
        'recency_boost_max'         => 0.5,
        'recency_half_life_days'    => 365,
        'recency_penalty_after_days' => 1825,
        'recency_max_penalty'       => 0.3,
        'expand_primary_weight'     => 0.7,
        // Display.
        'excerpt_length'            => 300,
        'results_per_page'          => 10,
        'max_pagefind_results'      => 50,
        'ai_summary_top_n'          => 5,
        'ai_summary_max_chars'      => 2000,
        // Prompt overrides.
        'prompt_expand_query'       => '',
        'prompt_summarize'          => '',
        'prompt_follow_up'          => '',
    ];

    // New installs: set defaults with autoload disabled.
    if (false === get_option('scolta_settings')) {
        add_option('scolta_settings', $defaults, '', false);
    } else {
        // Existing installs: merge in new defaults for added fields,
        // and fix autoload flag.
        $existing = get_option('scolta_settings', []);
        $merged = array_merge($defaults, $existing);
        update_option('scolta_settings', $merged);

        global $wpdb;
        $wpdb->update(
            $wpdb->options,
            ['autoload' => 'no'],
            ['option_name' => 'scolta_settings']
        );
    }

    // Queue initial index build if Action Scheduler is available.
    if (function_exists('as_schedule_single_action')) {
        as_schedule_single_action(time() + 10, 'scolta_rebuild_start', [], 'scolta');
    }

    // Set transient for admin notice.
    set_transient('scolta_activated', true, 60);
}
register_activation_hook(__FILE__, 'scolta_activate');

/**
 * Show one-time admin notice after plugin activation.
 */
add_action('admin_notices', function () {
    if (!get_transient('scolta_activated')) {
        return;
    }
    delete_transient('scolta_activated');
    echo '<div class="notice notice-info"><p>';
    echo 'Scolta activated! Your search index will be built automatically.';
    if (!function_exists('as_schedule_single_action')) {
        echo ' Install Action Scheduler for background indexing, or run <code>wp scolta build</code>.';
    }
    echo ' <a href="' . esc_url(admin_url('options-general.php?page=scolta')) . '">View settings &rarr;</a>';
    echo '</p></div>';
});

/**
 * Deactivation: clean up transients.
 */
function scolta_deactivate(): void {
    // Clean up expand transients.
    global $wpdb;
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
        '_transient_scolta_expand_%',
        '_transient_timeout_scolta_expand_%'
    ));

    // Clear Action Scheduler actions.
    if (function_exists('as_unschedule_all_actions')) {
        as_unschedule_all_actions('scolta_rebuild_start', [], 'scolta');
        as_unschedule_all_actions('scolta_process_chunk', null, 'scolta');
        as_unschedule_all_actions('scolta_finalize_build', [], 'scolta');
        as_unschedule_all_actions('scolta_debounced_rebuild', [], 'scolta');
    }

    // Clear build locks and state.
    delete_transient('scolta_build_lock');
    delete_transient('scolta_build_chunks');
    delete_option('scolta_build_status');
    delete_option('scolta_build_force');
}
register_deactivation_hook(__FILE__, 'scolta_deactivate');

/**
 * Content change tracking via WordPress hooks.
 *
 * This is the WordPress equivalent of Drupal's Search API tracker.
 * WordPress's hook system is one of its great strengths — we lean into it
 * rather than polling or scanning files.
 */
add_action('save_post', function (int $post_id, \WP_Post $post, bool $update): void {
    $settings = get_option('scolta_settings', []);
    $tracked_types = $settings['post_types'] ?? ['post', 'page'];

    if (!in_array($post->post_type, $tracked_types, true)) {
        return;
    }

    // Skip autosaves and revisions.
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
        return;
    }

    if ($post->post_status === 'publish') {
        Scolta_Tracker::track($post_id, $post->post_type, 'index');
    } else {
        // Unpublished/drafted — remove from index.
        Scolta_Tracker::track($post_id, $post->post_type, 'delete');
    }
}, 10, 3);

add_action('before_delete_post', function (int $post_id): void {
    $post = get_post($post_id);
    if (!$post) {
        return;
    }
    $settings = get_option('scolta_settings', []);
    $tracked_types = $settings['post_types'] ?? ['post', 'page'];

    if (in_array($post->post_type, $tracked_types, true)) {
        Scolta_Tracker::track($post_id, $post->post_type, 'delete');
    }
});

/**
 * Transition hook — catches publish↔draft transitions that save_post
 * doesn't always fire for (e.g., Quick Edit, bulk actions).
 */
add_action('transition_post_status', function (string $new_status, string $old_status, \WP_Post $post): void {
    if ($new_status === $old_status) {
        return;
    }

    $settings = get_option('scolta_settings', []);
    $tracked_types = $settings['post_types'] ?? ['post', 'page'];

    if (!in_array($post->post_type, $tracked_types, true)) {
        return;
    }

    if ($new_status === 'publish') {
        Scolta_Tracker::track($post->ID, $post->post_type, 'index');
    } elseif ($old_status === 'publish') {
        // Was published, now isn't — remove from index.
        Scolta_Tracker::track($post->ID, $post->post_type, 'delete');
    }
}, 10, 3);

/**
 * Cache resolved prompts when settings are saved.
 *
 * DefaultPrompts::resolve() is pure PHP (no WASM), so this is cheap.
 * Caching avoids repeated string replacement on every AI request.
 */
add_action('update_option_scolta_settings', function ($old, $new): void {
    $site_name = $new['site_name'] ?? get_bloginfo('name');
    $site_desc = $new['site_description'] ?? 'website';

    $all = [];
    foreach (['expand_query', 'summarize', 'follow_up'] as $name) {
        $all[$name] = \Tag1\Scolta\Prompt\DefaultPrompts::resolve(
            $name,
            $site_name,
            $site_desc,
        );
    }
    update_option('scolta_resolved_prompts', $all);
}, 10, 2);

/**
 * REST API registration.
 */
add_action('rest_api_init', function (): void {
    Scolta_Rest_Api::register_routes();
});

/**
 * Register shortcode for embedding the search UI.
 */
add_action('init', function (): void {
    Scolta_Shortcode::register();
});

/**
 * Initialize background rebuild scheduler and auto-rebuild hooks.
 */
add_action('init', ['Scolta_Rebuild_Scheduler', 'init']);
add_action('init', ['Scolta_Auto_Rebuild', 'init']);
