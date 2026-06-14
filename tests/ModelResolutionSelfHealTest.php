<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Tag1\Scolta\AiProvider\Amazee\AmazeeClient;
use Tag1\Scolta\AiProvider\Amazee\AutoProvisioner;
use Tag1\Scolta\Exception\ApiKeyMissingException;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Behavioral coverage for the Amazee model-resolution self-heal adoption.
 *
 * The Amazee provisioner stores credentials and resolves model names in two
 * steps. When the `/model/info` step fails, credentials are stored with no
 * resolved model: AutoProvisioner::ensureAiAvailable() no-opped forever on the
 * stored creds, from_options() drove the LiteLLM gateway with the shipped dated
 * default (claude-sonnet-4-5-20250929), and the gateway rejects that with HTTP
 * 400 — so summarize silently returned nothing and expand ran unexpanded.
 *
 * scolta_auto_provision_amazee() now passes a hasResolvedModels predicate (so
 * the library re-resolves against the stored key) and a guarded onModelsResolved
 * (so resolved names are persisted without clobbering admin config), and
 * from_options() degrades to a key-less client (HTTP 200) rather than sending
 * the gateway the dated default.
 */
class ModelResolutionSelfHealTest extends TestCase {

	private const MODEL_INFO_RESPONSE = '{"data": [{"model_name": "claude-sonnet-4-5"}, {"model_name": "claude-haiku-4-5"}]}';
	private const DATED_DEFAULT       = 'claude-sonnet-4-5-20250929';

	protected function set_up(): void {
		$GLOBALS['wp_options']           = array();
		$GLOBALS['test_user_meta']       = array();
		$GLOBALS['test_current_user_id'] = 1;
		putenv( 'SCOLTA_API_KEY' );
		unset( $_ENV['SCOLTA_API_KEY'], $_SERVER['SCOLTA_API_KEY'] );
	}

	// -------------------------------------------------------------------
	// The predicate: it must report FALSE for the dated default (no-op trap).
	// -------------------------------------------------------------------

	public function test_predicate_reports_unresolved_for_dated_default_and_empty(): void {
		// The dated default is exactly what AiClient ships as DEFAULT_MODEL.
		$this->assertSame( self::DATED_DEFAULT, \Tag1\Scolta\AiClient::DEFAULT_MODEL );

		$GLOBALS['wp_options'] = array();
		$this->assertFalse( scolta_amazee_models_resolved(), 'No settings => unresolved' );

		update_option( 'scolta_settings', array( 'ai_model' => '' ) );
		$this->assertFalse( scolta_amazee_models_resolved(), 'Empty model => unresolved' );

		update_option( 'scolta_settings', array( 'ai_model' => self::DATED_DEFAULT ) );
		$this->assertFalse(
			scolta_amazee_models_resolved(),
			'The shipped dated default must report unresolved, or the self-heal never fires'
		);
	}

	public function test_predicate_reports_resolved_for_real_model_name(): void {
		update_option( 'scolta_settings', array( 'ai_model' => 'claude-sonnet-4-5' ) );
		$this->assertTrue( scolta_amazee_models_resolved() );
	}

	// -------------------------------------------------------------------
	// Self-heal: the real AutoProvisioner, driven by the actual callbacks.
	// -------------------------------------------------------------------

	public function test_stored_creds_with_dated_default_self_heal(): void {
		// Half-provisioned: credentials stored, only the dated default persisted.
		update_option( 'scolta_settings', array( 'ai_model' => self::DATED_DEFAULT ) );
		$storage = new Scolta_Amazee_Config_Storage();
		$storage->store( 'stored-token', 'https://llm.test.amazee.ai', 'eu' );

		// Only /model/info is queued — provisioning a NEW trial would throw,
		// proving the heal re-resolves against the stored key, not a fresh trial.
		$client = $this->makeAmazeeClient( array( new Response( 200, array(), self::MODEL_INFO_RESPONSE ) ) );

		$provisioned = AutoProvisioner::ensureAiAvailable(
			$storage,
			hasExplicitApiKey: false,
			onModelsResolved: 'scolta_amazee_persist_resolved_models',
			client: $client,
			hasResolvedModels: 'scolta_amazee_models_resolved',
		);

		$this->assertFalse( $provisioned, 'A model-only heal is not a fresh-trial provision' );
		$settings = get_option( 'scolta_settings', array() );
		$this->assertSame( 'claude-sonnet-4-5', $settings['ai_model'], 'Dated default healed to a resolved model' );
		$this->assertSame( 'claude-haiku-4-5', $settings['ai_expansion_model'], 'Expansion model resolved too' );
		$this->assertTrue( scolta_amazee_models_resolved() );
	}

	public function test_naive_non_empty_predicate_would_not_heal(): void {
		// Documents the trap: a "model is non-empty" predicate reports TRUE in
		// the dated-default state, so AutoProvisioner no-ops and the bug ships.
		update_option( 'scolta_settings', array( 'ai_model' => self::DATED_DEFAULT ) );
		$storage = new Scolta_Amazee_Config_Storage();
		$storage->store( 'stored-token', 'https://llm.test.amazee.ai', 'eu' );

		// No responses queued: any Amazee call would throw. The naive predicate
		// must keep ensureAiAvailable a no-op.
		$client = $this->makeAmazeeClient( array() );

		AutoProvisioner::ensureAiAvailable(
			$storage,
			hasExplicitApiKey: false,
			onModelsResolved: 'scolta_amazee_persist_resolved_models',
			client: $client,
			hasResolvedModels: static fn (): bool => ! empty( get_option( 'scolta_settings', array() )['ai_model'] ),
		);

		$this->assertSame(
			self::DATED_DEFAULT,
			get_option( 'scolta_settings', array() )['ai_model'],
			'A naive non-empty predicate leaves the dated default in place (the bug)'
		);
	}

	public function test_persist_does_not_clobber_user_configured_model(): void {
		// An explicit admin model choice must survive auto-resolution.
		update_option( 'scolta_settings', array( 'ai_model' => 'my-custom-model' ) );

		scolta_amazee_persist_resolved_models( 'claude-sonnet-4-5', 'claude-haiku-4-5' );

		$this->assertSame(
			'my-custom-model',
			get_option( 'scolta_settings', array() )['ai_model'],
			'A user-configured model must never be overwritten by resolved defaults'
		);
	}

	// -------------------------------------------------------------------
	// Degrade: a model-unresolved Amazee install never sends the gateway the
	// dated default — the client throws ApiKeyMissingException (HTTP 200 path).
	// -------------------------------------------------------------------

	public function test_unresolved_amazee_client_throws_instead_of_calling_gateway(): void {
		if ( defined( 'SCOLTA_API_KEY' ) && constant( 'SCOLTA_API_KEY' ) !== '' ) {
			$this->markTestSkipped( 'SCOLTA_API_KEY constant defined by a prior test; cannot exercise the Amazee degrade path.' );
		}
		update_option( 'scolta_settings', array( 'ai_model' => self::DATED_DEFAULT ) );
		$storage = new Scolta_Amazee_Config_Storage();
		$storage->store( 'litellm-token', 'https://llm.test.amazee.ai', 'eu' );

		$service = Scolta_Ai_Service::from_options();

		// The degraded client is key-less: calling it throws ApiKeyMissingException
		// (which the REST controllers degrade to HTTP 200) before any HTTP call —
		// the dated default never reaches the gateway. No fetch stub is needed:
		// if the dated model were sent, this would attempt a real network call.
		$this->expectException( ApiKeyMissingException::class );
		$service->message( 'system', 'user' );
	}

	// -------------------------------------------------------------------
	// Structural: the wiring is present at the provisioning entry point.
	// -------------------------------------------------------------------

	public function test_auto_provision_wires_predicate_and_persistence(): void {
		$src = file_get_contents( dirname( __DIR__ ) . '/scolta.php' );
		$this->assertStringContainsString( "hasResolvedModels: 'scolta_amazee_models_resolved'", $src );
		$this->assertStringContainsString( "onModelsResolved: 'scolta_amazee_persist_resolved_models'", $src );
		$this->assertStringContainsString( 'AiClient::DEFAULT_MODEL', $src, 'Predicate must exclude the dated default' );
	}

	public function test_from_options_degrades_on_unresolved_model(): void {
		$src = file_get_contents( dirname( __DIR__ ) . '/includes/class-scolta-ai-service.php' );
		$this->assertStringContainsString( 'scolta_amazee_models_resolved()', $src );
		$this->assertMatchesRegularExpression(
			"/else\\s*\\{.*?unset\\(\\s*\\\$settings\\['ai_api_key'\\]\\s*\\)/s",
			$src,
			'from_options() must drop the key (degrade) when the model is unresolved'
		);
	}

	// -------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------

	private function makeAmazeeClient( array $responses ): AmazeeClient {
		return new AmazeeClient(
			'https://api.amazee.ai',
			new Client( array( 'handler' => HandlerStack::create( new MockHandler( $responses ) ) ) )
		);
	}
}
