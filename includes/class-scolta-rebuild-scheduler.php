<?php
/**
 * Background rebuild scheduler for Scolta search index.
 *
 * Uses WordPress Action Scheduler (if available) to run index builds in
 * the background without PHP timeout issues on large sites.
 *
 * Two-phase pipeline around IndexBuildOrchestrator:
 * 1. handle_start() — count content, record build status, schedule a slice
 * 2. handle_chunk() — run a time-boxed orchestrator slice; the orchestrator
 *    streams the content generator, chunks per the configured memory
 *    budget, and commits state to disk. When a slice yields (time box or
 *    memory pressure), the next slice resumes from the on-disk state.
 *
 * The corpus is never materialized in RAM and never serialized into a
 * transient: the orchestrator consumes the gatherer's generator directly
 * and persists its own resumable state under the state directory.
 *
 * @package Scolta
 * @since 0.2.0
 */

defined( 'ABSPATH' ) || exit;

use Tag1\Scolta\Config\MemoryBudgetConfig;
use Tag1\Scolta\Export\ContentExporter;
use Tag1\Scolta\Index\BuildIntentFactory;
use Tag1\Scolta\Index\IndexBuildOrchestrator;
use Tag1\Scolta\Index\MemoryBudget;

/**
 * Runs background index rebuilds in time-boxed Action Scheduler slices.
 */
class Scolta_Rebuild_Scheduler {

	/** Action hook for starting a rebuild. */
	const ACTION_START = 'scolta_rebuild_start';

	/** Action hook for processing a build slice. */
	const ACTION_CHUNK = 'scolta_process_chunk';

	/** Action hook for finalizing a build (legacy; now resumes a slice). */
	const ACTION_FINALIZE = 'scolta_finalize_build';

	/** Transient key for build lock. */
	const LOCK_KEY = 'scolta_build_lock';

	/** Lock time-to-live in seconds (1 hour). */
	const LOCK_TTL = 3600;

	/**
	 * Seconds of indexing work per Action Scheduler invocation.
	 *
	 * Each slice voluntarily yields after this long (checked at chunk
	 * boundaries) so a single action stays well inside common PHP/web
	 * execution limits; the next slice resumes from committed state.
	 */
	const TIME_SLICE_SECONDS = 20;

	/**
	 * Register Action Scheduler hooks.
	 *
	 * @since 0.2.0
	 */
	public static function init(): void {
		add_action( self::ACTION_START, array( __CLASS__, 'handle_start' ) );
		add_action( self::ACTION_CHUNK, array( __CLASS__, 'handle_chunk' ), 10, 1 );
		add_action( self::ACTION_FINALIZE, array( __CLASS__, 'handle_finalize' ) );
	}

	/**
	 * Schedule a rebuild via Action Scheduler.
	 *
	 * Acquires a build lock to prevent concurrent rebuilds. If Action
	 * Scheduler is not available, returns false.
	 *
	 * @since 0.2.0
	 *
	 * @param bool $force Whether to bypass the unchanged-content cache.
	 * @return bool True if rebuild was scheduled, false if locked or unavailable.
	 */
	public static function start_rebuild( bool $force = false ): bool {
		if ( get_transient( self::LOCK_KEY ) ) {
			return false;
		}
		set_transient( self::LOCK_KEY, time(), self::LOCK_TTL );
		update_option( 'scolta_build_force', $force );

		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time(), self::ACTION_START, array(), 'scolta' );
		}
		return true;
	}

	/**
	 * Handle the start phase: count content, record status, schedule a slice.
	 *
	 * Only the lightweight published-post count runs here — content itself
	 * is streamed by the orchestrator inside the slices.
	 *
	 * @since 0.2.0
	 */
	public static function handle_start(): void {
		$settings = get_option( 'scolta_settings', array() );
		$force    = (bool) get_option( 'scolta_build_force', false );
		delete_option( 'scolta_build_force' );

		// Background scheduler always uses the PHP pipeline.
		// auto and php both mean PHP; only explicit 'binary' would be unsupported.
		$indexer_setting = $settings['indexer'] ?? 'auto';
		if ( 'binary' === $indexer_setting ) {
			// translators: shown when background rebuild attempted with binary indexer.
			$msg = __(
				'Binary indexer not supported in background mode. Use wp scolta build.',
				'scolta-ai-search'
			);
			self::finish( $msg );
			return;
		}

		$total = Scolta_Content_Gatherer::gather_count();
		if ( 0 === $total ) {
			self::finish( __( 'No indexable content found.', 'scolta-ai-search' ) );
			return;
		}

		$chunk_size = self::memory_budget( $settings )->chunkSize();

		update_option(
			'scolta_build_status',
			array(
				'status'           => 'running',
				'started_at'       => time(),
				'total_chunks'     => (int) ceil( $total / max( 1, $chunk_size ) ),
				'completed_chunks' => 0,
				'total_pages'      => $total,
				'indexer'          => 'php',
				'force'            => $force,
			)
		);

		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time(), self::ACTION_CHUNK, array( false ), 'scolta' );
		}
	}

	/**
	 * Handle one build slice: stream content through the orchestrator
	 * until done or the slice yields, then schedule a resume or finish.
	 *
	 * @since 0.2.0
	 *
	 * @param mixed $resume Truthy to resume from committed state. Legacy
	 *                      queued actions passed an int chunk index; any
	 *                      such in-flight value safely maps to a resume.
	 */
	public static function handle_chunk( $resume = false ): void {
		self::run_slice( (bool) $resume );
	}

	/**
	 * Handle finalization (legacy hook): the orchestrator merges and swaps
	 * inside build(), so a queued finalize action just resumes a slice.
	 *
	 * @since 0.2.0
	 */
	public static function handle_finalize(): void {
		self::run_slice( true );
	}

	/**
	 * Run one time-boxed orchestrator slice.
	 *
	 * Mirrors the WP-CLI build path: content is streamed one post at a
	 * time (unchanged posts come back as CachedContentReferences via the
	 * timestamp manifest), the memory budget honors the admin
	 * memory_budget_profile and chunk_size settings, and all chunking /
	 * merging / atomic-swap logic lives in scolta-php.
	 *
	 * @param bool $resume Whether to resume from committed on-disk state.
	 */
	private static function run_slice( bool $resume ): void {
		$settings   = get_option( 'scolta_settings', array() );
		$output_dir = $settings['output_dir'] ?? scolta_default_output_dir();
		$state_dir  = wp_upload_dir()['basedir'] . '/scolta/state';
		$status     = get_option( 'scolta_build_status', array() );
		$force      = is_array( $status ) && ! empty( $status['force'] );
		$total      = is_array( $status ) ? (int) ( $status['total_pages'] ?? 0 ) : 0;

		$budget = self::memory_budget( $settings );
		$intent = BuildIntentFactory::fromFlags( $resume, false, $total, $budget );

		// Logger + progress reporter are passed to build() below.
		$orchestrator = new IndexBuildOrchestrator(
			$state_dir,
			$output_dir,
			wp_salt( 'auth' ),
			'en',
			null,
			self::slice_probe(),
		);

		// Expose the timestamp manifest to the gatherer so unchanged posts
		// are yielded as CachedContentReferences without loading content.
		$ts_manifest = $force ? null : $orchestrator->getTimestampManifest();

		// Stream content one post at a time — no full pre-load into RAM.
		$exporter = new ContentExporter( $output_dir );
		$items    = $exporter->filterItems(
			Scolta_Content_Gatherer::gather( $ts_manifest, $force )
		);

		$report = $orchestrator->build(
			$intent,
			$items,
			new Scolta_Logger(),
			new Scolta_Build_Status_Progress_Reporter(),
			force: $force,
		);

		if ( $report->success ) {
			scolta_cleanup_nested_indexes( $output_dir );
			// Increment generation counter for cache invalidation.
			$gen = (int) get_option( 'scolta_generation', 0 );
			update_option( 'scolta_generation', $gen + 1 );
			self::finish(
				sprintf(
					/* translators: %d: number of pages indexed */
					__( 'Indexed %d pages.', 'scolta-ai-search' ),
					$report->pagesProcessed
				)
			);
			return;
		}

		$yielded = in_array( $report->error, array( 'memory_abort', 'index_only_complete' ), true );
		if ( $yielded && $report->chunksWritten > 0
			&& function_exists( 'as_schedule_single_action' ) ) {
			// Voluntary yield with committed progress: refresh the lock and
			// resume in a fresh action.
			set_transient( self::LOCK_KEY, time(), self::LOCK_TTL );
			as_schedule_single_action( time(), self::ACTION_CHUNK, array( true ), 'scolta' );
			return;
		}

		self::finish( __( 'Failed: ', 'scolta-ai-search' ) . ( $report->error ?? 'unknown' ) );
	}

	/**
	 * Build the memory budget from the admin settings, exactly like the
	 * WP-CLI path does (no CLI flag overrides in background mode).
	 *
	 * @param array $settings Plugin settings.
	 */
	private static function memory_budget( array $settings ): MemoryBudget {
		return MemoryBudgetConfig::fromCliAndConfig(
			null,
			null,
			fn() => array(
				'profile'    => $settings['memory_budget_profile'] ?? 'conservative',
				'chunk_size' => $settings['chunk_size'] ?? null,
			),
		);
	}

	/**
	 * Yield probe for one slice: time-boxed, with the orchestrator's
	 * memory-pressure check preserved.
	 *
	 * The closure replaces the orchestrator's built-in RSS check, so it
	 * re-implements the 75% heuristic against the PHP memory limit in
	 * addition to the elapsed-time box.
	 *
	 * @return \Closure(): bool
	 */
	private static function slice_probe(): \Closure {
		$started = microtime( true );
		return static function () use ( $started ): bool {
			if ( ( microtime( true ) - $started ) >= self::TIME_SLICE_SECONDS ) {
				return true;
			}
			$limit = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
			return $limit > 0 && memory_get_usage( true ) >= (int) ( $limit * 0.75 );
		};
	}

	/**
	 * Clean up build lock and update status to idle.
	 *
	 * @since 0.2.0
	 *
	 * @param string $message Completion message.
	 */
	private static function finish( string $message ): void {
		delete_transient( self::LOCK_KEY );
		update_option(
			'scolta_build_status',
			array(
				'status'       => 'idle',
				'message'      => $message,
				'completed_at' => time(),
			)
		);
	}
}
