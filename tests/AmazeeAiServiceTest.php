<?php

declare(strict_types=1);

use Yoast\PHPUnitPolyfills\TestCases\TestCase;
use Tag1\Scolta\AiProvider\Amazee\AmazeeBudgetExceededException;

/**
 * Tests for Amazee.ai integration in Scolta_Ai_Service.
 */
class AmazeeAiServiceTest extends TestCase {

    protected function set_up(): void {
        $GLOBALS['wp_options']       = [];
        $GLOBALS['test_user_meta']   = [];
        $GLOBALS['test_current_user_id'] = 1;
        putenv( 'SCOLTA_API_KEY' );
        unset( $_ENV['SCOLTA_API_KEY'], $_SERVER['SCOLTA_API_KEY'] );
    }

    public function test_from_options_uses_amazee_credentials_when_present(): void {
        if ( defined( 'SCOLTA_API_KEY' ) && constant( 'SCOLTA_API_KEY' ) !== '' ) {
            $this->markTestSkipped( 'SCOLTA_API_KEY constant defined by a prior test; cannot test Amazee fallback in same process.' );
        }
        $storage = new Scolta_Amazee_Config_Storage();
        $storage->store( 'litellm-token', 'https://api.amazee.test', 'us-east-1' );

        $service = Scolta_Ai_Service::from_options();
        $config  = $service->get_config();

        $this->assertSame( 'openai', $config->aiProvider );
        $this->assertSame( 'litellm-token', $config->aiApiKey );
        $this->assertSame( 'https://api.amazee.test', $config->aiBaseUrl );
    }

    public function test_from_options_uses_env_key_when_no_amazee_creds(): void {
        putenv( 'SCOLTA_API_KEY=env-test-key' );

        $service = Scolta_Ai_Service::from_options();
        $config  = $service->get_config();

        $this->assertSame( 'env-test-key', $config->aiApiKey );
        putenv( 'SCOLTA_API_KEY' );
    }

    public function test_is_amazee_active_true_when_credentials_stored(): void {
        if ( defined( 'SCOLTA_API_KEY' ) && constant( 'SCOLTA_API_KEY' ) !== '' ) {
            $this->markTestSkipped( 'SCOLTA_API_KEY constant defined by a prior test; Amazee is correctly not active when an explicit key exists.' );
        }
        $storage = new Scolta_Amazee_Config_Storage();
        $storage->store( 'tok', 'https://api.amazee.test', 'eu-west-1' );

        $service = Scolta_Ai_Service::from_options();
        $this->assertTrue( $service->is_amazee_active() );
    }

    public function test_is_amazee_active_false_without_credentials(): void {
        $service = Scolta_Ai_Service::from_options();
        $this->assertFalse( $service->is_amazee_active() );
    }

    public function test_get_api_key_source_returns_amazee_when_creds_present(): void {
        $storage = new Scolta_Amazee_Config_Storage();
        $storage->store( 'tok', 'https://api.amazee.test', 'ap-southeast-1' );

        $this->assertSame( 'amazee', Scolta_Ai_Service::get_api_key_source() );
    }

    public function test_get_api_key_source_returns_env_when_no_amazee(): void {
        putenv( 'SCOLTA_API_KEY=env-key' );
        $this->assertSame( 'env', Scolta_Ai_Service::get_api_key_source() );
        putenv( 'SCOLTA_API_KEY' );
    }

    public function test_has_message_override(): void {
        $this->assertTrue( method_exists( 'Scolta_Ai_Service', 'message' ) );
    }

    public function test_has_conversation_override(): void {
        $this->assertTrue( method_exists( 'Scolta_Ai_Service', 'conversation' ) );
    }

    public function test_has_message_for_operation_override(): void {
        $content = file_get_contents( dirname( __DIR__ ) . '/includes/class-scolta-ai-service.php' );
        $this->assertStringContainsString( 'public function messageForOperation(', $content );
    }

    public function test_imports_amazee_budget_exception(): void {
        $content = file_get_contents( dirname( __DIR__ ) . '/includes/class-scolta-ai-service.php' );
        $this->assertStringContainsString( 'AmazeeBudgetExceededException', $content );
    }

    public function test_budget_handler_property_in_source(): void {
        $content = file_get_contents( dirname( __DIR__ ) . '/includes/class-scolta-ai-service.php' );
        $this->assertStringContainsString( 'budget_handler', $content );
    }

    public function test_from_options_uses_db_key_over_amazee_credentials(): void {
        // Regression: users who configured their key via admin UI must never be
        // silently rerouted to the Amazee LiteLLM proxy.
        if ( defined( 'SCOLTA_API_KEY' ) && constant( 'SCOLTA_API_KEY' ) !== '' ) {
            $this->markTestSkipped( 'SCOLTA_API_KEY constant defined by a prior test; DB-vs-Amazee test requires no constant.' );
        }
        update_option( 'scolta_settings', array(
            'ai_provider' => 'anthropic',
            'ai_api_key'  => 'sk-ant-db-configured-key',
            'ai_model'    => 'claude-sonnet-4-5-20250929',
        ) );
        $storage = new Scolta_Amazee_Config_Storage();
        $storage->store( 'amazee-token', 'https://api.amazee.test', 'eu-west-1' );

        $service = Scolta_Ai_Service::from_options();
        $config  = $service->get_config();

        $this->assertSame( 'anthropic', $config->aiProvider );
        $this->assertSame( 'sk-ant-db-configured-key', $config->aiApiKey );
        $this->assertFalse( $service->is_amazee_active(), 'Amazee must not be active when explicit DB key exists' );

        $storage->clear();
    }

    public function test_from_options_uses_env_key_over_amazee_credentials(): void {
        // Regression: env var must win over stored Amazee credentials.
        putenv( 'SCOLTA_API_KEY=sk-ant-env-key' );
        $storage = new Scolta_Amazee_Config_Storage();
        $storage->store( 'amazee-token', 'https://api.amazee.test', 'us-east-1' );

        $service = Scolta_Ai_Service::from_options();
        $config  = $service->get_config();

        $this->assertSame( 'sk-ant-env-key', $config->aiApiKey );
        $this->assertFalse( $service->is_amazee_active(), 'Amazee must not be active when explicit env key exists' );

        putenv( 'SCOLTA_API_KEY' );
        $storage->clear();
    }

    public function test_has_explicit_api_key_returns_true_for_db_key(): void {
        // Regression: a key stored via admin UI must be recognised as explicit.
        if ( defined( 'SCOLTA_API_KEY' ) && constant( 'SCOLTA_API_KEY' ) !== '' ) {
            $this->markTestSkipped( 'SCOLTA_API_KEY constant defined by a prior test; DB key is correctly recognized as explicit regardless.' );
        }
        update_option( 'scolta_settings', array(
            'ai_api_key' => 'sk-ant-admin-ui-key',
        ) );
        $this->assertTrue( scolta_has_explicit_api_key() );
    }

    public function test_has_explicit_api_key_returns_false_with_no_key(): void {
        // Baseline: no env, no constant, no DB key → should not be "explicit".
        if ( defined( 'SCOLTA_API_KEY' ) && constant( 'SCOLTA_API_KEY' ) !== '' ) {
            $this->markTestSkipped( 'SCOLTA_API_KEY constant defined by a prior test; cannot test "no key" scenario.' );
        }
        $this->assertFalse( scolta_has_explicit_api_key() );
    }

    public function test_scolta_php_amazee_classes_present_in_vendor(): void {
        $vendorDir = dirname( __DIR__ ) . '/vendor/tag1/scolta-php/src/AiProvider/Amazee';
        $this->assertDirectoryExists( $vendorDir, 'tag1/scolta-php Amazee classes must exist in vendor' );
        $this->assertFileExists( $vendorDir . '/ConfigStorageInterface.php' );
        $this->assertFileExists( $vendorDir . '/AmazeeBudgetExceededException.php' );
        $this->assertFileExists( $vendorDir . '/AmazeeClient.php' );
        $this->assertFileExists( $vendorDir . '/AmazeeTrialProvisioner.php' );
        $this->assertFileExists( $vendorDir . '/AmazeeAccountUpgrader.php' );
    }
}
