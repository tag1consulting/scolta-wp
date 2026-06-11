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

    protected function tearDown(): void {
        unset($GLOBALS['scolta_as_scheduled'], $GLOBALS['scolta_as_unscheduled']);
    }

    // -------------------------------------------------------------------
    // Source-level checks
    // -------------------------------------------------------------------

    public function test_debounce_action_constant(): void {
        $this->assertSame('scolta_debounced_rebuild', Scolta_Auto_Rebuild::DEBOUNCE_ACTION);
    }

    public function test_init_hooks_save_post(): void {
        $source = file_get_contents(dirname(__DIR__) . '/includes/class-scolta-auto-rebuild.php');

        $this->assertMatchesRegularExpression(
            "/add_action\s*\(\s*'save_post'/",
            $source,
            'Scolta_Auto_Rebuild::init() must hook save_post'
        );
    }

    public function test_init_reads_no_options(): void {
        // init() runs on every request; the settings read is deferred to the
        // content-change callbacks so page views cost no extra DB query
        // (scolta_settings is not autoloaded).
        $source = file_get_contents(dirname(__DIR__) . '/includes/class-scolta-auto-rebuild.php');

        $this->assertDoesNotMatchRegularExpression(
            '/function init\(\)[^}]*get_option/s',
            $source,
            'init() must not read options — the auto_rebuild check belongs in the callbacks'
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
        update_option('scolta_settings', [
            'auto_rebuild'       => true,
            'auto_rebuild_delay' => 120,
            'post_types'         => ['post', 'page'],
        ]);

        $post = new WP_Post();
        $post->ID         = 1;
        $post->post_type  = 'post';
        $post->post_status = 'publish';

        Scolta_Auto_Rebuild::on_content_change(1, $post);

        $this->assertCount(1, $GLOBALS['scolta_as_scheduled'],
            'on_content_change() must schedule exactly one debounced rebuild');
        $this->assertSame(Scolta_Auto_Rebuild::DEBOUNCE_ACTION,
            $GLOBALS['scolta_as_scheduled'][0]['hook']);
        $this->assertGreaterThanOrEqual(time() + 120,
            $GLOBALS['scolta_as_scheduled'][0]['timestamp'],
            'the debounce must honor the configured delay');
    }

    public function test_on_content_change_is_noop_when_disabled(): void {
        // Hooks are registered unconditionally now, so the callback itself
        // must bail when the auto_rebuild setting is off.
        update_option('scolta_settings', [
            'auto_rebuild' => false,
            'post_types'   => ['post', 'page'],
        ]);

        $post = new WP_Post();
        $post->ID         = 1;
        $post->post_type  = 'post';
        $post->post_status = 'publish';

        Scolta_Auto_Rebuild::on_content_change(1, $post);

        $this->assertSame([], $GLOBALS['scolta_as_scheduled'],
            'on_content_change() must not schedule anything when auto_rebuild is disabled');
        $this->assertSame([], $GLOBALS['scolta_as_unscheduled'],
            'on_content_change() must not unschedule anything when auto_rebuild is disabled');
    }

    public function test_on_content_change_is_noop_when_settings_absent(): void {
        // A site that never saved settings has no scolta_settings row;
        // empty() must treat that as disabled, matching the previous
        // init()-time behaviour.
        $post = new WP_Post();
        $post->ID         = 1;
        $post->post_type  = 'post';
        $post->post_status = 'publish';

        Scolta_Auto_Rebuild::on_content_change(1, $post);

        $this->assertSame([], $GLOBALS['scolta_as_scheduled'],
            'on_content_change() must not schedule anything without saved settings');
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

        // Should return early — no scheduling for non-indexed type.
        Scolta_Auto_Rebuild::on_content_change(2, $post);
        $this->assertSame([], $GLOBALS['scolta_as_scheduled'],
            'on_content_change() must not schedule for a non-indexed post type');
    }

    // -------------------------------------------------------------------
    // trigger_rebuild() honors the flag at fire time
    // -------------------------------------------------------------------

    public function test_trigger_rebuild_starts_scheduler_when_enabled(): void {
        update_option('scolta_settings', ['auto_rebuild' => true]);

        Scolta_Auto_Rebuild::trigger_rebuild();

        // start_rebuild() takes the build lock and schedules ACTION_START.
        $this->assertNotFalse(get_transient(Scolta_Rebuild_Scheduler::LOCK_KEY),
            'trigger_rebuild() must start the scheduler when auto_rebuild is on');
        $this->assertCount(1, $GLOBALS['scolta_as_scheduled']);
    }

    public function test_trigger_rebuild_is_noop_when_disabled(): void {
        // A debounce event queued while auto_rebuild was on can fire after
        // an administrator turns the setting off; the stale event must not
        // rebuild. (Previously the DEBOUNCE_ACTION callback was simply not
        // registered while disabled — same outcome, new mechanism.)
        update_option('scolta_settings', ['auto_rebuild' => false]);

        Scolta_Auto_Rebuild::trigger_rebuild();

        $this->assertFalse(get_transient(Scolta_Rebuild_Scheduler::LOCK_KEY),
            'trigger_rebuild() must not start the scheduler when auto_rebuild is off');
        $this->assertSame([], $GLOBALS['scolta_as_scheduled']);
    }
}
