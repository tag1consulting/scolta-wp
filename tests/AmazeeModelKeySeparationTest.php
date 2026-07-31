<?php

declare(strict_types=1);

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Gateway-resolved model names must not share a key with operator-chosen ones.
 *
 * Amazee model resolution returns LiteLLM gateway aliases (`claude-4-5-sonnet`)
 * that only the Amazee proxy accepts. They used to be written into
 * `scolta_settings['ai_model']` — the key an administrator uses to name a
 * provider-native model. Once the trial expired or a direct provider key was
 * configured, `ai_provider` became `anthropic` while `ai_model` still held a
 * name Anthropic does not recognise, and AI degraded permanently behind a
 * generic `ai_error`.
 *
 * These tests build a real Scolta_Ai_Service over the test site's own options,
 * so they assert the model that would actually be sent rather than the shape of
 * the source that computes it.
 */
class AmazeeModelKeySeparationTest extends TestCase {

	private const GATEWAY_ALIAS           = 'claude-4-5-sonnet';
	private const GATEWAY_EXPANSION_ALIAS = 'claude-4-5-haiku';
	private const NATIVE_MODEL            = 'claude-sonnet-4-5-20250929';

	protected function set_up(): void {
		$GLOBALS['wp_options']           = array();
		$GLOBALS['test_user_meta']       = array();
		$GLOBALS['test_current_user_id'] = 1;
		putenv( 'SCOLTA_API_KEY' );
		unset( $_ENV['SCOLTA_API_KEY'], $_SERVER['SCOLTA_API_KEY'] );
	}

	protected function tear_down(): void {
		putenv( 'SCOLTA_API_KEY' );
		unset( $_ENV['SCOLTA_API_KEY'], $_SERVER['SCOLTA_API_KEY'] );
	}

	private function skip_if_key_constant_defined(): void {
		if ( defined( 'SCOLTA_API_KEY' ) && constant( 'SCOLTA_API_KEY' ) !== '' ) {
			$this->markTestSkipped( 'SCOLTA_API_KEY constant defined by a prior test; cannot exercise credential precedence in the same process.' );
		}
	}

	private function store_amazee_credentials(): void {
		( new Scolta_Amazee_Config_Storage() )->store(
			'litellm-token',
			'https://llm.test.amazee.ai',
			'eu'
		);
	}

	// -------------------------------------------------------------------
	// The transition that broke Athenaeum.
	// -------------------------------------------------------------------

	public function test_expire_then_switch_leaves_ai_working(): void {
		$this->skip_if_key_constant_defined();

		// 1. Provisioned onto Amazee, model resolved. The gateway alias is what
		//    reaches the gateway.
		$this->store_amazee_credentials();
		scolta_amazee_persist_resolved_models( self::GATEWAY_ALIAS, self::GATEWAY_EXPANSION_ALIAS );

		$config = Scolta_Ai_Service::from_options()->get_config();
		$this->assertSame( 'openai', $config->aiProvider );
		$this->assertSame( self::GATEWAY_ALIAS, $config->aiModel );

		// 2. The trial expires / an operator supplies a direct provider key.
		//    The stored Amazee credentials stop being the effective key.
		putenv( 'SCOLTA_API_KEY=operator-anthropic-key' );

		$config = Scolta_Ai_Service::from_options()->get_config();

		// 3. The regression: AI still works, because the direct provider gets a
		//    provider-native model and never the retained gateway alias.
		$this->assertSame( 'operator-anthropic-key', $config->aiApiKey );
		$this->assertSame(
			self::NATIVE_MODEL,
			$config->aiModel,
			'a direct provider key must never be driven with a retained gateway alias'
		);
		$this->assertNotSame( self::GATEWAY_ALIAS, $config->aiModel );
	}

	public function test_switching_back_to_amazee_restores_the_alias(): void {
		$this->skip_if_key_constant_defined();

		$this->store_amazee_credentials();
		scolta_amazee_persist_resolved_models( self::GATEWAY_ALIAS, '' );

		putenv( 'SCOLTA_API_KEY=operator-anthropic-key' );
		$this->assertSame( self::NATIVE_MODEL, Scolta_Ai_Service::from_options()->get_config()->aiModel );

		// The gateway key is retained but not consulted, so flipping back needs
		// no re-provisioning.
		putenv( 'SCOLTA_API_KEY' );
		$this->assertSame( self::GATEWAY_ALIAS, Scolta_Ai_Service::from_options()->get_config()->aiModel );
	}

	public function test_an_explicit_operator_model_survives_a_resolution_run(): void {
		$this->skip_if_key_constant_defined();

		update_option( 'scolta_settings', array( 'ai_model' => 'my-custom-model' ) );
		$this->store_amazee_credentials();

		scolta_amazee_persist_resolved_models( self::GATEWAY_ALIAS, self::GATEWAY_EXPANSION_ALIAS );

		putenv( 'SCOLTA_API_KEY=operator-anthropic-key' );
		$this->assertSame(
			'my-custom-model',
			Scolta_Ai_Service::from_options()->get_config()->aiModel
		);
	}

	public function test_operator_expansion_model_does_not_leak_to_the_gateway(): void {
		$this->skip_if_key_constant_defined();

		update_option(
			'scolta_settings',
			array(
				'ai_model'           => self::NATIVE_MODEL,
				'ai_expansion_model' => 'claude-haiku-4-5-20251001',
			)
		);
		$this->store_amazee_credentials();
		scolta_amazee_persist_resolved_models( self::GATEWAY_ALIAS, '' );

		$config = Scolta_Ai_Service::from_options()->get_config();

		$this->assertSame( self::GATEWAY_ALIAS, $config->aiModel );
		$this->assertSame(
			'',
			$config->aiExpansionModel,
			'a native expansion model must not be sent to the gateway, which would reject it'
		);
	}

	// -------------------------------------------------------------------
	// Config surface.
	// -------------------------------------------------------------------

	public function test_install_defaults_declare_both_gateway_keys(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/scolta.php' );

		$this->assertStringContainsString( "'amazee_model'", $source );
		$this->assertStringContainsString( "'amazee_expansion_model'", $source );
	}

	public function test_gateway_keys_have_no_settings_form_field(): void {
		// Deliberate: there is nothing for an administrator to choose, and a
		// field would recreate exactly the alias-versus-native-ID confusion
		// this split exists to remove.
		$source = file_get_contents( dirname( __DIR__ ) . '/admin/class-scolta-admin.php' );

		$this->assertStringNotContainsString( "add_settings_field( 'amazee_model'", $source );
		$this->assertStringNotContainsString( "add_settings_field( 'amazee_expansion_model'", $source );
	}

	public function test_saving_settings_preserves_the_gateway_keys(): void {
		// sanitize_settings() rebuilds the option from scratch, so a key with
		// no form field is dropped unless it is explicitly carried over. Left
		// unfixed, any routine settings save would wipe the resolved model and
		// send the gateway the shipped default it rejects with HTTP 400.
		update_option(
			'scolta_settings',
			array(
				'amazee_model'           => self::GATEWAY_ALIAS,
				'amazee_expansion_model' => self::GATEWAY_EXPANSION_ALIAS,
			)
		);

		$clean = Scolta_Admin::sanitize_settings( array( 'ai_model' => self::NATIVE_MODEL ) );

		$this->assertSame( self::GATEWAY_ALIAS, $clean['amazee_model'] );
		$this->assertSame( self::GATEWAY_EXPANSION_ALIAS, $clean['amazee_expansion_model'] );
		$this->assertSame( self::NATIVE_MODEL, $clean['ai_model'] );
	}

	public function test_saving_settings_cannot_inject_a_gateway_model_from_the_form(): void {
		update_option( 'scolta_settings', array( 'amazee_model' => self::GATEWAY_ALIAS ) );

		$clean = Scolta_Admin::sanitize_settings(
			array(
				'ai_model'     => self::NATIVE_MODEL,
				'amazee_model' => 'injected-from-the-form',
			)
		);

		$this->assertSame(
			self::GATEWAY_ALIAS,
			$clean['amazee_model'],
			'the gateway keys come from stored state, never from form input'
		);
	}

	// -------------------------------------------------------------------
	// The upgrade path.
	// -------------------------------------------------------------------

	public function test_migration_repairs_a_poisoned_credentialed_site(): void {
		update_option(
			'scolta_settings',
			array(
				'ai_model'           => self::GATEWAY_ALIAS,
				'ai_expansion_model' => self::GATEWAY_EXPANSION_ALIAS,
			)
		);
		$this->store_amazee_credentials();

		scolta_migrate_amazee_model_key();

		$settings = get_option( 'scolta_settings', array() );
		$this->assertSame( self::GATEWAY_ALIAS, $settings['amazee_model'], 'the alias moves, it is never discarded' );
		$this->assertSame( self::GATEWAY_EXPANSION_ALIAS, $settings['amazee_expansion_model'] );
		$this->assertSame( self::NATIVE_MODEL, $settings['ai_model'], 'ai_model resets to the shipped default' );
		$this->assertSame( '', $settings['ai_expansion_model'] );
	}

	public function test_migrated_site_serves_the_alias_to_the_gateway_and_the_default_to_a_direct_key(): void {
		$this->skip_if_key_constant_defined();

		update_option( 'scolta_settings', array( 'ai_model' => self::GATEWAY_ALIAS ) );
		$this->store_amazee_credentials();

		scolta_migrate_amazee_model_key();

		// Still on Amazee: the alias is restored to the request path.
		$this->assertSame( self::GATEWAY_ALIAS, Scolta_Ai_Service::from_options()->get_config()->aiModel );

		// Switched to a direct key: the poisoning is gone.
		putenv( 'SCOLTA_API_KEY=operator-anthropic-key' );
		$this->assertSame( self::NATIVE_MODEL, Scolta_Ai_Service::from_options()->get_config()->aiModel );
	}

	public function test_migration_leaves_an_uncredentialed_site_alone(): void {
		// Without stored credentials there is no way to tell an orphaned alias
		// from an explicit administrator choice, and resetting it would
		// recreate the bug being fixed.
		update_option( 'scolta_settings', array( 'ai_model' => 'my-custom-model' ) );

		scolta_migrate_amazee_model_key();

		$settings = get_option( 'scolta_settings', array() );
		$this->assertSame( 'my-custom-model', $settings['ai_model'] );
		$this->assertSame( '', $settings['amazee_model'] );
	}

	public function test_migration_does_not_move_the_shipped_default(): void {
		update_option( 'scolta_settings', array( 'ai_model' => self::NATIVE_MODEL ) );
		$this->store_amazee_credentials();

		scolta_migrate_amazee_model_key();

		$settings = get_option( 'scolta_settings', array() );
		$this->assertSame( self::NATIVE_MODEL, $settings['ai_model'] );
		$this->assertSame( '', $settings['amazee_model'], 'the default is not a resolved model' );
	}

	public function test_migration_leaves_an_already_split_site_alone(): void {
		update_option(
			'scolta_settings',
			array(
				'ai_model'     => 'my-custom-model',
				'amazee_model' => self::GATEWAY_ALIAS,
			)
		);
		$this->store_amazee_credentials();

		scolta_migrate_amazee_model_key();

		$settings = get_option( 'scolta_settings', array() );
		$this->assertSame( 'my-custom-model', $settings['ai_model'] );
		$this->assertSame( self::GATEWAY_ALIAS, $settings['amazee_model'] );
	}

	public function test_migration_backfills_the_keys_everywhere(): void {
		update_option( 'scolta_settings', array( 'ai_model' => self::NATIVE_MODEL ) );

		scolta_migrate_amazee_model_key();

		$settings = get_option( 'scolta_settings', array() );
		$this->assertArrayHasKey( 'amazee_model', $settings );
		$this->assertArrayHasKey( 'amazee_expansion_model', $settings );
	}

	public function test_migration_is_a_no_op_on_a_second_run(): void {
		update_option( 'scolta_settings', array( 'ai_model' => self::GATEWAY_ALIAS ) );
		$this->store_amazee_credentials();

		scolta_migrate_amazee_model_key();
		$after_first = get_option( 'scolta_settings', array() );

		// A second run must not walk the now-default ai_model into the gateway
		// key, and must not fire on a site an administrator has since edited.
		update_option(
			'scolta_settings',
			array_merge( $after_first, array( 'ai_model' => 'my-custom-model' ) )
		);
		scolta_migrate_amazee_model_key();

		$settings = get_option( 'scolta_settings', array() );
		$this->assertSame( 'my-custom-model', $settings['ai_model'] );
		$this->assertSame( self::GATEWAY_ALIAS, $settings['amazee_model'] );
	}

	public function test_migration_sets_its_own_flag_rather_than_the_version_scalar(): void {
		scolta_migrate_amazee_model_key();

		$this->assertTrue( (bool) get_option( 'scolta_amazee_model_key_migrated', false ) );
	}

	// -------------------------------------------------------------------
	// The provider/model mismatch tripwire is inherited, not reimplemented.
	// -------------------------------------------------------------------

	public function test_ai_endpoints_route_through_the_shared_handler(): void {
		// The tripwire that names a provider/model mismatch (rather than
		// reporting a generic ai_error) lives in scolta-php's AiClient and
		// AiEndpointHandler, so this plugin inherits it with no code of its
		// own — but only for as long as the REST layer keeps delegating there
		// instead of catching provider failures itself.
		$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-scolta-rest-api.php' );

		$this->assertStringContainsString( 'AiEndpointHandler', $source );
		$this->assertStringContainsString( 'handleExpandQuery', $source );
		$this->assertStringContainsString( 'handleSummarize', $source );
	}
}
