<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Tag1\Scolta\AiProvider\Amazee\AmazeeClient;
use Tag1\Scolta\AiProvider\Amazee\KeyExpiryRecovery;
use Tag1\Scolta\Config\ScoltaConfig;
use Tag1\Scolta\Health\HealthChecker;
use Tag1\Scolta\Service\AiServiceAdapter;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Behavioral coverage for the Amazee key-expiry recovery wiring.
 *
 * Regression (django demo, 2026-06-09): an expired Amazee trial key returned
 * 400 expired_key while the adapter no-opped on the stored dead credentials —
 * expand echoed the query and /health still reported ai_configured: true.
 * Scolta_Ai_Service::from_options() now wires scolta-php's KeyExpiryRecovery on
 * the auto-provisioned path, and the health endpoint hands the same transient
 * store to HealthChecker.
 *
 * scolta-php's AiServiceAdapterTest proves the base recover-and-retry loop;
 * these tests prove the WordPress-specific wiring: recovery is wired only on
 * the Amazee path, the transient cache bridge satisfies KeyExpiryRecovery's
 * marker contract, and health stays truthful.
 */
class KeyExpiryRecoveryWiringTest extends TestCase {

	private const FRESH_TRIAL_RESPONSE = '{"key": {"litellm_token": "sk-fresh-token", "litellm_api_url": "https://llm.test.amazee.ai", "region": "test-region"}}';
	private const MODEL_INFO_RESPONSE  = '{"data": [{"model_name": "claude-sonnet-4-5"}, {"model_name": "claude-haiku-4-5"}]}';

	protected function set_up(): void {
		$GLOBALS['wp_options']           = array();
		$GLOBALS['test_user_meta']       = array();
		$GLOBALS['test_current_user_id'] = 1;
		putenv( 'SCOLTA_API_KEY' );
		unset( $_ENV['SCOLTA_API_KEY'], $_SERVER['SCOLTA_API_KEY'] );
	}

	// -------------------------------------------------------------------
	// from_options() wires recovery only on the auto-provisioned path
	// -------------------------------------------------------------------

	public function test_recovery_wired_when_amazee_credentials_present(): void {
		if ( defined( 'SCOLTA_API_KEY' ) && constant( 'SCOLTA_API_KEY' ) !== '' ) {
			$this->markTestSkipped( 'SCOLTA_API_KEY constant defined by a prior test; cannot exercise the Amazee path.' );
		}
		$storage = new Scolta_Amazee_Config_Storage();
		$storage->store( 'sk-expired-token', 'https://llm.test.amazee.ai', 'test-region' );

		$service = Scolta_Ai_Service::from_options();

		$this->assertInstanceOf(
			KeyExpiryRecovery::class,
			$this->wiredRecovery( $service ),
			'Recovery must be wired on the auto-provisioned Amazee path'
		);
	}

	public function test_recovery_not_wired_for_explicit_key(): void {
		// An explicit env key failing auth is the user's to fix — the adapter
		// must not silently re-provision an Amazee trial behind it.
		putenv( 'SCOLTA_API_KEY=sk-explicit-user-key' );

		$service = Scolta_Ai_Service::from_options();

		$this->assertNull(
			$this->wiredRecovery( $service ),
			'Recovery must not be wired when an explicit key is configured'
		);
		putenv( 'SCOLTA_API_KEY' );
	}

	public function test_recovery_not_wired_without_credentials(): void {
		if ( defined( 'SCOLTA_API_KEY' ) && constant( 'SCOLTA_API_KEY' ) !== '' ) {
			$this->markTestSkipped( 'SCOLTA_API_KEY constant defined by a prior test; the no-credentials path is unreachable.' );
		}
		$service = Scolta_Ai_Service::from_options();

		$this->assertNull(
			$this->wiredRecovery( $service ),
			'With neither a key nor stored credentials there is nothing to recover'
		);
	}

	// -------------------------------------------------------------------
	// Recovery once-per-window through the WordPress transient bridge
	// -------------------------------------------------------------------

	public function test_recovery_reprovisions_once_per_window_through_transient_bridge(): void {
		$storage = new Scolta_Amazee_Config_Storage();
		$storage->store( 'sk-expired-token', 'https://llm.test.amazee.ai', 'test-region' );

		$recovery = new KeyExpiryRecovery(
			storage: $storage,
			cache: new Scolta_Cache_Driver(),
			client: $this->makeAmazeeClient(
				array(
					new Response( 200, array(), self::FRESH_TRIAL_RESPONSE ),
					new Response( 200, array(), self::MODEL_INFO_RESPONSE ),
				),
				$mock
			),
		);

		$first = $recovery->handleAuthFailure( new \RuntimeException( 'code: expired_key' ) );

		$this->assertTrue( $first, 'An expired key triggers a re-provision' );
		$this->assertSame( 'sk-fresh-token', $recovery->credentials()['litellm_token'], 'Fresh credentials stored' );
		$this->assertFalse( $recovery->isAuthFailing(), 'Successful recovery clears the marker via transients' );
		$this->assertSame( 0, $mock->count(), 'Both provisioning calls (trial + models) ran' );

		// A second failure inside the window must not hit the provisioning API
		// again — the MockHandler queue is empty, so any call would throw.
		$second = $recovery->handleAuthFailure( new \RuntimeException( 'code: expired_key' ) );
		$this->assertFalse( $second, 'The window guard (read through transients) blocks a second attempt' );
	}

	public function test_record_auth_failure_is_visible_through_transient_bridge(): void {
		$cache    = new Scolta_Cache_Driver();
		$recovery = new KeyExpiryRecovery(
			storage: new Scolta_Amazee_Config_Storage(),
			cache: $cache,
		);

		$this->assertFalse( $recovery->isAuthFailing() );

		$recovery->recordAuthFailure();

		$this->assertTrue( $recovery->isAuthFailing(), 'Marker round-trips through the WordPress transient store' );
	}

	// -------------------------------------------------------------------
	// Health truthfulness through the transient bridge
	// -------------------------------------------------------------------

	public function test_health_reports_auth_failing_when_marker_set(): void {
		$cache = new Scolta_Cache_Driver();
		( new KeyExpiryRecovery( new Scolta_Amazee_Config_Storage(), $cache ) )->recordAuthFailure();

		$result = $this->runHealthCheck( $cache );

		$this->assertTrue( $result['ai_configured'], 'Credentials are still present' );
		$this->assertTrue( $result['ai_auth_failing'], 'The recorded marker must surface' );
		$this->assertFalse( $result['ai_usable'], 'Known-expired credentials must not report usable' );
		$this->assertSame( 'degraded', $result['status'] );
	}

	public function test_health_reports_usable_when_no_marker(): void {
		$result = $this->runHealthCheck( new Scolta_Cache_Driver() );

		$this->assertTrue( $result['ai_configured'] );
		$this->assertFalse( $result['ai_auth_failing'] );
		$this->assertTrue( $result['ai_usable'], 'A configured, non-failing key is usable' );
	}

	// -------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------

	/**
	 * Read the base adapter's private keyRecovery to assert wiring.
	 *
	 * @param AiServiceAdapter $service The adapter to inspect.
	 * @return KeyExpiryRecovery|null The wired recovery, or null.
	 */
	private function wiredRecovery( AiServiceAdapter $service ): ?KeyExpiryRecovery {
		// Accessible without setAccessible() since PHP 8.1.
		$prop = new \ReflectionProperty( AiServiceAdapter::class, 'keyRecovery' );
		return $prop->getValue( $service );
	}

	/**
	 * Run a HealthChecker for a configured Amazee install with the given cache.
	 *
	 * @param \Tag1\Scolta\Cache\CacheDriverInterface $cache The cache bridge.
	 * @return array The health-check result.
	 */
	private function runHealthCheck( $cache ): array {
		$config = ScoltaConfig::fromArray(
			array(
				'ai_provider' => 'openai',
				'ai_api_key'  => 'sk-amazee-litellm-token',
			)
		);
		$checker = new HealthChecker(
			config: $config,
			indexOutputDir: sys_get_temp_dir(),
			pagefindBinaryPath: null,
			projectDir: null,
			cache: $cache,
		);

		return $checker->check();
	}

	/**
	 * Build an AmazeeClient backed by a MockHandler queue.
	 *
	 * @param array            $responses Queued Guzzle responses.
	 * @param MockHandler|null $mock      Receives the handler for count asserts.
	 * @return AmazeeClient The stubbed control-plane client.
	 */
	private function makeAmazeeClient( array $responses, ?MockHandler &$mock = null ): AmazeeClient {
		$mock = new MockHandler( $responses );
		return new AmazeeClient(
			'https://api.amazee.ai',
			new Client( array( 'handler' => HandlerStack::create( $mock ) ) )
		);
	}
}
