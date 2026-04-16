<?php
/**
 * Background rebuild scheduler for Scolta search index.
 *
 * Uses WordPress Action Scheduler (if available) to process index builds
 * in background chunks, avoiding PHP timeout issues on large sites.
 *
 * Three-phase pipeline:
 * 1. handle_start() — gather content, split into chunks, schedule first chunk
 * 2. handle_chunk() — process one chunk via PhpIndexer, schedule next chunk
 * 3. handle_finalize() — merge chunks and write final Pagefind index
 *
 * @package Scolta
 * @since 0.2.0
 */

defined( 'ABSPATH' ) || exit;

use Tag1\Scolta\Index\PhpIndexer;
use Tag1\Scolta\Export\ContentExporter;
use Tag1\Scolta\Binary\PagefindBinary;

class Scolta_Rebuild_Scheduler {

	/** Action hook for starting a rebuild. */
	const ACTION_START = 'scolta_rebuild_start';

	/** Action hook for processing a single chunk. */
	const ACTION_CHUNK = 'scolta_process_chunk';

	/** Action hook for finalizing a build. */
	const ACTION_FINALIZE = 'scolta_finalize_build';

	/** Transient key for build lock. */
	const LOCK_KEY = 'scolta_build_lock';

	/** Lock time-to-live in seconds (1 hour). */
	const LOCK_TTL = 3600;

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
	 * @param bool $force Whether to skip the fingerprint check.
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
	 * Handle the start phase: gather content, check fingerprint, schedule chunks.
	 *
	 * @since 0.2.0
	 */
	public static function handle_start(): void {
		$settings   = get_option( 'scolta_settings', array() );
		$output_dir = $settings['output_dir'] ?? ABSPATH . 'scolta-pagefind';
		$state_dir  = wp_upload_dir()['basedir'] . '/scolta/state';
		$force      = get_option( 'scolta_build_force', false );
		delete_option( 'scolta_build_force' );

		$raw_items = Scolta_Content_Gatherer::gather();
		$exporter  = new ContentExporter( $output_dir );
		$items     = $exporter->exportToItems( $raw_items );

		if ( empty( $items ) ) {
			self::finish( __( 'No indexable content found.', 'scolta' ) );
			return;
		}

		// Check if PHP or binary indexer.
		$indexer_setting = $settings['indexer'] ?? 'auto';
		$use_php = (
			$indexer_setting === 'php' ||
			( $indexer_setting === 'auto' && ! ( new PagefindBinary( null, ABSPATH ) )->resolve() )
		);

		if ( ! $use_php ) {
			// translators: shown when background rebuild attempted with binary indexer.
			$msg = __(
				'Binary indexer not supported in background mode. Use wp scolta build.',
				'scolta'
			);
			self::finish( $msg );
			return;
		}

		// Fingerprint check: skip rebuild if content hasn't changed.
		$indexer = new PhpIndexer( $state_dir, $output_dir, wp_salt( 'auth' ) );
		if ( ! $force && $indexer->shouldBuild( $items ) === null ) {
			self::finish( __( 'No changes detected.', 'scolta' ) );
			return;
		}

		$chunks = array_chunk( $items, 100 );
		set_transient( 'scolta_build_chunks', $chunks, self::LOCK_TTL );

		update_option(
			'scolta_build_status',
			array(
				'status'           => 'running',
				'started_at'       => time(),
				'total_chunks'     => count( $chunks ),
				'completed_chunks' => 0,
				'total_pages'      => count( $items ),
				'indexer'          => 'php',
			)
		);

		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time(), self::ACTION_CHUNK, array( 0 ), 'scolta' );
		}
	}

	/**
	 * Handle a single chunk: index pages, schedule next chunk or finalize.
	 *
	 * @since 0.2.0
	 *
	 * @param int $chunk_index Zero-based chunk index.
	 */
	public static function handle_chunk( int $chunk_index ): void {
		$chunks = get_transient( 'scolta_build_chunks' );
		if ( ! $chunks || ! isset( $chunks[ $chunk_index ] ) ) {
			self::finish( __( 'Chunk data missing.', 'scolta' ) );
			return;
		}

		$settings   = get_option( 'scolta_settings', array() );
		$output_dir = $settings['output_dir'] ?? ABSPATH . 'scolta-pagefind';
		$state_dir  = wp_upload_dir()['basedir'] . '/scolta/state';

		$indexer     = new PhpIndexer( $state_dir, $output_dir, wp_salt( 'auth' ) );
		$total_pages = array_sum( array_map( 'count', $chunks ) );
		$indexer->processChunk( $chunks[ $chunk_index ], $chunk_index, $total_pages );

		$status                     = get_option( 'scolta_build_status', array() );
		$status['completed_chunks'] = $chunk_index + 1;
		update_option( 'scolta_build_status', $status );

		if ( function_exists( 'as_schedule_single_action' ) ) {
			$next = $chunk_index + 1;
			if ( $next < count( $chunks ) ) {
				as_schedule_single_action( time(), self::ACTION_CHUNK, array( $next ), 'scolta' );
			} else {
				as_schedule_single_action( time(), self::ACTION_FINALIZE, array(), 'scolta' );
			}
		}
	}

	/**
	 * Handle finalization: merge chunks and write the Pagefind index.
	 *
	 * @since 0.2.0
	 */
	public static function handle_finalize(): void {
		$settings   = get_option( 'scolta_settings', array() );
		$output_dir = $settings['output_dir'] ?? ABSPATH . 'scolta-pagefind';
		$state_dir  = wp_upload_dir()['basedir'] . '/scolta/state';

		$indexer = new PhpIndexer( $state_dir, $output_dir, wp_salt( 'auth' ) );
		$result  = $indexer->finalize();

		delete_transient( 'scolta_build_chunks' );

		if ( $result->success ) {
			// Increment generation counter for cache invalidation.
			$gen = (int) get_option( 'scolta_generation', 0 );
			update_option( 'scolta_generation', $gen + 1 );
		}

		$finish_msg = $result->success
			? $result->message
			: ( __( 'Failed: ', 'scolta' ) . $result->error );
		self::finish( $finish_msg );
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
