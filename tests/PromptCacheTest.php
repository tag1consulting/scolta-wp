<?php

declare(strict_types=1);

use Yoast\PHPUnitPolyfills\TestCases\TestCase;
use Tag1\Scolta\Prompt\DefaultPrompts;

/**
 * Tests for prompt cache invalidation on plugin update (issue #49).
 *
 * scolta_refresh_prompt_cache_if_stale() rebuilds scolta_resolved_prompts
 * whenever scolta_prompt_cache_version doesn't match SCOLTA_VERSION. This
 * ensures that sites which never re-save settings still get updated prompt
 * text after a plugin upgrade.
 */
class PromptCacheTest extends TestCase {

    protected function set_up(): void {
        $GLOBALS['wp_options'] = [];
    }

    // -------------------------------------------------------------------
    // Cache invalidation behaviour
    // -------------------------------------------------------------------

    public function test_refresh_builds_cache_when_version_absent(): void {
        // No version stored → cache should be built.
        scolta_refresh_prompt_cache_if_stale();

        $cache = get_option( 'scolta_resolved_prompts' );
        $this->assertIsArray( $cache );
        $this->assertArrayHasKey( 'expand_query', $cache );
        $this->assertArrayHasKey( 'summarize', $cache );
        $this->assertArrayHasKey( 'follow_up', $cache );
    }

    public function test_refresh_records_version_after_rebuild(): void {
        scolta_refresh_prompt_cache_if_stale();

        $this->assertEquals( SCOLTA_VERSION, get_option( 'scolta_prompt_cache_version' ) );
    }

    public function test_refresh_skips_rebuild_when_version_matches(): void {
        // Pre-populate with stale content and matching version.
        $GLOBALS['wp_options']['scolta_prompt_cache_version'] = SCOLTA_VERSION;
        $GLOBALS['wp_options']['scolta_resolved_prompts'] = [ 'expand_query' => 'stale' ];

        scolta_refresh_prompt_cache_if_stale();

        // Cache must not have been overwritten.
        $this->assertEquals( 'stale', get_option( 'scolta_resolved_prompts' )['expand_query'] );
    }

    public function test_refresh_rebuilds_when_version_is_outdated(): void {
        $GLOBALS['wp_options']['scolta_prompt_cache_version'] = '0.0.0';
        $GLOBALS['wp_options']['scolta_resolved_prompts'] = [ 'expand_query' => 'old' ];

        scolta_refresh_prompt_cache_if_stale();

        $cache = get_option( 'scolta_resolved_prompts' );
        $this->assertNotEquals( 'old', $cache['expand_query'] );
        $this->assertEquals( SCOLTA_VERSION, get_option( 'scolta_prompt_cache_version' ) );
    }

    // -------------------------------------------------------------------
    // Resolved content matches DefaultPrompts
    // -------------------------------------------------------------------

    public function test_cached_expand_query_matches_default_prompts(): void {
        $GLOBALS['wp_options']['scolta_settings'] = [
            'site_name'        => 'Test Site',
            'site_description' => 'test website',
        ];

        scolta_refresh_prompt_cache_if_stale();

        $cache    = get_option( 'scolta_resolved_prompts' );
        $expected = DefaultPrompts::resolve( 'expand_query', 'Test Site', 'test website' );
        $this->assertEquals( $expected, $cache['expand_query'] );
    }

    public function test_cached_summarize_matches_default_prompts(): void {
        $GLOBALS['wp_options']['scolta_settings'] = [
            'site_name'        => 'Test Site',
            'site_description' => 'test website',
        ];

        scolta_refresh_prompt_cache_if_stale();

        $cache    = get_option( 'scolta_resolved_prompts' );
        $expected = DefaultPrompts::resolve( 'summarize', 'Test Site', 'test website' );
        $this->assertEquals( $expected, $cache['summarize'] );
    }

    public function test_cached_follow_up_matches_default_prompts(): void {
        $GLOBALS['wp_options']['scolta_settings'] = [
            'site_name'        => 'Test Site',
            'site_description' => 'test website',
        ];

        scolta_refresh_prompt_cache_if_stale();

        $cache    = get_option( 'scolta_resolved_prompts' );
        $expected = DefaultPrompts::resolve( 'follow_up', 'Test Site', 'test website' );
        $this->assertEquals( $expected, $cache['follow_up'] );
    }

    public function test_refresh_falls_back_to_bloginfo_site_name(): void {
        // No scolta_settings stored — should fall back to get_bloginfo('name').
        scolta_refresh_prompt_cache_if_stale();

        $cache = get_option( 'scolta_resolved_prompts' );
        // Bootstrap stub returns 'Test WordPress Site' for get_bloginfo('name').
        $this->assertStringContainsString( 'Test WordPress Site', $cache['expand_query'] );
    }

    // -------------------------------------------------------------------
    // Hook registration
    // -------------------------------------------------------------------

    public function test_plugins_loaded_hook_registered_in_source(): void {
        $content = file_get_contents( dirname( __DIR__ ) . '/scolta.php' );
        $this->assertStringContainsString(
            "add_action( 'plugins_loaded', 'scolta_refresh_prompt_cache_if_stale' )",
            $content,
            'scolta.php must register scolta_refresh_prompt_cache_if_stale on plugins_loaded'
        );
    }

    public function test_refresh_function_exists(): void {
        $this->assertTrue( function_exists( 'scolta_refresh_prompt_cache_if_stale' ) );
    }
}
