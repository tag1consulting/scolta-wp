<?php

declare(strict_types=1);

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Source-parse regression tests for the 2026-06 quality-cleanup audit
 * findings that cannot be exercised end-to-end in the stub environment
 * (network provisioning, admin-post handlers that exit, CI guards).
 *
 * Per tests/CONVENTIONS.md, source-parse tests are the designated tool
 * for required guard patterns and prohibited constructs.
 */
class QualityCleanupRegressionTest extends TestCase {

	private static function source( string $relative_path ): string {
		return file_get_contents( dirname( __DIR__ ) . '/' . $relative_path );
	}

	// -------------------------------------------------------------------
	// Amazee trial start must not clobber a customized ai_model.
	// `$a['x'] ?? $d === $d` binds as `$a['x'] ?? ($d === $d)` — always
	// truthy — so provisioning overwrote ANY configured model.
	// -------------------------------------------------------------------

	public function test_amazee_model_guard_is_parenthesized(): void {
		$source = self::source( 'admin/class-scolta-amazee-admin-page.php' );
		$this->assertMatchesRegularExpression(
			"/\(\s*\\\$scolta_settings\['ai_model'\]\s*\?\?\s*\\\$default_model\s*\)\s*===\s*\\\$default_model/",
			$source,
			'the ai_model default check must parenthesize the null-coalesce before comparing'
		);
	}

	public function test_no_unparenthesized_null_coalesce_comparison_in_admin(): void {
		foreach ( glob( dirname( __DIR__ ) . '/admin/*.php' ) as $file ) {
			$this->assertDoesNotMatchRegularExpression(
				'/\?\?\s*\$\w+\s*===/',
				file_get_contents( $file ),
				basename( $file ) . ': `?? $x ===` binds as `?? ($x === ...)` — parenthesize the coalesce'
			);
		}
	}

	// -------------------------------------------------------------------
	// Admin rebuild honors the shared build lock and logs failures.
	// -------------------------------------------------------------------

	private static function handle_rebuild_now_body(): string {
		$source = self::source( 'admin/class-scolta-admin.php' );
		$start  = strpos( $source, 'function handle_rebuild_now' );
		self::assertNotFalse( $start, 'handle_rebuild_now() must exist' );
		return substr( $source, $start );
	}

	public function test_admin_rebuild_checks_build_lock(): void {
		$body = self::handle_rebuild_now_body();
		$this->assertStringContainsString(
			'get_transient( Scolta_Rebuild_Scheduler::LOCK_KEY )',
			$body,
			'handle_rebuild_now() must honor the lock the REST rebuild endpoint 409s on'
		);
		$this->assertStringContainsString(
			'finally',
			$body,
			'the lock must be released on every exit path'
		);
	}

	public function test_admin_rebuild_logs_caught_throwable(): void {
		$this->assertMatchesRegularExpression(
			'/catch\s*\(\s*\\\\Throwable\s+\$e\s*\).{0,400}\$e->getMessage\(\)/s',
			self::handle_rebuild_now_body(),
			'the rebuild catch block must log the throwable, not discard it'
		);
	}

	// -------------------------------------------------------------------
	// Resolved-prompt cache writes must pass autoload=false — the blob is
	// only read at AI request time, not on every page load.
	// -------------------------------------------------------------------

	public function test_resolved_prompts_option_writes_disable_autoload(): void {
		$this->assertSame(
			0,
			preg_match( "/update_option\(\s*'scolta_resolved_prompts',\s*\\\$\w+\s*\)/", self::source( 'scolta.php' ) ),
			"every update_option('scolta_resolved_prompts', ...) must pass the autoload=false third argument"
		);
	}

	// -------------------------------------------------------------------
	// Dead --bundle flag: forwarded by spawn_resume_background but never
	// declared by any command docblock, so WP-CLI rejects it before the
	// handler runs — the forwarding branch was unreachable.
	// -------------------------------------------------------------------

	public function test_no_bundle_flag_remnants_in_cli(): void {
		$this->assertStringNotContainsString(
			'bundle',
			self::source( 'cli/class-scolta-cli.php' ),
			'--bundle was undeclared and dead; it must not reappear without a docblock declaring it'
		);
	}

	// -------------------------------------------------------------------
	// In-suite mirror of the CI antipatterns guard. The CI grep was inert
	// (it could not match WP's quoted-key form), so the suite re-checks.
	// -------------------------------------------------------------------

	public function test_no_unbounded_wp_query_in_plugin_source(): void {
		foreach ( array( 'includes', 'admin', 'cli' ) as $dir ) {
			foreach ( glob( dirname( __DIR__ ) . '/' . $dir . '/*.php' ) as $file ) {
				$this->assertDoesNotMatchRegularExpression(
					"/[\"']?posts_per_page[\"']?\s*=>\s*-1/",
					file_get_contents( $file ),
					basename( $file ) . ': unbounded WP_Query (posts_per_page => -1) — use wp_count_posts() or paginate'
				);
			}
		}
	}

	public function test_ci_antipattern_guard_matches_quoted_key_form(): void {
		$workflow = self::source( '.github/workflows/ci.yml' );
		// The exact guard line ci.yml must carry. The original pattern began
		// at the bare key, so WP's quoted form ('posts_per_page' => -1)
		// never matched and the guard was inert.
		$this->assertStringContainsString(
			'grep -rnE "[\"\']?posts_per_page[\"\']?[[:space:]]*=>[[:space:]]*-1"',
			$workflow,
			'ci.yml must grep for the quoted-key form of posts_per_page => -1'
		);
		// Prove the equivalent regex actually fires on the WP form.
		$this->assertMatchesRegularExpression(
			'/["\']?posts_per_page["\']?\s*=>\s*-1/',
			"'posts_per_page' => -1"
		);
	}

	// -------------------------------------------------------------------
	// Activation notice strings are translatable.
	// -------------------------------------------------------------------

	public function test_activation_notice_strings_are_i18n_wrapped(): void {
		$source = self::source( 'scolta.php' );
		$this->assertStringNotContainsString( "echo 'Scolta activated!'", $source );
		$this->assertStringNotContainsString( "echo ' Your search index", $source );
		$this->assertStringNotContainsString( "echo ' Using the PHP indexer", $source );
		$this->assertStringContainsString( "esc_html__( 'Scolta activated!', 'scolta-ai-search' )", $source );
		$this->assertStringContainsString( "'Your search index will be built automatically in the background.',", $source );
	}

	public function test_shortcode_no_index_fallback_is_i18n_wrapped(): void {
		$source = self::source( 'includes/class-scolta-shortcode.php' );
		$this->assertStringNotContainsString( '</strong> Search index has not been built yet.', $source );
		$this->assertMatchesRegularExpression(
			"/esc_html__\(\s*'Search index has not been built yet\.',\s*'scolta-ai-search'/s",
			$source
		);
	}

	public function test_ajax_remove_db_key_responses_are_i18n_wrapped(): void {
		$source = self::source( 'admin/class-scolta-admin.php' );
		$this->assertStringNotContainsString( "wp_send_json_error( 'Insufficient permissions' )", $source );
		$this->assertStringNotContainsString( "wp_send_json_success( 'API key removed from database' )", $source );
	}

	// -------------------------------------------------------------------
	// readme.txt changelog must cover every stable release in
	// CHANGELOG.md — wordpress.org users only ever see readme.txt.
	// -------------------------------------------------------------------

	public function test_readme_changelog_covers_all_stable_changelog_releases(): void {
		$changelog = self::source( 'CHANGELOG.md' );
		$readme    = self::source( 'readme.txt' );

		preg_match_all( '/^## \[(\d+\.\d+\.\d+)\] - /m', $changelog, $m );
		// The readme.txt changelog starts at the first wordpress.org
		// submission (1.0.x); pre-1.0 internal releases are not published.
		$versions = array_filter( $m[1], fn ( $v ) => version_compare( $v, '1.0.0', '>=' ) );
		$this->assertNotEmpty( $versions, 'CHANGELOG.md must contain released versions' );

		foreach ( $versions as $version ) {
			$this->assertStringContainsString(
				"= {$version} =",
				$readme,
				"readme.txt changelog is missing the {$version} section published in CHANGELOG.md"
			);
		}
	}

	// -------------------------------------------------------------------
	// The 'locked' rebuild notice renders as a warning.
	// -------------------------------------------------------------------

	public function test_locked_rebuild_notice_renders_warning(): void {
		$GLOBALS['wp_options']           = array();
		$GLOBALS['test_user_meta']       = array();
		$GLOBALS['test_current_user_id'] = 1;
		set_transient(
			'scolta_rebuild_notice',
			array(
				'result'    => 'locked',
				'notice_id' => 'scolta_rebuild_test',
			),
			60
		);

		ob_start();
		Scolta_Admin::maybe_show_rebuild_notice();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice-warning', $output );
		$this->assertStringContainsString( 'already in progress', $output );
	}
}
