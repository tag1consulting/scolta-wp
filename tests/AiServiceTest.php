<?php

declare(strict_types=1);

use Yoast\PHPUnitPolyfills\TestCases\TestCase;
use Tag1\Scolta\Config\ScoltaConfig;

/**
 * Tests for Scolta_Ai_Service — config creation and prompt resolution.
 */
class AiServiceTest extends TestCase {

    protected function set_up(): void {
        $GLOBALS['wp_options'] = [];
        // Clear environment for deterministic tests.
        putenv('SCOLTA_API_KEY');
        unset($_ENV['SCOLTA_API_KEY'], $_SERVER['SCOLTA_API_KEY']);
    }

    private function createService(array $settings = []): Scolta_Ai_Service {
        $defaults = [
            'ai_provider' => 'anthropic',
            'ai_model' => 'claude-sonnet-4-5-20250929',
            'ai_base_url' => '',
            'ai_api_key' => 'test-key',
            'site_name' => 'Test Site',
            'site_description' => 'test website',
            'max_follow_ups' => 3,
            'ai_expand_query' => true,
            'ai_summarize' => true,
            'cache_ttl' => 2592000,
            'title_match_boost' => 1.0,
            'title_all_terms_multiplier' => 1.5,
            'content_match_boost' => 0.4,
            'recency_boost_max' => 0.5,
            'recency_half_life_days' => 365,
            'recency_penalty_after_days' => 1825,
            'recency_max_penalty' => 0.3,
            'expand_primary_weight' => 0.7,
            'excerpt_length' => 300,
            'results_per_page' => 10,
            'max_pagefind_results' => 50,
            'ai_summary_top_n' => 5,
            'ai_summary_max_chars' => 2000,
            'prompt_expand_query' => '',
            'prompt_summarize' => '',
            'prompt_follow_up' => '',
        ];

        $config = ScoltaConfig::fromArray(array_merge($defaults, $settings));
        return new Scolta_Ai_Service($config);
    }

    // -------------------------------------------------------------------
    // Config creation
    // -------------------------------------------------------------------

    public function test_get_config_returns_scolta_config(): void {
        $service = $this->createService();
        $config = $service->get_config();
        $this->assertInstanceOf(ScoltaConfig::class, $config);
    }

    public function test_config_maps_provider(): void {
        $service = $this->createService(['ai_provider' => 'openai']);
        $this->assertEquals('openai', $service->get_config()->aiProvider);
    }

    public function test_config_maps_model(): void {
        $service = $this->createService(['ai_model' => 'gpt-4']);
        $this->assertEquals('gpt-4', $service->get_config()->aiModel);
    }

    public function test_config_maps_site_name(): void {
        $service = $this->createService(['site_name' => 'My WP Site']);
        $this->assertEquals('My WP Site', $service->get_config()->siteName);
    }

    public function test_config_maps_phrase_adjacent_multiplier(): void {
        $service = $this->createService(['phrase_adjacent_multiplier' => 3.0]);
        $this->assertEquals(3.0, $service->get_config()->phraseAdjacentMultiplier);
    }

    public function test_config_maps_phrase_near_multiplier(): void {
        $service = $this->createService(['phrase_near_multiplier' => 2.0]);
        $this->assertEquals(2.0, $service->get_config()->phraseNearMultiplier);
    }

    public function test_config_maps_phrase_near_window(): void {
        $service = $this->createService(['phrase_near_window' => 8]);
        $this->assertEquals(8, $service->get_config()->phraseNearWindow);
    }

    public function test_config_maps_phrase_window(): void {
        $service = $this->createService(['phrase_window' => 20]);
        $this->assertEquals(20, $service->get_config()->phraseWindow);
    }

    public function test_config_maps_ai_languages(): void {
        $service = $this->createService(['ai_languages' => ['en', 'fr', 'de']]);
        $this->assertEquals(['en', 'fr', 'de'], $service->get_config()->aiLanguages);
    }

    public function test_config_maps_recency_strategy(): void {
        $service = $this->createService(['recency_strategy' => 'linear']);
        $this->assertEquals('linear', $service->get_config()->recencyStrategy);
    }

    public function test_config_maps_recency_curve(): void {
        $curve = [[0, 1.0], [365, 0.5], [730, 0.0]];
        $service = $this->createService(['recency_curve' => $curve]);
        $this->assertEquals($curve, $service->get_config()->recencyCurve);
    }

    // -------------------------------------------------------------------
    // Display config mapping
    // -------------------------------------------------------------------

    public function test_config_maps_excerpt_length(): void {
        $service = $this->createService(['excerpt_length' => 500]);
        $this->assertEquals(500, $service->get_config()->excerptLength);
    }

    public function test_config_maps_results_per_page(): void {
        $service = $this->createService(['results_per_page' => 25]);
        $this->assertEquals(25, $service->get_config()->resultsPerPage);
    }

    public function test_config_maps_max_pagefind_results(): void {
        $service = $this->createService(['max_pagefind_results' => 100]);
        $this->assertEquals(100, $service->get_config()->maxPagefindResults);
    }

    public function test_config_maps_ai_summary_top_n(): void {
        $service = $this->createService(['ai_summary_top_n' => 10]);
        $this->assertEquals(10, $service->get_config()->aiSummaryTopN);
    }

    public function test_config_maps_ai_summary_max_chars(): void {
        $service = $this->createService(['ai_summary_max_chars' => 5000]);
        $this->assertEquals(5000, $service->get_config()->aiSummaryMaxChars);
    }

    public function test_display_values_propagate_to_js_scoring_config(): void {
        $service = $this->createService([
            'excerpt_length' => 999,
            'results_per_page' => 42,
            'max_pagefind_results' => 75,
            'ai_summary_top_n' => 8,
            'ai_summary_max_chars' => 3000,
        ]);
        $js = $service->get_config()->toJsScoringConfig();

        $this->assertEquals(999, $js['EXCERPT_LENGTH']);
        $this->assertEquals(42, $js['RESULTS_PER_PAGE']);
        $this->assertEquals(75, $js['MAX_PAGEFIND_RESULTS']);
        $this->assertEquals(8, $js['AI_SUMMARY_TOP_N']);
        $this->assertEquals(3000, $js['AI_SUMMARY_MAX_CHARS']);
    }

    // -------------------------------------------------------------------
    // API key detection
    // -------------------------------------------------------------------

    public function test_api_key_from_env(): void {
        putenv('SCOLTA_API_KEY=env-key-123');
        $this->assertEquals('env-key-123', Scolta_Ai_Service::get_api_key());
        $this->assertEquals('env', Scolta_Ai_Service::get_api_key_source());
        putenv('SCOLTA_API_KEY');
    }

    public function test_api_key_source_none_when_empty(): void {
        $this->assertEquals('', Scolta_Ai_Service::get_api_key());
        $this->assertEquals('none', Scolta_Ai_Service::get_api_key_source());
    }

    public function test_api_key_from_constant(): void {
        if (!defined('SCOLTA_API_KEY')) {
            define('SCOLTA_API_KEY', 'const-key-456');
        }
        // The constant path only fires if env is empty.
        putenv('SCOLTA_API_KEY');
        $key = Scolta_Ai_Service::get_api_key();
        $source = Scolta_Ai_Service::get_api_key_source();
        $this->assertEquals('const-key-456', $key);
        $this->assertEquals('constant', $source);
    }

    // -------------------------------------------------------------------
    // Prompt resolution
    // -------------------------------------------------------------------

    public function test_get_expand_prompt_returns_default(): void {
        $service = $this->createService();
        $prompt = $service->get_expand_prompt();
        $this->assertStringContainsString('Test Site', $prompt);
    }

    public function test_get_expand_prompt_uses_custom_override(): void {
        $service = $this->createService([
            'prompt_expand_query' => 'Custom prompt for {SITE_NAME}',
        ]);
        $prompt = $service->get_expand_prompt();
        $this->assertEquals('Custom prompt for {SITE_NAME}', $prompt);
    }

    public function test_get_summarize_prompt_returns_default(): void {
        $service = $this->createService();
        $prompt = $service->get_summarize_prompt();
        $this->assertStringContainsString('Test Site', $prompt);
    }

    public function test_get_follow_up_prompt_returns_default(): void {
        $service = $this->createService();
        $prompt = $service->get_follow_up_prompt();
        $this->assertStringContainsString('Test Site', $prompt);
    }

    // -------------------------------------------------------------------
    // WP AI SDK detection
    // -------------------------------------------------------------------

    public function test_has_wp_ai_sdk_returns_false(): void {
        // The stub environment has no WP AI Client SDK.
        $service = $this->createService();
        $this->assertFalse($service->has_wp_ai_sdk());
    }
}
