<?php

declare(strict_types=1);

use Tag1\Scolta\AiProvider\Amazee\KeyExpiryRecovery;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Tests for the Amazee.ai re-authentication admin notice.
 *
 * When the stored Amazee.ai credentials are no longer accepted, scolta-php
 * records a persistent marker during AI requests. wp-admin must surface that
 * state with a clear path forward (reconnect/upgrade) on every admin page load
 * until the operator re-authenticates — the degraded state is never swallowed.
 */
class AmazeeReauthNoticeTest extends TestCase {

	protected function set_up(): void {
		// Transients are backed by the options store in the test bootstrap.
		$GLOBALS['wp_options'] = array();
		unset( $GLOBALS['scolta_test_user_can'] );
	}

	protected function tear_down(): void {
		unset( $GLOBALS['scolta_test_user_can'] );
	}

	/**
	 * Store Amazee.ai credentials so the install is on the Amazee.ai path.
	 */
	private function store_credentials(): void {
		( new Scolta_Amazee_Config_Storage() )->store( 'sk-stored-token', 'https://llm.test.amazee.ai', 'test-region' );
	}

	/**
	 * Build a KeyExpiryRecovery over the same transient bridge the handler uses.
	 */
	private function recovery(): KeyExpiryRecovery {
		return new KeyExpiryRecovery(
			storage: new Scolta_Amazee_Config_Storage(),
			cache: new Scolta_Cache_Driver(),
		);
	}

	/**
	 * Render the notice and capture its output.
	 */
	private function render_notice(): string {
		ob_start();
		Scolta_Amazee_Reauth_Handler::maybe_render_pending_notice();
		return ob_get_clean();
	}

	// -------------------------------------------------------------------
	// The notice renders with a continue-with-Amazee CTA when needed
	// -------------------------------------------------------------------

	public function test_notice_renders_with_cta_when_reauth_needed(): void {
		$this->store_credentials();
		$this->recovery()->flagUpgradeNeeded();

		$output = $this->render_notice();

		$this->assertStringContainsString( 'notice-warning', $output );
		$this->assertStringContainsString( 're-authenticated', $output );
		$this->assertStringContainsString( 'Continue with Amazee.ai', $output );
		$this->assertStringContainsString( 'page=scolta-amazee', $output, 'CTA must link to the Amazee.ai connection page' );
	}

	public function test_no_notice_when_reauth_not_needed(): void {
		$this->store_credentials();

		$this->assertSame( '', $this->render_notice(), 'A healthy connection shows no notice' );
	}

	public function test_no_notice_without_stored_credentials(): void {
		// No credentials stored: the install is not on the Amazee.ai path, so a
		// stray marker must not surface a prompt that leads nowhere.
		$this->recovery()->flagUpgradeNeeded();

		$this->assertSame( '', $this->render_notice() );
	}

	public function test_non_admin_sees_no_notice(): void {
		$this->store_credentials();
		$this->recovery()->flagUpgradeNeeded();

		$GLOBALS['scolta_test_user_can'] = false;
		$output                          = $this->render_notice();
		unset( $GLOBALS['scolta_test_user_can'] );

		$this->assertSame( '', $output, 'Only administrators see the re-authentication prompt' );
	}

	// -------------------------------------------------------------------
	// The notice persists until reconnect, then clears
	// -------------------------------------------------------------------

	public function test_notice_persists_across_renders(): void {
		$this->store_credentials();
		$this->recovery()->flagUpgradeNeeded();

		$this->assertNotSame( '', $this->render_notice() );
		$this->assertNotSame( '', $this->render_notice(), 'The notice is not consumed on render — it stays until reconnect' );
	}

	public function test_clear_removes_the_notice(): void {
		$this->store_credentials();
		$this->recovery()->flagUpgradeNeeded();
		$this->assertNotSame( '', $this->render_notice() );

		Scolta_Amazee_Reauth_Handler::clear();

		$this->assertSame( '', $this->render_notice(), 'A successful reconnect clears the prompt' );
	}

	// -------------------------------------------------------------------
	// Detect -> surface: an exhausted-key failure degrades AI and raises
	// the prompt rather than being swallowed
	// -------------------------------------------------------------------

	public function test_auth_failure_degrades_ai_and_raises_notice(): void {
		$this->store_credentials();

		// Simulate the auth-class failure scolta-php sees on the next AI call.
		$handled = $this->recovery()->handleAuthFailure( new \RuntimeException( 'code: expired_key' ) );

		$this->assertFalse( $handled, 'The failure is surfaced, not silently recovered' );
		$this->assertTrue( Scolta_Amazee_Reauth_Handler::is_reauth_needed(), 'The credential state is detected' );
		$this->assertStringContainsString(
			'Continue with Amazee.ai',
			$this->render_notice(),
			'A degraded-AI site must see the prompt and a path forward in wp-admin'
		);
	}

	// -------------------------------------------------------------------
	// Scolta_Admin::init() registers the unconditional render hook
	// -------------------------------------------------------------------

	public function test_admin_init_hooks_reauth_notice_renderer(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/admin/class-scolta-admin.php' );
		$this->assertMatchesRegularExpression(
			"/add_action\s*\(\s*'admin_notices'\s*,\s*array\s*\(\s*Scolta_Amazee_Reauth_Handler::class\s*,\s*'maybe_render_pending_notice'/",
			$source,
			'Scolta_Admin::init() must hook the re-authentication notice on admin_notices'
		);
	}

	public function test_amazee_admin_flow_clears_marker_on_reconnect(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/admin/class-scolta-amazee-admin-page.php' );
		$this->assertStringContainsString(
			'Scolta_Amazee_Reauth_Handler::clear();',
			$source,
			'The Amazee.ai connection flow must clear the marker once fresh credentials are stored'
		);
	}
}
