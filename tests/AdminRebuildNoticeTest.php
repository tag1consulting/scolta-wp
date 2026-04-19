<?php

declare(strict_types=1);

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Tests for rebuild notice transient behavior.
 *
 * Verifies that rebuild notices are stored as transients (not query params),
 * persist across page loads until explicitly dismissed, and are properly
 * scoped per user.
 */
class AdminRebuildNoticeTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		delete_transient( 'scolta_rebuild_notice' );
		$GLOBALS['test_current_user_id'] = 1;
		$GLOBALS['test_user_meta']       = array();
	}

	public function tear_down(): void {
		delete_transient( 'scolta_rebuild_notice' );
		$GLOBALS['test_current_user_id'] = 1;
		$GLOBALS['test_user_meta']       = array();
		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// Basic rendering
	// -------------------------------------------------------------------------

	public function test_no_output_when_no_transient(): void {
		ob_start();
		Scolta_Admin::maybe_show_rebuild_notice();
		$output = ob_get_clean();

		$this->assertEmpty( $output );
	}

	public function test_success_notice_shows_page_count(): void {
		set_transient(
			'scolta_rebuild_notice',
			array(
				'result'    => 'ok',
				'pages'     => 42,
				'notice_id' => 'test-abc',
			),
			DAY_IN_SECONDS * 7
		);

		ob_start();
		Scolta_Admin::maybe_show_rebuild_notice();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice-success', $output );
		$this->assertStringContainsString( '42 pages indexed', $output );
	}

	public function test_error_notice(): void {
		set_transient( 'scolta_rebuild_notice', array( 'result' => 'error', 'notice_id' => 'err-1' ), DAY_IN_SECONDS * 7 );

		ob_start();
		Scolta_Admin::maybe_show_rebuild_notice();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice-error', $output );
		$this->assertStringContainsString( 'rebuild failed', $output );
	}

	public function test_no_content_notice(): void {
		set_transient( 'scolta_rebuild_notice', array( 'result' => 'no_content', 'notice_id' => 'nc-1' ), DAY_IN_SECONDS * 7 );

		ob_start();
		Scolta_Admin::maybe_show_rebuild_notice();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice-warning', $output );
		$this->assertStringContainsString( 'no published content', $output );
	}

	public function test_no_items_notice(): void {
		set_transient( 'scolta_rebuild_notice', array( 'result' => 'no_items', 'notice_id' => 'ni-1' ), DAY_IN_SECONDS * 7 );

		ob_start();
		Scolta_Admin::maybe_show_rebuild_notice();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice-warning', $output );
		$this->assertStringContainsString( 'content filter', $output );
	}

	public function test_handle_rebuild_now_does_not_use_query_params(): void {
		$source = file_get_contents(
			dirname( __DIR__ ) . '/admin/class-scolta-admin.php'
		);
		$this->assertDoesNotMatchRegularExpression(
			"/add_query_arg\s*\([^)]*scolta_rebuild/",
			$source,
			'handle_rebuild_now() must not pass rebuild result via query params — use set_transient()'
		);
	}

	// -------------------------------------------------------------------------
	// Persistence: required gap-closure tests (must fail pre-fix, pass post-fix)
	// -------------------------------------------------------------------------

	/**
	 * Pre-fix: delete_transient() on first render means second render is empty — FAIL.
	 * Post-fix: transient persists; all three renders show the notice — PASS.
	 */
	public function test_notice_persists_across_page_loads_until_dismissed(): void {
		set_transient(
			'scolta_rebuild_notice',
			array(
				'result'    => 'ok',
				'pages'     => 7,
				'notice_id' => 'persist-test',
			),
			DAY_IN_SECONDS * 7
		);

		ob_start(); Scolta_Admin::maybe_show_rebuild_notice(); $first = ob_get_clean();
		ob_start(); Scolta_Admin::maybe_show_rebuild_notice(); $second = ob_get_clean();
		ob_start(); Scolta_Admin::maybe_show_rebuild_notice(); $third = ob_get_clean();

		$this->assertNotEmpty( $first, 'First render must show notice' );
		$this->assertNotEmpty( $second, 'Second render must show notice — persists across page loads' );
		$this->assertNotEmpty( $third, 'Third render must show notice — persists across page loads' );
	}

	/**
	 * Pre-fix: no dismiss-check code; transient is shown before we can dismiss and
	 * immediately deleted, so the "after" check passes for the wrong reason.
	 * Framed to fail: we dismiss BEFORE any render. Pre-fix has no dismiss check,
	 * so the notice still shows. Post-fix respects the dismissal.
	 *
	 * Pre-fix: after "dismissal" via user meta, notice still renders (no check) — FAIL.
	 * Post-fix: dismissal check suppresses the notice — PASS.
	 */
	public function test_dismissed_notice_does_not_show(): void {
		set_transient(
			'scolta_rebuild_notice',
			array(
				'result'    => 'ok',
				'pages'     => 5,
				'notice_id' => 'dismiss-test',
			),
			DAY_IN_SECONDS * 7
		);

		// Dismiss BEFORE any render.
		update_user_meta( get_current_user_id(), 'scolta_dismissed_rebuild_notice', 'dismiss-test' );

		ob_start();
		Scolta_Admin::maybe_show_rebuild_notice();
		$output = ob_get_clean();

		$this->assertEmpty( $output, 'Notice must not render after the user has dismissed it' );
	}

	/**
	 * Pre-fix: no dismiss check — "after dismiss" render shows notice (delete-on-read makes
	 * $after_dismiss empty but for the wrong reason; assertEmpty passes accidentally).
	 * The critical assertion is the reappear after new rebuild — pre-fix has no notice_id
	 * concept, so the dismiss user_meta is ignored and the new rebuild notice shows correctly.
	 * BUT: the first assertEmpty($after_dismiss) fails pre-fix because pre-fix shows the
	 * notice instead of respecting the dismissal.
	 *
	 * Pre-fix: $after_dismiss is NOT empty (no dismiss check) — assertEmpty FAIL.
	 * Post-fix: both assertions pass — PASS.
	 */
	public function test_notice_reappears_after_new_rebuild(): void {
		// Rebuild 1.
		set_transient(
			'scolta_rebuild_notice',
			array(
				'result'    => 'ok',
				'pages'     => 5,
				'notice_id' => 'rebuild-1',
			),
			DAY_IN_SECONDS * 7
		);

		// Dismiss rebuild-1 before viewing it.
		update_user_meta( get_current_user_id(), 'scolta_dismissed_rebuild_notice', 'rebuild-1' );

		ob_start(); Scolta_Admin::maybe_show_rebuild_notice(); $after_dismiss = ob_get_clean();
		$this->assertEmpty( $after_dismiss, 'Dismissed notice must not show' );

		// Rebuild 2 with a different notice_id.
		delete_transient( 'scolta_rebuild_notice' );
		set_transient(
			'scolta_rebuild_notice',
			array(
				'result'    => 'ok',
				'pages'     => 8,
				'notice_id' => 'rebuild-2',
			),
			DAY_IN_SECONDS * 7
		);

		ob_start(); Scolta_Admin::maybe_show_rebuild_notice(); $after_rebuild2 = ob_get_clean();
		$this->assertNotEmpty( $after_rebuild2, 'New rebuild notice must show even after previous was dismissed' );
		$this->assertStringContainsString( '8 pages', $after_rebuild2 );
	}

	/**
	 * Pre-fix: delete_transient() on first render consumes the notice for everyone.
	 * User 2's render is empty because User 1's render already deleted it.
	 *
	 * Pre-fix: $output_user2 is empty — assertNotEmpty FAIL.
	 * Post-fix: transient persists; User 2 sees the notice — PASS.
	 */
	public function test_other_users_render_does_not_consume_notice(): void {
		set_transient(
			'scolta_rebuild_notice',
			array(
				'result'    => 'ok',
				'pages'     => 5,
				'notice_id' => 'shared-notice',
			),
			DAY_IN_SECONDS * 7
		);

		// User 1 renders (but does NOT dismiss).
		$GLOBALS['test_current_user_id'] = 1;
		ob_start(); Scolta_Admin::maybe_show_rebuild_notice(); $output_user1 = ob_get_clean();

		// User 2 must STILL see the notice.
		$GLOBALS['test_current_user_id'] = 2;
		ob_start(); Scolta_Admin::maybe_show_rebuild_notice(); $output_user2 = ob_get_clean();

		$this->assertNotEmpty( $output_user1, 'User 1 must see the notice' );
		$this->assertNotEmpty( $output_user2, 'User 2 must see the notice — User 1 viewing it must not consume it' );
	}
}
