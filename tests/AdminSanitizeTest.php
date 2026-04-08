<?php

declare(strict_types=1);

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Tests for Scolta_Admin sanitization logic.
 *
 * The admin class is only loaded when is_admin() returns true.
 * We load it explicitly for testing.
 */
class AdminSanitizeTest extends TestCase {

    public static function set_up_before_class(): void {
        if (!class_exists('Scolta_Admin')) {
            require_once dirname(__DIR__) . '/admin/class-scolta-admin.php';
        }
    }

    protected function set_up(): void {
        $GLOBALS['wp_options'] = [];
        // Set defaults so sanitize can reference existing settings.
        scolta_activate();
    }

    private function defaultInput(): array {
        return [
            'ai_provider' => 'anthropic',
            'ai_model' => 'claude-sonnet-4-5-20250929',
            'ai_base_url' => '',
            'ai_expand_query' => '1',
            'ai_summarize' => '1',
            'max_follow_ups' => '3',
            'post_types' => ['post', 'page'],
            'site_name' => 'Test Site',
            'site_description' => 'website',
            'pagefind_binary' => 'pagefind',
            'build_dir' => '/tmp/wordpress/wp-content/scolta-build',
            'output_dir' => '/tmp/wordpress/scolta-pagefind',
            'auto_rebuild' => '1',
            'title_match_boost' => '1.0',
            'title_all_terms_multiplier' => '1.5',
            'content_match_boost' => '0.4',
            'recency_boost_max' => '0.5',
            'recency_half_life_days' => '365',
            'recency_penalty_after_days' => '1825',
            'recency_max_penalty' => '0.3',
            'expand_primary_weight' => '0.7',
            'excerpt_length' => '300',
            'results_per_page' => '10',
            'max_pagefind_results' => '50',
            'ai_summary_top_n' => '5',
            'ai_summary_max_chars' => '2000',
            'cache_ttl' => '2592000',
            'prompt_expand_query' => '',
            'prompt_summarize' => '',
            'prompt_follow_up' => '',
        ];
    }

    // -------------------------------------------------------------------
    // Method existence
    // -------------------------------------------------------------------

    public function test_sanitize_settings_exists(): void {
        $ref = new ReflectionClass('Scolta_Admin');
        $this->assertTrue($ref->hasMethod('sanitize_settings'));
    }

    public function test_sanitize_settings_accepts_array(): void {
        $ref = new ReflectionMethod('Scolta_Admin', 'sanitize_settings');
        $params = $ref->getParameters();

        $this->assertCount(1, $params);
        $this->assertEquals('array', $params[0]->getType()->getName());
    }

    public function test_sanitize_settings_returns_array(): void {
        $result = Scolta_Admin::sanitize_settings($this->defaultInput());
        $this->assertIsArray($result);
    }

    // -------------------------------------------------------------------
    // HTML stripping from text fields
    // -------------------------------------------------------------------

    public function test_strips_html_from_site_name(): void {
        $input = $this->defaultInput();
        $input['site_name'] = '<b>My Site</b>';
        $result = Scolta_Admin::sanitize_settings($input);
        $this->assertEquals('My Site', $result['site_name']);
    }

    public function test_strips_html_from_site_description(): void {
        $input = $this->defaultInput();
        $input['site_description'] = '<script>alert("xss")</script>test';
        $result = Scolta_Admin::sanitize_settings($input);
        $this->assertStringNotContainsString('<script>', $result['site_description']);
        $this->assertStringContainsString('test', $result['site_description']);
    }

    public function test_strips_html_from_model(): void {
        $input = $this->defaultInput();
        $input['ai_model'] = '<em>claude-sonnet-4-5-20250929</em>';
        $result = Scolta_Admin::sanitize_settings($input);
        $this->assertEquals('claude-sonnet-4-5-20250929', $result['ai_model']);
    }

    // -------------------------------------------------------------------
    // Numeric value preservation
    // -------------------------------------------------------------------

    public function test_preserves_valid_numeric_title_boost(): void {
        $input = $this->defaultInput();
        $input['title_match_boost'] = '2.5';
        $result = Scolta_Admin::sanitize_settings($input);
        $this->assertEquals(2.5, $result['title_match_boost']);
    }

    public function test_preserves_valid_integer_excerpt_length(): void {
        $input = $this->defaultInput();
        $input['excerpt_length'] = '500';
        $result = Scolta_Admin::sanitize_settings($input);
        $this->assertEquals(500, $result['excerpt_length']);
    }

    public function test_preserves_valid_max_follow_ups(): void {
        $input = $this->defaultInput();
        $input['max_follow_ups'] = '5';
        $result = Scolta_Admin::sanitize_settings($input);
        $this->assertEquals(5, $result['max_follow_ups']);
    }

    // -------------------------------------------------------------------
    // Cache TTL clamping
    // -------------------------------------------------------------------

    public function test_clamps_cache_ttl_minimum_zero(): void {
        $input = $this->defaultInput();
        $input['cache_ttl'] = '-100';
        $result = Scolta_Admin::sanitize_settings($input);
        $this->assertEquals(0, $result['cache_ttl']);
    }

    public function test_clamps_cache_ttl_maximum(): void {
        $input = $this->defaultInput();
        $input['cache_ttl'] = '99999999';
        $result = Scolta_Admin::sanitize_settings($input);
        $this->assertEquals(7776000, $result['cache_ttl']);
    }

    public function test_accepts_valid_cache_ttl(): void {
        $input = $this->defaultInput();
        $input['cache_ttl'] = '86400';
        $result = Scolta_Admin::sanitize_settings($input);
        $this->assertEquals(86400, $result['cache_ttl']);
    }

    public function test_accepts_zero_cache_ttl(): void {
        $input = $this->defaultInput();
        $input['cache_ttl'] = '0';
        $result = Scolta_Admin::sanitize_settings($input);
        $this->assertEquals(0, $result['cache_ttl']);
    }

    // -------------------------------------------------------------------
    // API key is NOT stored from form submission
    // -------------------------------------------------------------------

    public function test_does_not_store_api_key_from_input(): void {
        $input = $this->defaultInput();
        $input['ai_api_key'] = 'sk-secret-key-from-form';
        $result = Scolta_Admin::sanitize_settings($input);

        // The api key should NOT come from form input.
        // If existing settings don't have it, it should not appear.
        // Clear existing settings to test fresh.
        update_option('scolta_settings', []);
        $result2 = Scolta_Admin::sanitize_settings($input);
        $this->assertArrayNotHasKey('ai_api_key', $result2);
    }

    public function test_preserves_legacy_api_key_from_existing(): void {
        // Simulate existing settings with a legacy DB key.
        $existing = get_option('scolta_settings', []);
        $existing['ai_api_key'] = 'legacy-key-in-db';
        update_option('scolta_settings', $existing);

        $result = Scolta_Admin::sanitize_settings($this->defaultInput());
        $this->assertEquals('legacy-key-in-db', $result['ai_api_key']);
    }

    // -------------------------------------------------------------------
    // Internal settings preserved
    // -------------------------------------------------------------------

    public function test_preserves_search_page_path(): void {
        $existing = get_option('scolta_settings', []);
        $existing['search_page_path'] = '/custom-search';
        update_option('scolta_settings', $existing);

        $result = Scolta_Admin::sanitize_settings($this->defaultInput());
        $this->assertEquals('/custom-search', $result['search_page_path']);
    }

    public function test_preserves_pagefind_index_path(): void {
        $existing = get_option('scolta_settings', []);
        $existing['pagefind_index_path'] = '/custom-pagefind';
        update_option('scolta_settings', $existing);

        $result = Scolta_Admin::sanitize_settings($this->defaultInput());
        $this->assertEquals('/custom-pagefind', $result['pagefind_index_path']);
    }

    public function test_defaults_search_page_path_when_missing(): void {
        update_option('scolta_settings', []);
        $result = Scolta_Admin::sanitize_settings($this->defaultInput());
        $this->assertEquals('/scolta-search', $result['search_page_path']);
    }

    public function test_defaults_pagefind_index_path_when_missing(): void {
        update_option('scolta_settings', []);
        $result = Scolta_Admin::sanitize_settings($this->defaultInput());
        $this->assertEquals('/scolta-pagefind', $result['pagefind_index_path']);
    }

    // -------------------------------------------------------------------
    // Prompt sanitization
    // -------------------------------------------------------------------

    public function test_prompt_clears_when_matches_default(): void {
        // Get the default prompt template.
        try {
            $default = \Tag1\Scolta\Prompt\DefaultPrompts::getTemplate(
                \Tag1\Scolta\Prompt\DefaultPrompts::EXPAND_QUERY
            );
        } catch (\Throwable $e) {
            $this->markTestSkipped('Extism runtime not available: ' . $e->getMessage());
            return;
        }

        if (empty($default)) {
            $this->markTestSkipped('Default prompt template is empty (WASM not available).');
            return;
        }

        $input = $this->defaultInput();
        $input['prompt_expand_query'] = $default;
        $result = Scolta_Admin::sanitize_settings($input);
        $this->assertEquals('', $result['prompt_expand_query']);
    }

    public function test_prompt_preserves_custom_value(): void {
        $input = $this->defaultInput();
        $input['prompt_expand_query'] = 'My custom prompt for {SITE_NAME}';
        $result = Scolta_Admin::sanitize_settings($input);
        $this->assertEquals('My custom prompt for {SITE_NAME}', $result['prompt_expand_query']);
    }

    public function test_prompt_empty_stays_empty(): void {
        $input = $this->defaultInput();
        $input['prompt_expand_query'] = '';
        $result = Scolta_Admin::sanitize_settings($input);
        $this->assertEquals('', $result['prompt_expand_query']);
    }

    // -------------------------------------------------------------------
    // Provider validation
    // -------------------------------------------------------------------

    public function test_rejects_invalid_provider(): void {
        $input = $this->defaultInput();
        $input['ai_provider'] = 'evil-provider';
        $result = Scolta_Admin::sanitize_settings($input);
        $this->assertEquals('anthropic', $result['ai_provider']);
    }

    public function test_accepts_valid_provider_openai(): void {
        $input = $this->defaultInput();
        $input['ai_provider'] = 'openai';
        $result = Scolta_Admin::sanitize_settings($input);
        $this->assertEquals('openai', $result['ai_provider']);
    }

    // -------------------------------------------------------------------
    // Scoring field clamping
    // -------------------------------------------------------------------

    public function test_clamps_title_boost_max(): void {
        $input = $this->defaultInput();
        $input['title_match_boost'] = '999';
        $result = Scolta_Admin::sanitize_settings($input);
        $this->assertEquals(10.0, $result['title_match_boost']);
    }

    public function test_clamps_title_boost_min(): void {
        $input = $this->defaultInput();
        $input['title_match_boost'] = '-5';
        $result = Scolta_Admin::sanitize_settings($input);
        $this->assertEquals(0.0, $result['title_match_boost']);
    }

    public function test_clamps_recency_max_penalty(): void {
        $input = $this->defaultInput();
        $input['recency_max_penalty'] = '5.0';
        $result = Scolta_Admin::sanitize_settings($input);
        $this->assertEquals(1.0, $result['recency_max_penalty']);
    }

    // -------------------------------------------------------------------
    // Boolean toggles
    // -------------------------------------------------------------------

    public function test_checkbox_off_becomes_false(): void {
        $input = $this->defaultInput();
        unset($input['ai_expand_query']);
        $result = Scolta_Admin::sanitize_settings($input);
        $this->assertFalse($result['ai_expand_query']);
    }

    public function test_checkbox_on_becomes_true(): void {
        $input = $this->defaultInput();
        $input['ai_expand_query'] = '1';
        $result = Scolta_Admin::sanitize_settings($input);
        $this->assertTrue($result['ai_expand_query']);
    }
}
