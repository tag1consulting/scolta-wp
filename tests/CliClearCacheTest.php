<?php

declare(strict_types=1);

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Regression tests for `wp scolta clear-cache`.
 *
 * The original DELETE used a single LIKE '%_transient_scolta_%'. In SQL
 * LIKE, '_' matches exactly one character, so the pattern never matched
 * the companion '_transient_timeout_scolta_*' expiry rows — the
 * 2026-06-09 fleet regression found 157 live 30-day scolta transients
 * still in wp_options after a cache flush. The command must delete the
 * value/timeout pair, same as deactivation and uninstall.
 */
class CliClearCacheTest extends TestCase {

	/** @var object Original global wpdb stub, restored in tear_down. */
	private $original_wpdb;

	protected function set_up(): void {
		$GLOBALS['wp_options'] = [];
		if ( ! class_exists( 'Scolta_CLI' ) ) {
			require_once dirname( __DIR__ ) . '/cli/class-scolta-cli.php';
		}
		$this->original_wpdb = $GLOBALS['wpdb'];
		$GLOBALS['wpdb']     = new ScoltaLikeDeletingWpdbStub();
	}

	protected function tear_down(): void {
		$GLOBALS['wpdb'] = $this->original_wpdb;
	}

	public function test_clear_cache_deletes_value_and_timeout_rows(): void {
		global $wpdb;
		$wpdb->rows = array(
			'_transient_scolta_expand_abc'            => 'cached',
			'_transient_timeout_scolta_expand_abc'    => '999',
			'_transient_scolta_summarize_xyz'         => 'cached',
			'_transient_timeout_scolta_summarize_xyz' => '999',
			'_transient_unrelated_plugin'             => 'keep',
			'scolta_settings'                         => 'keep',
		);

		( new Scolta_CLI() )->clear_cache( array(), array() );

		$remaining_scolta_transients = preg_grep( '/^_transient.*scolta/', array_keys( $wpdb->rows ) );
		$this->assertSame(
			array(),
			array_values( $remaining_scolta_transients ),
			'clear-cache must leave zero _transient%scolta% rows (value AND timeout)'
		);
		$this->assertArrayHasKey( '_transient_unrelated_plugin', $wpdb->rows, 'other plugins\' transients must survive' );
		$this->assertArrayHasKey( 'scolta_settings', $wpdb->rows, 'non-transient scolta options must survive' );
	}

	public function test_clear_cache_increments_generation(): void {
		update_option( 'scolta_generation', 4 );

		( new Scolta_CLI() )->clear_cache( array(), array() );

		$this->assertSame( 5, get_option( 'scolta_generation' ) );
	}
}

/**
 * wpdb stub that executes DELETE ... LIKE queries against an in-memory
 * row map using real SQL LIKE semantics ('%' = any run, '_' = exactly
 * one character) — the semantics the original bug depended on.
 */
class ScoltaLikeDeletingWpdbStub {
	public string $prefix  = 'wp_';
	public string $options = 'wp_options';
	public string $posts   = 'wp_posts';

	/** @var array<string, string> option_name => value. */
	public array $rows = array();

	public function prepare( string $query, ...$args ): string {
		return vsprintf( str_replace( '%s', "'%s'", $query ), $args );
	}

	public function query( string $query ): int {
		if ( ! preg_match_all( "/option_name LIKE '([^']+)'/", $query, $m ) ) {
			return 0;
		}
		$deleted = 0;
		foreach ( $m[1] as $like ) {
			$regex = '/^' . str_replace( array( '%', '_' ), array( '.*', '.' ), preg_quote( $like, '/' ) ) . '$/';
			foreach ( array_keys( $this->rows ) as $name ) {
				if ( preg_match( $regex, $name ) ) {
					unset( $this->rows[ $name ] );
					++$deleted;
				}
			}
		}
		return $deleted;
	}

	public function get_results( string $query, string $output = 'OBJECT' ): array {
		return array();
	}

	public function get_var( string $query ) {
		return null;
	}

	public function get_charset_collate(): string {
		return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
	}
}
