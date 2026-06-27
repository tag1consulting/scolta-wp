<?php

declare(strict_types=1);

use Tag1\Scolta\AiProvider\Amazee\KeyExpiryRecovery;
use Tag1\Scolta\Config\ScoltaConfig;
use Tag1\Scolta\Health\HealthChecker;
use Tag1\Scolta\Service\AiServiceAdapter;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Coverage for the Amazee.ai credential-recovery wiring.
 *
 * When the stored Amazee.ai credentials are no longer accepted, the next AI
 * call fails authentication. The adapter must degrade cleanly rather than
 * swallow it: record the failure so `/health` reports AI as degraded, set a
 * persistent marker so wp-admin prompts the operator to reconnect/upgrade, and
 * leave the stored credentials in place — no new connection is requested on
 * this path. scolta-php 1.0.5 owns that behaviour (its AiServiceAdapterTest
 * proves the base call-path); these tests prove the WordPress-specific wiring:
 * recovery is wired only on the Amazee.ai path, the transient cache bridge
 * satisfies KeyExpiryRecovery's marker contract, and health stays truthful.
 */
class AmazeeCredentialRecoveryTest extends TestCase {

	protected function set_up(): void {
		$GLOBALS['wp_options']           = array();
		$GLOBALS['test_user_meta']       = array();
		$GLOBALS['test_current_user_id'] = 1;
		putenv( 'SCOLTA_API_KEY' );
		unset( $_ENV['SCOLTA_API_KEY'], $_SERVER['SCOLTA_API_KEY'] );
	}

	// -------------------------------------------------------------------
	// from_options() wires recovery only on the Amazee.ai path
	// -------------------------------------------------------------------

	public function test_recovery_wired_when_amazee_credentials_present(): void {
		if ( defined( 'SCOLTA_API_KEY' ) && constant( 'SCOLTA_API_KEY' ) !== '' ) {
			$this->markTestSkipped( 'SCOLTA_API_KEY constant defined by a prior test; cannot exercise the Amazee path.' );
		}
		$storage = new Scolta_Amazee_Config_Storage();
		$storage->store( 'sk-stored-token', 'https://llm.test.amazee.ai', 'test-region' );

		$service = Scolta_Ai_Service::from_options();

		$this->assertInstanceOf(
			KeyExpiryRecovery::class,
			$this->wiredRecovery( $service ),
			'Recovery must be wired on the Amazee.ai path'
		);
	}

	public function test_recovery_not_wired_for_explicit_key(): void {
		// An explicit env key failing auth is the user's to fix — the adapter
		// must not touch the Amazee.ai credentials behind it.
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
	// An auth failure degrades and flags re-authentication; it never
	// re-connects and never swallows the failure
	// -------------------------------------------------------------------

	public function test_auth_failure_degrades_and_flags_reauth_leaving_credentials_in_place(): void {
		$storage = new Scolta_Amazee_Config_Storage();
		$storage->store( 'sk-stored-token', 'https://llm.test.amazee.ai', 'test-region' );

		$recovery = $this->makeRecovery( $storage );

		$handled = $recovery->handleAuthFailure( new \RuntimeException( 'code: expired_key' ) );

		$this->assertFalse( $handled, 'The failure is not silently recovered — the caller degrades gracefully' );
		$this->assertTrue( $recovery->isAuthFailing(), 'Health must report AI as degraded' );
		$this->assertTrue( $recovery->isUpgradeNeeded(), 'The admin must be prompted to re-authenticate' );
		$this->assertSame(
			'sk-stored-token',
			$recovery->credentials()['litellm_token'],
			'The stored credentials are left untouched — no new connection is requested'
		);
	}

	public function test_reauth_marker_round_trips_and_clears_through_transient_bridge(): void {
		$recovery = $this->makeRecovery();

		$this->assertFalse( $recovery->isUpgradeNeeded() );

		$recovery->flagUpgradeNeeded();
		$this->assertTrue( $recovery->isUpgradeNeeded(), 'Marker round-trips through the WordPress transient store' );

		$recovery->clearUpgradeNeeded();
		$this->assertFalse( $recovery->isUpgradeNeeded(), 'Clearing removes the re-authentication prompt' );
	}

	public function test_record_auth_failure_is_visible_through_transient_bridge(): void {
		$recovery = $this->makeRecovery();

		$this->assertFalse( $recovery->isAuthFailing() );

		$recovery->recordAuthFailure();

		$this->assertTrue( $recovery->isAuthFailing(), 'Marker round-trips through the WordPress transient store' );
	}

	// -------------------------------------------------------------------
	// Health truthfulness through the transient bridge
	// -------------------------------------------------------------------

	public function test_health_reports_auth_failing_when_marker_set(): void {
		$cache = new Scolta_Cache_Driver();
		$this->makeRecovery( null, $cache )->recordAuthFailure();

		$result = $this->runHealthCheck( $cache );

		$this->assertTrue( $result['ai_configured'], 'Credentials are still present' );
		$this->assertTrue( $result['ai_auth_failing'], 'The recorded marker must surface' );
		$this->assertFalse( $result['ai_usable'], 'Known-bad credentials must not report usable' );
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
	 * Build a KeyExpiryRecovery over the WordPress transient cache bridge.
	 *
	 * @param Scolta_Amazee_Config_Storage|null $storage Credential store (defaults to a fresh one).
	 * @param Scolta_Cache_Driver|null          $cache   Cache bridge (defaults to a fresh one).
	 * @return KeyExpiryRecovery The recovery helper under test.
	 */
	private function makeRecovery( ?Scolta_Amazee_Config_Storage $storage = null, ?Scolta_Cache_Driver $cache = null ): KeyExpiryRecovery {
		return new KeyExpiryRecovery(
			storage: $storage ?? new Scolta_Amazee_Config_Storage(),
			cache: $cache ?? new Scolta_Cache_Driver(),
		);
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
}
