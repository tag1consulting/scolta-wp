<?php

declare(strict_types=1);

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Activation-path network regression tests (WP.org review round 3).
 *
 * The original defect: activation auto-provisioned Amazee.ai (remote contact
 * with the site admin email) with no consent step, and no test asserted what
 * activation must NOT do. These tests run the REAL activation hook —
 * register_activation_hook through do_action, not a hand-called function —
 * in a subprocess probe (tests/integration/activation-network-probe.php),
 * because SCOLTA_AUTO_PROVISION_DEFAULT must be defined before scolta.php
 * loads and an in-process test cannot vary a constant per test.
 *
 * The probe records outbound HTTP (pre_http_request) and provisioning
 * attempts (the scolta_pre_auto_provision seam, which short-circuits before
 * any HTTP client is constructed) — nothing ever touches the network.
 */
class ActivationNetworkTest extends TestCase {

    /**
     * Run the probe subprocess and decode its JSON report.
     *
     * @param string $mode One of 'activate-on', 'activate-off', 'optin'.
     * @return array<string, mixed> Probe observations.
     */
    private function run_probe( string $mode ): array {
        $cmd = escapeshellarg( PHP_BINARY )
            . ' ' . escapeshellarg( __DIR__ . '/integration/activation-network-probe.php' )
            . ' ' . escapeshellarg( $mode ) . ' 2>&1';

        exec( $cmd, $output, $exit_code );
        $raw = implode( "\n", $output );
        $this->assertSame( 0, $exit_code, "Probe exited {$exit_code}:\n{$raw}" );

        // The JSON report is the last non-empty line.
        $lines = array_values( array_filter( $output, static fn( $l ) => trim( $l ) !== '' ) );
        $json  = json_decode( end( $lines ) ?: '', true );
        $this->assertIsArray( $json, "Probe output is not JSON:\n{$raw}" );
        return $json;
    }

    // -------------------------------------------------------------------
    // Opt-in build (SCOLTA_AUTO_PROVISION_DEFAULT = false, the wp.org zip)
    // -------------------------------------------------------------------

    public function test_optin_build_activation_performs_zero_outbound_http(): void {
        $report = $this->run_probe( 'activate-off' );

        $this->assertSame( 0, $report['http_requests'], 'Activation must not make any WP HTTP API request' );
        $this->assertSame( 0, $report['provision_calls'], 'Activation must not attempt Amazee.ai provisioning' );
        $this->assertNotContains(
            'scolta_amazee_provision',
            $report['scheduled'],
            'Activation must not schedule the provisioning action'
        );
    }

    public function test_optin_build_activation_defaults_ai_features_off(): void {
        $report = $this->run_probe( 'activate-off' );

        $this->assertFalse( $report['ai_expand_query'], 'ai_expand_query must default off in the opt-in build' );
        $this->assertFalse( $report['ai_summarize'], 'ai_summarize must default off in the opt-in build' );
        $this->assertTrue( $report['optin_pending'], 'Activation must record the pending opt-in flag' );
    }

    public function test_optin_build_still_schedules_local_index_build(): void {
        $report = $this->run_probe( 'activate-off' );

        $this->assertContains(
            'scolta_rebuild_start',
            $report['scheduled'],
            'The local-only index build must still be scheduled'
        );
    }

    // -------------------------------------------------------------------
    // Auto-provisioning build (constant true, self-distributed/partner)
    // -------------------------------------------------------------------

    public function test_autoprovision_build_schedules_and_attempts_provisioning(): void {
        $report = $this->run_probe( 'activate-on' );

        $this->assertContains( 'scolta_amazee_provision', $report['scheduled'], 'Provisioning must be scheduled' );
        $this->assertSame( 1, $report['provision_calls'], 'Running the scheduled action must attempt provisioning (intercepted)' );
        $this->assertSame( 0, $report['http_requests'], 'The seam must intercept before any real HTTP' );
        $this->assertTrue( $report['ai_expand_query'] );
        $this->assertTrue( $report['ai_summarize'] );
        $this->assertFalse( $report['optin_pending'], 'No opt-in flag in auto-provisioning builds' );
    }

    // -------------------------------------------------------------------
    // Explicit opt-in action (admin_post_scolta_enable_ai)
    // -------------------------------------------------------------------

    public function test_enable_action_provisions_exactly_once_and_flips_ai_settings(): void {
        $report = $this->run_probe( 'optin' );

        $this->assertSame( 1, $report['provision_calls'], 'The opt-in action must trigger provisioning exactly once' );
        $this->assertTrue( $report['ai_expand_query'], 'Opting in must enable AI query expansion' );
        $this->assertTrue( $report['ai_summarize'], 'Opting in must enable AI summarization' );
        $this->assertFalse( $report['optin_pending'], 'Opting in must clear the pending flag' );
        $this->assertStringContainsString( 'page=scolta', (string) $report['redirect'], 'Handler must redirect back to settings' );
    }
}
