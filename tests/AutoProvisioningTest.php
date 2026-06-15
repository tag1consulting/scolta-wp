<?php

declare(strict_types=1);

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Tests for Amazee.ai auto-provisioning on plugin activation.
 *
 * File-inspection and structural tests — no live HTTP calls.
 */
class AutoProvisioningTest extends TestCase {

    private string $pluginFile;
    private string $pluginSource;

    protected function set_up(): void {
        $this->pluginFile   = dirname( __DIR__ ) . '/scolta.php';
        $this->pluginSource = file_get_contents( $this->pluginFile );
    }

    // -------------------------------------------------------------------
    // Activation hook schedules or calls provisioning.
    // -------------------------------------------------------------------

    public function test_activate_uses_as_for_provisioning(): void {
        $this->assertStringContainsString(
            "'scolta_amazee_provision'",
            $this->pluginSource,
            'scolta_activate() must schedule scolta_amazee_provision via Action Scheduler'
        );
    }

    public function test_activate_falls_back_to_synchronous_without_as(): void {
        $this->assertStringContainsString(
            'scolta_auto_provision_amazee()',
            $this->pluginSource,
            'scolta_activate() must call scolta_auto_provision_amazee() when AS is unavailable'
        );
    }

    public function test_amazee_provision_action_registered(): void {
        $this->assertStringContainsString(
            "'scolta_amazee_provision',",
            $this->pluginSource,
            'scolta_amazee_provision action must be registered'
        );
        $this->assertMatchesRegularExpression(
            "/add_action\\(\\s*'scolta_amazee_provision',.*?scolta_auto_provision_amazee\\(\\)/s",
            $this->pluginSource,
            'scolta_amazee_provision action must call scolta_auto_provision_amazee()'
        );
    }

    // -------------------------------------------------------------------
    // scolta_auto_provision_amazee() function structure.
    // -------------------------------------------------------------------

    public function test_auto_provision_function_exists_at_runtime(): void {
        $this->assertTrue(
            function_exists( 'scolta_auto_provision_amazee' ),
            'scolta_auto_provision_amazee() must be defined in scolta.php'
        );
    }

    public function test_auto_provision_uses_auto_provisioner_class(): void {
        $this->assertStringContainsString(
            'AutoProvisioner::ensureAiAvailable(',
            $this->pluginSource,
            'scolta_auto_provision_amazee() must delegate to AutoProvisioner::ensureAiAvailable()'
        );
    }

    public function test_auto_provision_uses_amazee_config_storage(): void {
        $this->assertStringContainsString(
            'new Scolta_Amazee_Config_Storage()',
            $this->pluginSource,
            'scolta_auto_provision_amazee() must use Scolta_Amazee_Config_Storage'
        );
    }

    public function test_auto_provision_passes_explicit_key_flag(): void {
        $this->assertStringContainsString(
            'hasExplicitApiKey: scolta_has_explicit_api_key()',
            $this->pluginSource,
            'scolta_auto_provision_amazee() must pass scolta_has_explicit_api_key() as the flag'
        );
    }

    public function test_auto_provision_persists_models_without_clobbering_user_config(): void {
        // onModelsResolved IS now passed — provisioning must persist the resolved
        // model names, otherwise the LiteLLM gateway is driven with the shipped
        // dated default (which it rejects with HTTP 400). The callback
        // (scolta_amazee_persist_resolved_models) is guarded to only fill the
        // dated default / empty expansion model, so a user's explicit model
        // choice is never overwritten.
        $this->assertStringContainsString(
            "onModelsResolved: 'scolta_amazee_persist_resolved_models'",
            $this->pluginSource,
            'scolta_auto_provision_amazee() must persist resolved models via the guarded callback'
        );
        $this->assertStringContainsString(
            "hasResolvedModels: 'scolta_amazee_models_resolved'",
            $this->pluginSource,
            'scolta_auto_provision_amazee() must pass the hasResolvedModels predicate so a half-provision self-heals'
        );
        // The persistence helper must guard against clobbering an explicit model:
        // it only writes when the stored model is still the dated default.
        $this->assertMatchesRegularExpression(
            "/function scolta_amazee_persist_resolved_models.*?ai_model.*?\\?\\? \\\$default.*?=== \\\$default/s",
            $this->pluginSource,
            'scolta_amazee_persist_resolved_models() must only overwrite the dated default, preserving user config'
        );
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
