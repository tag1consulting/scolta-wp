<?php

declare(strict_types=1);

use Tag1\Scolta\Index\TimestampManifest;
use Tag1\Scolta\Storage\FilesystemDriver;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Regression tests for the forced-rebuild timestamp manifest.
 *
 * Root cause (two halves of one run): the build callers handed the gatherer a
 * null manifest under force, and the gatherer gated both the manifest write and
 * the timestamp lookup on force. Nothing was re-recorded during a forced build,
 * so the orchestrator's end-of-build TimestampManifest::pruneAndSave() deleted
 * every entry — a forced build emptied the cache instead of re-priming it.
 * Ungating the write alone was not enough: $timestamps was populated under the
 * same force gate, so entries would have been written with ts 0 and never
 * matched again.
 *
 * The gatherer cannot be driven end to end here — the bootstrap's WP_Query stub
 * ignores its arguments and always returns an empty post list, so gather()
 * breaks out of its first batch — hence the gate structure is pinned by source
 * inspection, in the style of ContentGathererGeneratorTest. The manifest
 * behaviour the fix rests on is exercised against the real class.
 */
class ForceBuildManifestPrimingTest extends TestCase {

	/** @var string[] */
	private array $temp_dirs = array();

	protected function tear_down(): void {
		foreach ( $this->temp_dirs as $dir ) {
			foreach ( (array) glob( $dir . '/*' ) as $file ) {
				@unlink( $file );
			}
			@rmdir( $dir );
		}
		$this->temp_dirs = array();
	}

	private function make_state_dir(): string {
		$dir = sys_get_temp_dir() . '/scolta-test-manifest-' . uniqid();
		@mkdir( $dir, 0755, true );
		$this->temp_dirs[] = $dir;
		return $dir;
	}

	private static function gatherer_source(): string {
		return file_get_contents( dirname( __DIR__ ) . '/includes/class-scolta-content-gatherer.php' );
	}

	private static function gather_body(): string {
		// Same method-body extraction as ContentGathererGeneratorTest.
		preg_match(
			'/public static function gather\([^)]*\)[^{]*\{(.+?)(?=\n\t\/\*\*|\n\tpublic static function|\n\tprivate|\n\})/s',
			self::gatherer_source(),
			$m
		);
		return $m[1] ?? '';
	}

	private static function item_stub(): array {
		return array(
			array(
				'hash'     => 'abc123',
				'id'       => 'post-1',
				'url'      => 'https://example.com/one',
				'date'     => '2026-01-01',
				'siteName' => 'Example',
				'language' => 'en',
				'filters'  => array(),
				'metadata' => array(),
				'sortable' => array(),
			),
		);
	}

	// -------------------------------------------------------------------
	// The invariant the whole fix rests on, against the real manifest:
	// a build that records nothing wipes the manifest.
	// -------------------------------------------------------------------

	public function test_recorded_entries_survive_prune_and_reload(): void {
		$state_dir = $this->make_state_dir();
		$storage   = new FilesystemDriver();

		$manifest = new TimestampManifest( $state_dir, $storage );
		$manifest->put( '1', 1700000001, self::item_stub() );
		$manifest->put( '2', 1700000002, self::item_stub() );
		$manifest->pruneAndSave();

		$reloaded = new TimestampManifest( $state_dir, $storage );

		$this->assertFalse( $reloaded->isEmpty(), 'recorded entries must persist across builds' );
		$this->assertSame( 2, $reloaded->count() );
		$this->assertSame( 1700000001, $reloaded->get( '1' )['ts'] );
		$this->assertSame( 1700000002, $reloaded->get( '2' )['ts'] );
	}

	public function test_recording_nothing_empties_the_manifest_on_prune(): void {
		$state_dir = $this->make_state_dir();
		$storage   = new FilesystemDriver();

		$seed = new TimestampManifest( $state_dir, $storage );
		$seed->put( '1', 1700000001, self::item_stub() );
		$seed->put( '2', 1700000002, self::item_stub() );
		$seed->pruneAndSave();

		// A build that records nothing: load the seeded manifest, put()
		// nothing, markSeen() nothing, prune at the end of the run.
		$run = new TimestampManifest( $state_dir, $storage );
		$this->assertSame( 2, $run->count(), 'precondition: the seeded entries loaded' );
		$run->pruneAndSave();

		$this->assertTrue( $run->isEmpty(), 'pruning deletes every entry not re-recorded during the run' );

		$reloaded = new TimestampManifest( $state_dir, $storage );
		$this->assertTrue( $reloaded->isEmpty(), 'the emptied manifest is what the next build reads' );
	}

	// -------------------------------------------------------------------
	// Callers hand the gatherer the live manifest, forced or not.
	// -------------------------------------------------------------------

	public function test_scheduler_does_not_null_the_manifest_under_force(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-scolta-rebuild-scheduler.php' );

		$this->assertMatchesRegularExpression(
			'/\$ts_manifest\s*=\s*\$orchestrator->getTimestampManifest\(\);/',
			$source,
			'the scheduler must assign the orchestrator manifest unconditionally'
		);
		$this->assertDoesNotMatchRegularExpression(
			'/\$ts_manifest\s*=[^;]*\?[^;]*null/',
			$source,
			'the scheduler must not withhold the manifest under force — the end-of-build prune would empty it'
		);
	}

	public function test_cli_does_not_null_the_manifest_under_force(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/cli/class-scolta-cli.php' );

		$this->assertMatchesRegularExpression(
			'/\$ts_manifest\s*=\s*\$orchestrator->getTimestampManifest\(\);/',
			$source,
			'do_build_php() must assign the orchestrator manifest unconditionally'
		);
		$this->assertDoesNotMatchRegularExpression(
			'/\$ts_manifest\s*=[^;]*\?[^;]*null/',
			$source,
			'wp scolta build --force must not withhold the manifest — the end-of-build prune would empty it'
		);
	}

	// -------------------------------------------------------------------
	// gather(): only the skip decision is gated on force.
	// -------------------------------------------------------------------

	public function test_gather_gates_force_exactly_once(): void {
		$body = self::gather_body();
		$this->assertNotEmpty( $body, 'Could not locate gather() method body' );

		$this->assertSame(
			1,
			preg_match_all( '/!\s*\$force/', $body ),
			'gather() must test ! $force exactly once — the cached-reference skip decision. '
				. 'The manifest write and the timestamp lookup are gated on the manifest, never on force.'
		);
	}

	public function test_gather_writes_the_manifest_regardless_of_force(): void {
		$body = self::gather_body();

		$this->assertStringContainsString(
			'$manifest->put(',
			$body,
			'gather() must record loaded posts into the manifest'
		);

		// The write's enclosing condition must test only the manifest.
		preg_match( '/if \(([^)]*)\) \{\s*\$ts\s+= /', $body, $m );
		$this->assertNotEmpty( $m, 'Could not locate the condition guarding the manifest write' );
		$this->assertStringNotContainsString(
			'$force',
			$m[1],
			'the manifest write must not be gated on force — a forced build re-primes the manifest'
		);
		$this->assertStringContainsString( '$manifest !== null', $m[1] );
	}

	public function test_gather_looks_up_timestamps_regardless_of_force(): void {
		$body = self::gather_body();

		$this->assertStringContainsString(
			'self::get_post_timestamps(',
			$body,
			'gather() must look up post modification timestamps'
		);

		// The lookup's enclosing condition must test only the manifest:
		// under force it still has to run, or every entry would be written
		// with ts 0 and never match on a later build.
		preg_match( '/if \(([^)]*)\) \{\s*\$timestamps\s*= self::get_post_timestamps\(/', $body, $m );
		$this->assertNotEmpty( $m, 'Could not locate the condition guarding the timestamp lookup' );
		$this->assertStringNotContainsString(
			'$force',
			$m[1],
			'the timestamp lookup must not be gated on force — the write path needs real timestamps'
		);
		$this->assertStringContainsString( '$manifest !== null', $m[1] );
	}

	public function test_gather_skip_path_stays_gated_on_force(): void {
		$body = self::gather_body();

		// The cached-reference yield is the one thing force suppresses.
		$this->assertMatchesRegularExpression(
			'/if \( ! \$force \) \{.*?yield new CachedContentReference\(/s',
			$body,
			'the cached-reference skip path must remain gated on ! $force so a forced build reloads every post'
		);
	}
}
