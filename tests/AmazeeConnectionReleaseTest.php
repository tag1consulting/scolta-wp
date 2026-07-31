<?php

declare(strict_types=1);

use Tag1\Scolta\AiProvider\Amazee\KeyExpiryRecovery;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Tests for releasing the managed-gateway connection when the site leaves it.
 *
 * A site that configures its own API key, or picks a different AI provider,
 * runs on that key: the stored gateway credentials serve nothing from that
 * point on. They must not linger, and the reconnect prompt — which exists to
 * repair the gateway path — must not appear on a site that no longer uses it.
 */
class AmazeeConnectionReleaseTest extends TestCase {

	protected function set_up(): void {
		$GLOBALS['wp_options'] = array();
		unset( $GLOBALS['scolta_test_user_can'] );
	}

	protected function tear_down(): void {
		$GLOBALS['wp_options'] = array();
		unset( $GLOBALS['scolta_test_user_can'] );
	}

	/**
	 * Store credentials so the site is on the managed-gateway path.
	 */
	private function store_credentials(): void {
		( new Scolta_Amazee_Config_Storage() )->store( 'sk-stored-token', 'https://llm.test.amazee.ai', 'test-region' );
	}

	/**
	 * Build a KeyExpiryRecovery over the same marker store the handler reads.
	 */
	private function recovery(): KeyExpiryRecovery {
		return new KeyExpiryRecovery(
			storage: new Scolta_Amazee_Config_Storage(),
			cache: new Scolta_Cache_Driver(),
		);
	}

	/**
	 * Seed settings and run the save-time sync the way the option hook does.
	 *
	 * @param array<string, mixed> $before Settings before the save.
	 * @param array<string, mixed> $after  Settings after the save.
	 */
	private function save_settings( array $before, array $after ): void {
		update_option( 'scolta_settings', $after );
		scolta_sync_amazee_connection_state( $before, $after );
	}

	// -------------------------------------------------------------------
	// Moving to an explicit key
	// -------------------------------------------------------------------

	public function test_configuring_an_explicit_key_clears_credentials_and_markers(): void {
		$this->store_credentials();
		$this->recovery()->flagUpgradeNeeded();
		update_option(
			'scolta_settings',
			array(
				'ai_provider'            => 'amazee',
				'amazee_model'           => 'claude-4-5-sonnet',
				'amazee_expansion_model' => 'claude-3-5-haiku',
			)
		);

		$after = array(
			'ai_provider'            => 'amazee',
			'ai_api_key'             => 'sk-operator-key',
			'amazee_model'           => 'claude-4-5-sonnet',
			'amazee_expansion_model' => 'claude-3-5-haiku',
		);
		$this->save_settings( array( 'ai_provider' => 'amazee' ), $after );

		$this->assertNull(
			( new Scolta_Amazee_Config_Storage() )->load(),
			'A site with its own key must not carry stored gateway credentials'
		);
		$this->assertFalse( $this->recovery()->isUpgradeNeeded(), 'The reconnect marker must be cleared' );

		$settings = get_option( 'scolta_settings', array() );
		$this->assertSame( '', $settings['amazee_model'], 'Gateway-scoped model names must be cleared' );
		$this->assertSame( '', $settings['amazee_expansion_model'], 'Gateway-scoped model names must be cleared' );
	}

	// -------------------------------------------------------------------
	// Switching provider
	// -------------------------------------------------------------------

	public function test_switching_provider_away_clears_credentials_and_markers(): void {
		$this->store_credentials();
		$this->recovery()->flagUpgradeNeeded();

		$this->save_settings(
			array( 'ai_provider' => 'amazee' ),
			array( 'ai_provider' => 'anthropic' )
		);

		$this->assertNull(
			( new Scolta_Amazee_Config_Storage() )->load(),
			'Switching provider must release the stored gateway credentials'
		);
		$this->assertFalse( $this->recovery()->isUpgradeNeeded(), 'The reconnect marker must be cleared' );
	}

	public function test_saving_settings_on_the_gateway_keeps_the_connection(): void {
		$this->store_credentials();

		$this->save_settings(
			array( 'ai_provider' => 'amazee' ),
			array(
				'ai_provider'    => 'amazee',
				'excerpt_length' => 250,
			)
		);

		$this->assertNotNull(
			( new Scolta_Amazee_Config_Storage() )->load(),
			'An unrelated settings save must not disturb the connection'
		);
	}

	public function test_saving_settings_with_no_connection_stored_is_a_no_op(): void {
		$this->save_settings(
			array( 'ai_provider' => 'amazee' ),
			array( 'ai_provider' => 'openai' )
		);

		$this->assertNull( ( new Scolta_Amazee_Config_Storage() )->load() );
	}

	// -------------------------------------------------------------------
	// The reconnect notice follows the active AI path
	// -------------------------------------------------------------------

	public function test_reconnect_notice_shows_while_the_gateway_is_the_active_path(): void {
		$this->store_credentials();
		update_option( 'scolta_settings', array( 'amazee_model' => 'claude-4-5-sonnet' ) );
		$this->recovery()->flagUpgradeNeeded();

		$this->assertTrue( Scolta_Amazee_Reauth_Handler::is_reauth_needed() );
	}

	public function test_no_reconnect_notice_when_an_explicit_key_serves_requests(): void {
		$this->store_credentials();
		update_option(
			'scolta_settings',
			array(
				'amazee_model' => 'claude-4-5-sonnet',
				'ai_api_key'   => 'sk-operator-key',
			)
		);
		$this->recovery()->flagUpgradeNeeded();

		$this->assertFalse(
			Scolta_Amazee_Reauth_Handler::is_reauth_needed(),
			'A site running on its own key must not be asked to reconnect the gateway'
		);

		ob_start();
		Scolta_Amazee_Reauth_Handler::maybe_render_pending_notice();
		$this->assertSame( '', ob_get_clean(), 'No notice may render on the explicit-key path' );
	}
}
