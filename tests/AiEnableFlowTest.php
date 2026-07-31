<?php

declare(strict_types=1);

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Tests for the explicit AI enable flow.
 *
 * AI features are established by the administrator's "Enable AI features"
 * action and by nothing else: activation registers no deferred work for it and
 * calls nothing that contacts a remote service. File-inspection and structural
 * tests — no live HTTP calls.
 */
class AiEnableFlowTest extends TestCase {

    private string $pluginFile;
    private string $pluginSource;
    private string $adminSource;

    protected function set_up(): void {
        $this->pluginFile   = dirname( __DIR__ ) . '/scolta.php';
        $this->pluginSource = file_get_contents( $this->pluginFile );
        $this->adminSource  = file_get_contents( dirname( __DIR__ ) . '/admin/class-scolta-admin.php' );
    }

    // -------------------------------------------------------------------
    // Activation registers no path to the AI service.
    // -------------------------------------------------------------------

    public function test_activation_body_never_reaches_the_ai_connection(): void {
        $body = $this->activateBody();
        // Seeding local option keys is fine; calling, scheduling or
        // constructing anything on the connection path is not.
        foreach ( array(
            'scolta_auto_provision_amazee',
            'scolta_amazee_provision',
            'AutoProvisioner',
            'AmazeeClient',
            'AmazeeTrialProvisioner',
            'Scolta_Amazee_Config_Storage',
            'wp_remote_',
        ) as $forbidden ) {
            $this->assertStringNotContainsString(
                $forbidden,
                $body,
                "scolta_activate() must not reference {$forbidden}"
            );
        }
    }

    public function test_activation_records_the_optin_flag(): void {
        $this->assertStringContainsString(
            "update_option( 'scolta_ai_optin_pending', true, false )",
            $this->activateBody(),
            'Activation must record the pending opt-in flag so the enable control appears'
        );
    }

    public function test_no_deferred_action_mirrors_the_enable_flow(): void {
        $this->assertDoesNotMatchRegularExpression(
            "/add_action\(\s*'scolta_amazee_provision'/",
            $this->pluginSource,
            'No scheduled action may establish the AI connection'
        );
    }

    public function test_the_only_caller_is_the_explicit_admin_action(): void {
        // One call site outside the function's own definition: the handler
        // behind admin_post_scolta_enable_ai.
        $this->assertSame(
            1,
            substr_count( $this->adminSource, 'scolta_auto_provision_amazee()' ),
            'admin must call the connection helper exactly once, from handle_enable_ai()'
        );
        $this->assertMatchesRegularExpression(
            '/function handle_enable_ai.*?scolta_auto_provision_amazee\(\)/s',
            $this->adminSource,
            'The call must live inside handle_enable_ai()'
        );

        $callers = array();
        foreach ( glob( dirname( __DIR__ ) . '/{includes,cli}/*.php', GLOB_BRACE ) as $file ) {
            if ( str_contains( (string) file_get_contents( $file ), 'scolta_auto_provision_amazee(' ) ) {
                $callers[] = basename( $file );
            }
        }
        $this->assertSame( array(), $callers, 'No request path may establish the AI connection' );
    }

    /**
     * Isolate the activation function body so assertions cannot match neighbours.
     *
     * Comment lines are dropped: what activation must not do is a statement
     * about the code it runs, and a comment naming the AI path (the settings
     * defaults carry one) is not a call to it.
     */
    private function activateBody(): string {
        $start = strpos( $this->pluginSource, 'function scolta_activate()' );
        $this->assertNotFalse( $start, 'scolta_activate() must exist' );
        $end = strpos( $this->pluginSource, "\n}\n", $start );
        $this->assertNotFalse( $end, 'could not find the end of scolta_activate()' );

        $body  = substr( $this->pluginSource, $start, $end - $start );
        $lines = array_filter(
            explode( "\n", $body ),
            static fn( string $line ): bool => ! str_starts_with( ltrim( $line ), '//' )
        );

        return implode( "\n", $lines );
    }

    // -------------------------------------------------------------------
    // The connection helper's structure.
    // -------------------------------------------------------------------

    public function test_connection_helper_exists_at_runtime(): void {
        $this->assertTrue(
            function_exists( 'scolta_auto_provision_amazee' ),
            'scolta_auto_provision_amazee() must be defined in scolta.php'
        );
    }

    public function test_connection_helper_uses_auto_provisioner_class(): void {
        $this->assertStringContainsString(
            'AutoProvisioner::ensureAiAvailable(',
            $this->pluginSource,
            'scolta_auto_provision_amazee() must delegate to AutoProvisioner::ensureAiAvailable()'
        );
    }

    public function test_connection_helper_uses_amazee_config_storage(): void {
        $this->assertStringContainsString(
            'new Scolta_Amazee_Config_Storage()',
            $this->pluginSource,
            'scolta_auto_provision_amazee() must use Scolta_Amazee_Config_Storage'
        );
    }

    public function test_connection_helper_passes_explicit_key_flag(): void {
        $this->assertStringContainsString(
            'hasExplicitApiKey: scolta_has_explicit_api_key()',
            $this->pluginSource,
            'scolta_auto_provision_amazee() must pass scolta_has_explicit_api_key() as the flag'
        );
    }

    public function test_connection_helper_persists_models_without_clobbering_user_config(): void {
        // onModelsResolved IS passed — the connection must persist the resolved
        // model names, otherwise the LiteLLM gateway is driven with the shipped
        // dated default (which it rejects with HTTP 400). The callback
        // (scolta_amazee_persist_resolved_models) writes the gateway-scoped
        // keys only, so an operator's model choice is never overwritten.
        $this->assertStringContainsString(
            "onModelsResolved: 'scolta_amazee_persist_resolved_models'",
            $this->pluginSource,
            'scolta_auto_provision_amazee() must persist resolved models via the guarded callback'
        );
        $this->assertStringContainsString(
            "hasResolvedModels: 'scolta_amazee_models_resolved'",
            $this->pluginSource,
            'scolta_auto_provision_amazee() must pass the hasResolvedModels predicate so a half-resolved state self-heals'
        );
        $body = $this->persistResolvedModelsBody();
        $this->assertStringContainsString( "\$settings['amazee_model']", $body );
        $this->assertStringContainsString( "\$settings['amazee_expansion_model']", $body );
        $this->assertStringNotContainsString(
            "\$settings['ai_model']",
            $body,
            'model resolution must never write the operator-facing ai_model'
        );
        $this->assertStringNotContainsString(
            "\$settings['ai_expansion_model']",
            $body,
            'model resolution must never write the operator-facing ai_expansion_model'
        );
    }

    /**
     * Isolate the callback body so the assertions cannot match neighbouring code.
     */
    private function persistResolvedModelsBody(): string
    {
        $start = strpos($this->pluginSource, 'function scolta_amazee_persist_resolved_models');
        $this->assertNotFalse($start, 'scolta_amazee_persist_resolved_models() must exist');
        $end = strpos($this->pluginSource, "\n}\n", $start);
        $this->assertNotFalse($end, 'could not find the end of scolta_amazee_persist_resolved_models()');

        return substr($this->pluginSource, $start, $end - $start);
    }

    // -------------------------------------------------------------------
    // scolta_has_explicit_api_key() checks all key sources.
    // -------------------------------------------------------------------

    public function test_has_explicit_api_key_function_exists(): void {
        $this->assertTrue(
            function_exists( 'scolta_has_explicit_api_key' ),
            'scolta_has_explicit_api_key() must be defined in scolta.php'
        );
    }

    public function test_has_explicit_api_key_checks_getenv(): void {
        $this->assertStringContainsString(
            "getenv( 'SCOLTA_API_KEY' )",
            $this->pluginSource,
            'scolta_has_explicit_api_key() must check getenv(SCOLTA_API_KEY)'
        );
    }

    public function test_has_explicit_api_key_checks_env_superglobal(): void {
        $this->assertStringContainsString(
            "\$_ENV['SCOLTA_API_KEY']",
            $this->pluginSource,
            'scolta_has_explicit_api_key() must check $_ENV[SCOLTA_API_KEY]'
        );
    }

    public function test_has_explicit_api_key_checks_constant(): void {
        $this->assertStringContainsString(
            "defined( 'SCOLTA_API_KEY' )",
            $this->pluginSource,
            'scolta_has_explicit_api_key() must check the SCOLTA_API_KEY constant'
        );
    }

    public function test_has_explicit_api_key_checks_database(): void {
        $this->assertStringContainsString(
            "get_option( 'scolta_settings'",
            $this->pluginSource,
            'scolta_has_explicit_api_key() must check the database-stored key (admin UI / legacy migration)'
        );
    }
}
