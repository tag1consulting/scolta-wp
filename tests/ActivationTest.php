<?php

declare(strict_types=1);

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Tests for plugin activation, deactivation, and default options.
 */
class ActivationTest extends TestCase {

    protected function set_up(): void {
        $GLOBALS['wp_options'] = [];
    }

    public function test_activation_creates_default_options(): void {
        scolta_activate();

        $settings = get_option('scolta_settings');
        $this->assertIsArray($settings);
    }

    public function test_activation_sets_correct_defaults(): void {
        scolta_activate();

        $settings = get_option('scolta_settings');
        $this->assertEquals('anthropic', $settings['ai_provider']);
        $this->assertEquals('claude-sonnet-4-5-20250929', $settings['ai_model']);
        $this->assertEquals('', $settings['ai_base_url']);
        $this->assertEquals('website', $settings['site_description']);
        $this->assertEquals(['post', 'page'], $settings['post_types']);
        $this->assertTrue($settings['ai_expand_query']);
        $this->assertTrue($settings['ai_summarize']);
        $this->assertEquals(['en'], $settings['ai_languages']);
        $this->assertEquals(3, $settings['max_follow_ups']);
        $this->assertEquals(2592000, $settings['cache_ttl']);
    }

    public function test_activation_sets_scoring_defaults(): void {
        scolta_activate();

        $settings = get_option('scolta_settings');
        $this->assertEquals(1.0, $settings['title_match_boost']);
        $this->assertEquals(1.5, $settings['title_all_terms_multiplier']);
        $this->assertEquals(0.4, $settings['content_match_boost']);
        $this->assertEquals(0.5, $settings['recency_boost_max']);
        $this->assertEquals(365, $settings['recency_half_life_days']);
        $this->assertEquals(1825, $settings['recency_penalty_after_days']);
        $this->assertEquals(0.3, $settings['recency_max_penalty']);
        $this->assertEquals(0.7, $settings['expand_primary_weight']);
    }

    public function test_activation_sets_display_defaults(): void {
        scolta_activate();

        $settings = get_option('scolta_settings');
        $this->assertEquals(300, $settings['excerpt_length']);
        $this->assertEquals(10, $settings['results_per_page']);
        $this->assertEquals(50, $settings['max_pagefind_results']);
        $this->assertEquals(5, $settings['ai_summary_top_n']);
        $this->assertEquals(2000, $settings['ai_summary_max_chars']);
    }

    public function test_activation_sets_empty_prompt_overrides(): void {
        scolta_activate();

        $settings = get_option('scolta_settings');
        $this->assertEquals('', $settings['prompt_expand_query']);
        $this->assertEquals('', $settings['prompt_summarize']);
        $this->assertEquals('', $settings['prompt_follow_up']);
    }

    public function test_activation_merges_with_existing_settings(): void {
        // Simulate existing install with partial settings.
        update_option('scolta_settings', [
            'ai_provider' => 'openai',
            'custom_setting' => 'preserved',
        ]);

        scolta_activate();

        $settings = get_option('scolta_settings');
        // Existing values preserved.
        $this->assertEquals('openai', $settings['ai_provider']);
        $this->assertEquals('preserved', $settings['custom_setting']);
        // New defaults merged in.
        $this->assertArrayHasKey('max_follow_ups', $settings);
    }

    // -------------------------------------------------------------------
    // Old-path migration (managed hosting fix)
    // -------------------------------------------------------------------

    public function test_activation_migrates_old_build_dir_default(): void {
        // Simulate an existing install using the pre-managed-hosting default.
        $old_build = WP_CONTENT_DIR . '/scolta-build';
        update_option('scolta_settings', ['build_dir' => $old_build]);

        scolta_activate();

        $settings = get_option('scolta_settings');
        $expected = wp_upload_dir()['basedir'] . '/scolta/build';
        $this->assertEquals($expected, $settings['build_dir'],
            'build_dir with old WP_CONTENT_DIR default should be migrated to uploads path');
    }

    public function test_activation_migrates_old_output_dir_default(): void {
        // Simulate an existing install using the pre-managed-hosting default.
        $old_output = ABSPATH . 'scolta-pagefind';
        update_option('scolta_settings', ['output_dir' => $old_output]);

        scolta_activate();

        $settings = get_option('scolta_settings');
        $expected = wp_upload_dir()['basedir'] . '/scolta/pagefind';
        $this->assertEquals($expected, $settings['output_dir'],
            'output_dir with old ABSPATH default should be migrated to uploads path');
    }

    public function test_activation_preserves_custom_build_dir(): void {
        // A custom path should not be touched by migration.
        $custom = '/mnt/fast-ssd/scolta-build';
        update_option('scolta_settings', ['build_dir' => $custom]);

        scolta_activate();

        $settings = get_option('scolta_settings');
        $this->assertEquals($custom, $settings['build_dir'],
            'Custom build_dir should not be migrated');
    }

    public function test_activation_preserves_custom_output_dir(): void {
        $custom = '/mnt/fast-ssd/scolta-pagefind';
        update_option('scolta_settings', ['output_dir' => $custom]);

        scolta_activate();

        $settings = get_option('scolta_settings');
        $this->assertEquals($custom, $settings['output_dir'],
            'Custom output_dir should not be migrated');
    }

    public function test_activation_new_install_uses_uploads_paths(): void {
        // Fresh install — no existing settings.
        $this->assertFalse(get_option('scolta_settings'));

        scolta_activate();

        $settings = get_option('scolta_settings');
        $uploads   = wp_upload_dir()['basedir'];
        $this->assertEquals($uploads . '/scolta/build', $settings['build_dir']);
        $this->assertEquals($uploads . '/scolta/pagefind', $settings['output_dir']);
    }

    public function test_settings_round_trip_uses_uploads_paths(): void {
        // Fresh install — no existing settings.
        delete_option('scolta_settings');

        // Call activation directly (bootstrap loads scolta.php which defines scolta_activate).
        scolta_activate();

        $settings = get_option('scolta_settings', []);
        $uploads_base = wp_upload_dir()['basedir'];

        // Both paths must be under wp-content/uploads/scolta/.
        $this->assertStringStartsWith(
            $uploads_base . '/scolta/',
            $settings['build_dir'] ?? '',
            'build_dir must default to uploads-based path after activation'
        );
        $this->assertStringStartsWith(
            $uploads_base . '/scolta/',
            $settings['output_dir'] ?? '',
            'output_dir must default to uploads-based path after activation'
        );

        // Neither path should contain old defaults.
        $this->assertStringNotContainsString('scolta-build', $settings['build_dir'] ?? '');
        $this->assertStringNotContainsString('scolta-pagefind', $settings['output_dir'] ?? '');
    }

    public function test_admin_form_defaults_match_plugin_defaults(): void {
        $admin_source = file_get_contents(dirname(__DIR__) . '/admin/class-scolta-admin.php');
        // Admin must NOT use old default paths.
        $this->assertStringNotContainsString(
            "WP_CONTENT_DIR . '/scolta-build'",
            $admin_source,
            'Admin form must not use old build_dir default (WP_CONTENT_DIR/scolta-build)'
        );
        $this->assertStringNotContainsString(
            "ABSPATH . 'scolta-pagefind'",
            $admin_source,
            'Admin form must not use old output_dir default (ABSPATH/scolta-pagefind)'
        );
        // Admin must use uploads-based paths.
        $this->assertStringContainsString(
            "'/scolta/build'",
            $admin_source,
            'Admin form must default build_dir to uploads-based path'
        );
        $this->assertStringContainsString(
            "'/scolta/pagefind'",
            $admin_source,
            'Admin form must default output_dir to uploads-based path'
        );
    }

    public function test_deactivation_runs_without_error(): void {
        // Verify the function has the expected void return type and completes
        // without throwing. The wpdb stub handles the query.
        $ref = new ReflectionFunction('scolta_deactivate');
        $this->assertEquals('void', $ref->getReturnType()?->getName(),
            'scolta_deactivate() should declare a void return type');

        // Call it and verify no exception was thrown.
        $thrown = null;
        try {
            scolta_deactivate();
        } catch (\Throwable $e) {
            $thrown = $e;
        }
        $this->assertNull($thrown,
            'scolta_deactivate() should complete without throwing');
    }
}
