<?php

declare(strict_types=1);

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Activation-path network regression tests.
 *
 * The original defect: activation configured the AI connection itself (remote
 * contact carrying the site admin email) with no consent step, and no test
 * asserted what activation must NOT do. AI features are now enabled explicitly
 * by the administrator, so these tests run the REAL activation hook —
 * register_activation_hook through do_action, not a hand-called function — in a
 * subprocess probe (tests/integration/activation-network-probe.php) and assert
 * that activation alone establishes nothing.
 *
 * The probe records outbound HTTP (pre_http_request) and connection attempts
 * (the scolta_pre_auto_provision seam, which short-circuits before any HTTP
 * client is constructed) — nothing ever touches the network.
 */
class ActivationNetworkTest extends TestCase {

    /**
     * Run the probe subprocess and decode its JSON report.
     *
     * @param string $mode One of 'activate', 'optin', 'optin-key'.
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
    // Activation
    // -------------------------------------------------------------------

    public function test_activation_performs_zero_outbound_http(): void {
        $report = $this->run_probe( 'activate' );

        $this->assertSame( 0, $report['http_requests'], 'Activation must not make any WP HTTP API request' );
        $this->assertSame( 0, $report['provision_calls'], 'Activation must not contact the AI service' );
        $this->assertNotContains(
            'scolta_amazee_provision',
            $report['scheduled'],
            'Activation must not schedule any AI connection work'
        );
    }

    public function test_activation_stores_no_credentials(): void {
        $report = $this->run_probe( 'activate' );

        $this->assertFalse( $report['credentials'], 'Activation must store no AI credentials' );
    }

    public function test_activation_defaults_ai_features_off_and_records_the_optin(): void {
        $report = $this->run_probe( 'activate' );

        $this->assertFalse( $report['ai_expand_query'], 'ai_expand_query must default off' );
        $this->assertFalse( $report['ai_summarize'], 'ai_summarize must default off' );
        $this->assertTrue( $report['optin_pending'], 'Activation must record the pending opt-in flag' );
    }

    public function test_activation_still_schedules_local_index_build(): void {
        $report = $this->run_probe( 'activate' );

        $this->assertContains(
            'scolta_rebuild_start',
            $report['scheduled'],
            'The local-only index build must still be scheduled'
        );
    }

    // -------------------------------------------------------------------
    // Explicit enable action (admin_post_scolta_enable_ai)
    // -------------------------------------------------------------------

    public function test_enable_action_connects_exactly_once_and_flips_ai_settings(): void {
        $report = $this->run_probe( 'optin' );

        $this->assertSame( 1, $report['provision_calls'], 'The enable action must establish the connection exactly once' );
        $this->assertTrue( $report['ai_expand_query'], 'Enabling must turn on AI query expansion' );
        $this->assertTrue( $report['ai_summarize'], 'Enabling must turn on AI summarization' );
        $this->assertSame( 'amazee', $report['ai_provider'], 'Enabling must record the managed gateway as the provider' );
        $this->assertFalse( $report['optin_pending'], 'Enabling must clear the pending flag' );
        $this->assertStringContainsString( 'page=scolta', (string) $report['redirect'], 'Handler must redirect back to settings' );
    }

    public function test_enable_action_uses_an_explicit_key_when_one_is_configured(): void {
        $report = $this->run_probe( 'optin-key' );

        $this->assertSame( 0, $report['provision_calls'], 'An operator key must be used as-is, with nothing established' );
        $this->assertFalse( $report['credentials'], 'No credentials may be stored when the site has its own key' );
        $this->assertTrue( $report['ai_expand_query'], 'Enabling must still turn on AI query expansion' );
        $this->assertTrue( $report['ai_summarize'], 'Enabling must still turn on AI summarization' );
        // Activation seeds no provider, and enabling AI with the operator's own
        // key must not pick one for them: this branch turns the AI features on,
        // nothing more. (The Amazee branch does write 'amazee', because that is
        // what the click connected.)
        $this->assertSame( '', $report['ai_provider'], 'Enabling with an explicit key must not choose a provider' );
        $this->assertFalse( $report['optin_pending'], 'Enabling must clear the pending flag' );
    }
}
