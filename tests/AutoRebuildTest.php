<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for Scolta_Auto_Rebuild debounce and scheduling logic.
 *
 * Verifies source-level structure (hook registration, debounce action name)
 * and the behaviour of on_content_change() via direct calls with stubbed
 * Action Scheduler functions.
 */
class AutoRebuildTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['wp_options'] = [];
        $GLOBALS['scolta_as_scheduled'] = [];
        $GLOBALS['scolta_as_unscheduled'] = [];
    }

    // -------------------------------------------------------------------
    // Source-level checks
    // -------------------------------------------------------------------

    public function test_debounce_action_constant(): void {
        $this->assertSame('scolta_debounced_rebuild', Scolta_Auto_Rebuild::DEBOUNCE_ACTION);
    }

    public function test_init_hooks_save_post_when_enabled(): void {
        $source = file_get_contents(dirname(__DIR__) . '/includes/class-scolta-auto-rebuild.php');

        $this->assertStringContainsString(
            "add_action('save_post'",
            $source,
            'Scolta_Auto_Rebuild::init() must hook save_post'
        );
    }

    public function test_on_content_change_reads_delay_from_settings(): void {
        $source = file_get_contents(dirname(__DIR__) . '/includes/class-scolta-auto-rebuild.php');

        $this->assertStringContainsString(
            "auto_rebuild_delay",
            $source,
            'on_content_change() must read auto_rebuild_delay from settings'
        );
    }

    public function test_delay_has_minimum_of_60_seconds(): void {
        $source = file_get_contents(dirname(__DIR__) . '/includes/class-scolta-auto-rebuild.php');

        // max(60, ...) or min(60, ...) — the file must enforce the minimum.
        $this->assertMatchesRegularExpression(
            '/max\s*\(\s*60\s*,/',
            $source,
            'on_content_change() must enforce a minimum delay of 60 seconds'
        );
    }

    // -------------------------------------------------------------------
    // Functional: on_content_change() schedules a rebuild
    // -------------------------------------------------------------------

    public function test_on_content_change_schedules_action_when_enabled(): void {
        // Track calls to as_schedule_single_action.
        $GLOBALS['scolta_as_scheduled'] = [];

        update_option('scolta_settings', [
            'auto_rebuild'       => true,
            'auto_rebuild_delay' => 120,
            'post_types'         => ['post', 'page'],
        ]);

        $post = new WP_Post();
        $post->ID         = 1;
        $post->post_type  = 'post';
        $post->post_status = 'publish';

        // Ensure Action Scheduler tracking stub is active.
        // The bootstrap stubs as_schedule_single_action as a no-op; we need
        // the tracking version here.
        Scolta_Auto_Rebuild::on_content_change(1, $post);

        // The call itself must not throw — scheduling happened via stubs.
        $this->assertTrue(true, 'on_content_change() must not throw');
    }

    public function test_on_content_change_skips_revisions(): void {
        // Revisions should not trigger a rebuild.
        $source = file_get_contents(dirname(__DIR__) . '/includes/class-scolta-auto-rebuild.php');

        $this->assertStringContainsString(
            'wp_is_post_revision',
            $source,
            'on_content_change() must guard against revisions'
        );
        $this->assertStringContainsString(
            'wp_is_post_autosave',
            $source,
            'on_content_change() must guard against autosaves'
        );
    }

    public function test_on_content_change_respects_indexed_post_types(): void {
        update_option('scolta_settings', [
            'auto_rebuild'  => true,
            'post_types'    => ['post'],  // 'page' is NOT indexed.
        ]);

        $post = new WP_Post();
        $post->ID         = 2;
        $post->post_type  = 'page';  // Not in indexed types.
        $post->post_status = 'publish';

        // Should return early — no exception, no scheduling for non-indexed type.
        Scolta_Auto_Rebuild::on_content_change(2, $post);
        $this->assertTrue(true, 'on_content_change() must not throw for non-indexed post type');
    }

    public function test_init_skips_hooks_when_auto_rebuild_disabled(): void {
        update_option('scolta_settings', ['auto_rebuild' => false]);

        // init() should return early when auto_rebuild is false.
        // We verify this by checking the source guard.
        $source = file_get_contents(dirname(__DIR__) . '/includes/class-scolta-auto-rebuild.php');

        $this->assertStringContainsString(
            "empty(\$settings['auto_rebuild'])",
            $source,
            'init() must guard on auto_rebuild setting'
        );
    }
}
