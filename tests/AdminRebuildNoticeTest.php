<?php

declare(strict_types=1);

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Tests for rebuild notice transient behavior.
 *
 * Verifies that rebuild notices are stored as transients (not query params),
 * display exactly once, and are deleted after being read.
 */
class AdminRebuildNoticeTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		delete_transient( 'scolta_rebuild_notice' );
	}

	public function tear_down(): void {
		delete_transient( 'scolta_rebuild_notice' );
		parent::tear_down();
	}

	public function test_no_output_when_no_transient(): void {
		ob_start();
		Scolta_Admin::maybe_show_rebuild_notice();
		$output = ob_get_clean();

		$this->assertEmpty( $output );
	}

	public function test_success_notice_shows_page_count(): void {
		set_transient( 'scolta_rebuild_notice', array(
			'result' => 'ok',
			'pages'  => 42,
		), 60 );

		ob_start();
		Scolta_Admin::maybe_show_rebuild_notice();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice-success', $output );
		$this->assertStringContainsString( '42 pages indexed', $output );
	}

	public function test_transient_deleted_after_display(): void {
		set_transient( 'scolta_rebuild_notice', array(
			'result' => 'ok',
			'pages'  => 10,
		), 60 );

		ob_start();
		Scolta_Admin::maybe_show_rebuild_notice();
		ob_get_clean();

		$this->assertFalse(
			get_transient( 'scolta_rebuild_notice' ),
			'Transient must be deleted after the notice is displayed'
		);
	}

	public function test_notice_does_not_repeat(): void {
		set_transient( 'scolta_rebuild_notice', array(
			'result' => 'ok',
			'pages'  => 10,
		), 60 );

		ob_start();
		Scolta_Admin::maybe_show_rebuild_notice();
		$first = ob_get_clean();

		ob_start();
		Scolta_Admin::maybe_show_rebuild_notice();
		$second = ob_get_clean();

		$this->assertNotEmpty( $first );
		$this->assertEmpty( $second, 'Notice must not repeat on second call' );
	}

	public function test_error_notice(): void {
		set_transient( 'scolta_rebuild_notice', array( 'result' => 'error' ), 60 );

		ob_start();
		Scolta_Admin::maybe_show_rebuild_notice();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice-error', $output );
		$this->assertStringContainsString( 'rebuild failed', $output );
	}

	public function test_no_content_notice(): void {
		set_transient( 'scolta_rebuild_notice', array( 'result' => 'no_content' ), 60 );

		ob_start();
		Scolta_Admin::maybe_show_rebuild_notice();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice-warning', $output );
		$this->assertStringContainsString( 'no published content', $output );
	}

	public function test_no_items_notice(): void {
		set_transient( 'scolta_rebuild_notice', array( 'result' => 'no_items' ), 60 );

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
}
