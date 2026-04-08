<?php

declare(strict_types=1);

use Yoast\PHPUnitPolyfills\TestCases\TestCase;
use Tag1\Scolta\Config\ScoltaConfig;
use Tag1\Scolta\Content\ContentSourceInterface;

/**
 * Tests for Scolta_Content_Source — WordPress content adapter.
 */
class ContentSourceTest extends TestCase {

    protected function set_up(): void {
        $GLOBALS['wp_options'] = [];
    }

    private function createConfig(array $overrides = []): ScoltaConfig {
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
        return ScoltaConfig::fromArray(array_merge($defaults, $overrides));
    }

    private function createSource(array $configOverrides = []): Scolta_Content_Source {
        return new Scolta_Content_Source($this->createConfig($configOverrides));
    }

    // -------------------------------------------------------------------
    // Interface implementation
    // -------------------------------------------------------------------

    public function test_class_exists(): void {
        $this->assertTrue(class_exists('Scolta_Content_Source'));
    }

    public function test_implements_content_source_interface(): void {
        $ref = new ReflectionClass('Scolta_Content_Source');
        $this->assertTrue(
            $ref->implementsInterface(ContentSourceInterface::class)
        );
    }

    // -------------------------------------------------------------------
    // Interface methods (camelCase) exist
    // -------------------------------------------------------------------

    public function test_has_getPublishedContent(): void {
        $ref = new ReflectionClass('Scolta_Content_Source');
        $this->assertTrue($ref->hasMethod('getPublishedContent'));
    }

    public function test_has_getChangedContent(): void {
        $ref = new ReflectionClass('Scolta_Content_Source');
        $this->assertTrue($ref->hasMethod('getChangedContent'));
    }

    public function test_has_getDeletedIds(): void {
        $ref = new ReflectionClass('Scolta_Content_Source');
        $this->assertTrue($ref->hasMethod('getDeletedIds'));
    }

    public function test_has_clearTracker(): void {
        $ref = new ReflectionClass('Scolta_Content_Source');
        $this->assertTrue($ref->hasMethod('clearTracker'));
    }

    public function test_has_getTotalCount(): void {
        $ref = new ReflectionClass('Scolta_Content_Source');
        $this->assertTrue($ref->hasMethod('getTotalCount'));
    }

    public function test_has_getPendingCount(): void {
        $ref = new ReflectionClass('Scolta_Content_Source');
        $this->assertTrue($ref->hasMethod('getPendingCount'));
    }

    // -------------------------------------------------------------------
    // WordPress snake_case aliases exist
    // -------------------------------------------------------------------

    public function test_has_get_published_content(): void {
        $ref = new ReflectionClass('Scolta_Content_Source');
        $this->assertTrue($ref->hasMethod('get_published_content'));
    }

    public function test_has_get_changed_content(): void {
        $ref = new ReflectionClass('Scolta_Content_Source');
        $this->assertTrue($ref->hasMethod('get_changed_content'));
    }

    public function test_has_get_deleted_ids(): void {
        $ref = new ReflectionClass('Scolta_Content_Source');
        $this->assertTrue($ref->hasMethod('get_deleted_ids'));
    }

    public function test_has_get_total_count(): void {
        $ref = new ReflectionClass('Scolta_Content_Source');
        $this->assertTrue($ref->hasMethod('get_total_count'));
    }

    // -------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------

    public function test_constructor_accepts_scolta_config(): void {
        $ref = new ReflectionMethod('Scolta_Content_Source', '__construct');
        $params = $ref->getParameters();

        $this->assertCount(1, $params);
        $this->assertEquals('config', $params[0]->getName());
        $this->assertEquals(ScoltaConfig::class, $params[0]->getType()->getName());
    }

    public function test_constructor_creates_instance(): void {
        $source = $this->createSource();
        $this->assertInstanceOf(Scolta_Content_Source::class, $source);
    }

    // -------------------------------------------------------------------
    // Interface methods delegate to snake_case implementations
    // -------------------------------------------------------------------

    public function test_getDeletedIds_returns_array(): void {
        $source = $this->createSource();
        $result = $source->getDeletedIds();
        $this->assertIsArray($result);
    }

    public function test_get_deleted_ids_returns_array(): void {
        $source = $this->createSource();
        $result = $source->get_deleted_ids();
        $this->assertIsArray($result);
    }

    public function test_clearTracker_delegates(): void {
        // clearTracker should call Scolta_Tracker::clear() without error.
        $source = $this->createSource();
        $source->clearTracker();
        $this->assertTrue(true);
    }

    public function test_getPendingCount_returns_int(): void {
        $source = $this->createSource();
        $result = $source->getPendingCount();
        $this->assertIsInt($result);
    }

    public function test_getTotalCount_returns_int(): void {
        $source = $this->createSource();
        $result = $source->getTotalCount();
        $this->assertIsInt($result);
    }

    public function test_get_total_count_returns_int(): void {
        $source = $this->createSource();
        $result = $source->get_total_count(['post', 'page']);
        $this->assertIsInt($result);
    }

    public function test_getChangedContent_returns_iterable(): void {
        $source = $this->createSource();
        $result = $source->getChangedContent();
        $this->assertIsIterable($result);
    }

    public function test_getPublishedContent_returns_iterable(): void {
        $source = $this->createSource();
        $result = $source->getPublishedContent();
        $this->assertIsIterable($result);
    }

    // -------------------------------------------------------------------
    // Return type consistency between camelCase and snake_case
    // -------------------------------------------------------------------

    public function test_getDeletedIds_matches_get_deleted_ids(): void {
        $source = $this->createSource();
        $camel = $source->getDeletedIds();
        $snake = $source->get_deleted_ids();
        $this->assertEquals($camel, $snake);
    }
}
