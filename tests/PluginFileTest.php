<?php

declare(strict_types=1);

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Tests for the main plugin file (scolta.php) structure.
 */
class PluginFileTest extends TestCase {

    protected function set_up(): void {
        $GLOBALS['wp_options'] = [];
    }

    // -------------------------------------------------------------------
    // Constants
    // -------------------------------------------------------------------

    public function test_scolta_version_defined(): void {
        $this->assertTrue(defined('SCOLTA_VERSION'));
    }

    public function test_scolta_version_is_string(): void {
        $this->assertIsString(SCOLTA_VERSION);
    }

    public function test_scolta_version_format(): void {
        // Version should be semver (possibly with -dev suffix).
        $this->assertMatchesRegularExpression(
            '/^\d+\.\d+\.\d+(-\w+)?$/',
            SCOLTA_VERSION
        );
    }

    public function test_scolta_plugin_dir_defined(): void {
        $this->assertTrue(defined('SCOLTA_PLUGIN_DIR'));
    }

    public function test_scolta_plugin_dir_ends_with_slash(): void {
        $this->assertStringEndsWith('/', SCOLTA_PLUGIN_DIR);
    }

    public function test_scolta_plugin_url_defined(): void {
        $this->assertTrue(defined('SCOLTA_PLUGIN_URL'));
    }

    public function test_scolta_plugin_file_defined(): void {
        $this->assertTrue(defined('SCOLTA_PLUGIN_FILE'));
    }

    public function test_scolta_plugin_file_points_to_scolta_php(): void {
        $this->assertStringEndsWith('scolta.php', SCOLTA_PLUGIN_FILE);
    }

    // -------------------------------------------------------------------
    // Activation hook
    // -------------------------------------------------------------------

    public function test_scolta_activate_function_exists(): void {
        $this->assertTrue(function_exists('scolta_activate'));
    }

    public function test_scolta_deactivate_function_exists(): void {
        $this->assertTrue(function_exists('scolta_deactivate'));
    }

    public function test_activation_hook_registered_in_source(): void {
        $content = file_get_contents(dirname(__DIR__) . '/scolta.php');
        $this->assertStringContainsString(
            "register_activation_hook(__FILE__, 'scolta_activate')",
            $content
        );
    }

    public function test_deactivation_hook_registered_in_source(): void {
        $content = file_get_contents(dirname(__DIR__) . '/scolta.php');
        $this->assertStringContainsString(
            "register_deactivation_hook(__FILE__, 'scolta_deactivate')",
            $content
        );
    }

    // -------------------------------------------------------------------
    // Activation sets default options
    // -------------------------------------------------------------------

    public function test_activation_sets_default_options(): void {
        scolta_activate();
        $settings = get_option('scolta_settings');
        $this->assertIsArray($settings);
        $this->assertNotEmpty($settings);
    }

    public function test_default_options_include_ai_provider(): void {
        scolta_activate();
        $settings = get_option('scolta_settings');
        $this->assertArrayHasKey('ai_provider', $settings);
        $this->assertEquals('anthropic', $settings['ai_provider']);
    }

    public function test_default_options_include_ai_model(): void {
        scolta_activate();
        $settings = get_option('scolta_settings');
        $this->assertArrayHasKey('ai_model', $settings);
    }

    public function test_default_options_include_post_types(): void {
        scolta_activate();
        $settings = get_option('scolta_settings');
        $this->assertArrayHasKey('post_types', $settings);
        $this->assertEquals(['post', 'page'], $settings['post_types']);
    }

    public function test_default_options_include_cache_ttl(): void {
        scolta_activate();
        $settings = get_option('scolta_settings');
        $this->assertArrayHasKey('cache_ttl', $settings);
    }

    public function test_default_options_include_max_follow_ups(): void {
        scolta_activate();
        $settings = get_option('scolta_settings');
        $this->assertArrayHasKey('max_follow_ups', $settings);
    }

    public function test_default_options_include_ai_expand_query(): void {
        scolta_activate();
        $settings = get_option('scolta_settings');
        $this->assertArrayHasKey('ai_expand_query', $settings);
    }

    public function test_default_options_include_ai_summarize(): void {
        scolta_activate();
        $settings = get_option('scolta_settings');
        $this->assertArrayHasKey('ai_summarize', $settings);
    }

    public function test_default_options_include_site_description(): void {
        scolta_activate();
        $settings = get_option('scolta_settings');
        $this->assertArrayHasKey('site_description', $settings);
    }

    public function test_default_options_include_scoring_fields(): void {
        scolta_activate();
        $settings = get_option('scolta_settings');

        $scoring_keys = [
            'title_match_boost',
            'title_all_terms_multiplier',
            'content_match_boost',
            'recency_boost_max',
            'recency_half_life_days',
            'recency_penalty_after_days',
            'recency_max_penalty',
            'expand_primary_weight',
        ];
        foreach ($scoring_keys as $key) {
            $this->assertArrayHasKey($key, $settings, "Missing scoring key: {$key}");
        }
    }

    public function test_default_options_include_display_fields(): void {
        scolta_activate();
        $settings = get_option('scolta_settings');

        $display_keys = [
            'excerpt_length',
            'results_per_page',
            'max_pagefind_results',
            'ai_summary_top_n',
            'ai_summary_max_chars',
        ];
        foreach ($display_keys as $key) {
            $this->assertArrayHasKey($key, $settings, "Missing display key: {$key}");
        }
    }

    public function test_default_options_include_prompt_overrides(): void {
        scolta_activate();
        $settings = get_option('scolta_settings');

        $this->assertArrayHasKey('prompt_expand_query', $settings);
        $this->assertArrayHasKey('prompt_summarize', $settings);
        $this->assertArrayHasKey('prompt_follow_up', $settings);
    }

    public function test_default_options_include_path_settings(): void {
        scolta_activate();
        $settings = get_option('scolta_settings');

        $this->assertArrayHasKey('search_page_path', $settings);
        $this->assertArrayHasKey('pagefind_index_path', $settings);
        $this->assertArrayHasKey('build_dir', $settings);
        $this->assertArrayHasKey('output_dir', $settings);
    }

    // -------------------------------------------------------------------
    // Plugin file includes
    // -------------------------------------------------------------------

    public function test_plugin_file_includes_tracker(): void {
        $content = file_get_contents(dirname(__DIR__) . '/scolta.php');
        $this->assertStringContainsString('class-scolta-tracker.php', $content);
    }

    public function test_plugin_file_includes_content_source(): void {
        $content = file_get_contents(dirname(__DIR__) . '/scolta.php');
        $this->assertStringContainsString('class-scolta-content-source.php', $content);
    }

    public function test_plugin_file_includes_ai_service(): void {
        $content = file_get_contents(dirname(__DIR__) . '/scolta.php');
        $this->assertStringContainsString('class-scolta-ai-service.php', $content);
    }

    public function test_plugin_file_includes_rest_api(): void {
        $content = file_get_contents(dirname(__DIR__) . '/scolta.php');
        $this->assertStringContainsString('class-scolta-rest-api.php', $content);
    }

    public function test_plugin_file_includes_shortcode(): void {
        $content = file_get_contents(dirname(__DIR__) . '/scolta.php');
        $this->assertStringContainsString('class-scolta-shortcode.php', $content);
    }

    // -------------------------------------------------------------------
    // Version matches plugin header
    // -------------------------------------------------------------------

    public function test_version_constant_matches_plugin_header(): void {
        $content = file_get_contents(dirname(__DIR__) . '/scolta.php');
        preg_match('/Version:\s+(.+)$/m', $content, $matches);
        $header_version = trim($matches[1] ?? '');

        $this->assertEquals(SCOLTA_VERSION, $header_version);
    }
}
