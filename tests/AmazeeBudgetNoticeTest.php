<?php

declare(strict_types=1);

use Tag1\Scolta\AiProvider\Amazee\AmazeeBudgetExceededException;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Regression tests for the Amazee.ai budget-exceeded admin notice.
 *
 * The budget error fires during front-end/REST search requests, where the
 * admin_notices hook never runs. The original handler registered
 * admin_notices in that request (a no-op) while still setting the 24h
 * throttle transient — so the notice could never display and the throttle
 * suppressed every retry: the trial budget exhausted, visitors lost AI
 * search, and the administrator was never told.
 *
 * The fixed flow: handle() persists a pending-notice transient;
 * maybe_render_pending_notice() (hooked unconditionally in
 * Scolta_Admin::init()) renders and clears it on the next admin page load.
 */
class AmazeeBudgetNoticeTest extends TestCase {

	protected function set_up(): void {
		// Transients are backed by the options store in the test bootstrap.
		$GLOBALS['wp_options'] = [];
		unset( $GLOBALS['scolta_test_user_can'] );
	}

	protected function tear_down(): void {
		unset( $GLOBALS['scolta_test_user_can'] );
	}

	private function trigger_budget_event(): void {
		( new Scolta_Amazee_Budget_Handler() )->handle(
			new AmazeeBudgetExceededException( new \RuntimeException( 'Budget has been exceeded!' ) )
		);
	}

	private function render_pending_notice(): string {
		ob_start();
		Scolta_Amazee_Budget_Handler::maybe_render_pending_notice();
		return ob_get_clean();
	}

	// -------------------------------------------------------------------
	// handle() in a REST context persists the notice instead of hooking
	// admin_notices (which never fires there)
	// -------------------------------------------------------------------

	public function test_handle_persists_pending_notice_transient(): void {
		$this->trigger_budget_event();

		$this->assertNotFalse(
			get_transient( Scolta_Amazee_Budget_Handler::PENDING_TRANSIENT ),
			'handle() must persist a pending-notice transient for the next admin page load'
		);
	}

	public function test_handle_does_not_hook_admin_notices_directly(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-scolta-amazee-budget-handler.php' );
		$this->assertDoesNotMatchRegularExpression(
			"/function handle\([^}]*add_action\s*\(\s*'admin_notices'/s",
			$source,
			'handle() must not register admin_notices — it runs in REST requests where that hook never fires'
		);
	}

	// -------------------------------------------------------------------
	// The notice renders on a subsequent admin render, then clears
	// -------------------------------------------------------------------

	public function test_pending_notice_renders_on_next_admin_render(): void {
		$this->trigger_budget_event();

		$output = $this->render_pending_notice();

		$this->assertStringContainsString( 'notice-error', $output );
		$this->assertStringContainsString( 'budget has been exceeded', $output );
	}

	public function test_pending_notice_clears_after_first_render(): void {
		$this->trigger_budget_event();

		$this->render_pending_notice();

		$this->assertFalse(
			get_transient( Scolta_Amazee_Budget_Handler::PENDING_TRANSIENT ),
			'the pending transient must be deleted on first read (CONVENTIONS: transient-based UI state)'
		);
		$this->assertSame( '', $this->render_pending_notice(), 'a second render must produce nothing' );
	}

	public function test_no_pending_notice_renders_nothing(): void {
		$this->assertSame( '', $this->render_pending_notice() );
	}

	// -------------------------------------------------------------------
	// Only administrators see the notice — and a non-admin page view
	// must not consume it
	// -------------------------------------------------------------------

	public function test_non_admin_does_not_render_and_keeps_notice_pending(): void {
		$this->trigger_budget_event();

		$GLOBALS['scolta_test_user_can'] = false;
		$output                          = $this->render_pending_notice();
		unset( $GLOBALS['scolta_test_user_can'] );

		$this->assertSame( '', $output, 'non-admins must not see the budget notice' );
		$this->assertNotFalse(
			get_transient( Scolta_Amazee_Budget_Handler::PENDING_TRANSIENT ),
			'a non-admin page view must not consume the pending notice'
		);
		$this->assertNotSame( '', $this->render_pending_notice(), 'an admin must still see it afterwards' );
	}

	// -------------------------------------------------------------------
	// 24h throttle still applies to the event, not the render
	// -------------------------------------------------------------------

	public function test_second_event_within_throttle_window_is_dropped(): void {
		$this->trigger_budget_event();
		$this->render_pending_notice();

		$this->trigger_budget_event();

		$this->assertFalse(
			get_transient( Scolta_Amazee_Budget_Handler::PENDING_TRANSIENT ),
			'a second budget event inside the 24h throttle window must not queue another notice'
		);
	}

	// -------------------------------------------------------------------
	// Scolta_Admin::init() registers the unconditional render hook
	// -------------------------------------------------------------------

	public function test_admin_init_hooks_pending_notice_renderer(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/admin/class-scolta-admin.php' );
		$this->assertMatchesRegularExpression(
			"/add_action\s*\(\s*'admin_notices'\s*,\s*array\s*\(\s*Scolta_Amazee_Budget_Handler::class\s*,\s*'maybe_render_pending_notice'/",
			$source,
			'Scolta_Admin::init() must hook maybe_render_pending_notice unconditionally on admin_notices'
		);
	}
}
