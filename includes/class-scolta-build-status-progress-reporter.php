<?php
/**
 * Progress reporter that mirrors orchestrator build progress into the
 * scolta_build_status option polled by the admin UI and the REST
 * build-progress endpoint.
 *
 * @package Scolta
 * @since 1.0.5
 */

defined( 'ABSPATH' ) || exit;

use Tag1\Scolta\Index\ProgressReporterInterface;

/**
 * Writes chunk-level progress to the scolta_build_status option.
 *
 * IndexBuildOrchestrator calls advance() at each chunk boundary; this
 * keeps the existing total_chunks / completed_chunks polling contract
 * that the admin progress bar reads.
 */
class Scolta_Build_Status_Progress_Reporter implements ProgressReporterInterface {

	/**
	 * Total steps announced by the orchestrator.
	 *
	 * @var int
	 */
	private int $total = 0;

	/**
	 * Steps completed so far.
	 *
	 * @var int
	 */
	private int $done = 0;

	/**
	 * Record the total step count and reset progress.
	 *
	 * @param int    $totalSteps Total number of steps.
	 * @param string $label      Human-readable label (unused).
	 */
	public function start( int $totalSteps, string $label ): void {
		$this->total = $totalSteps;
		$this->done  = 0;
		$this->write();
	}

	/**
	 * Advance the progress counter.
	 *
	 * @param int         $steps  Number of steps completed.
	 * @param string|null $detail Optional detail string (unused).
	 */
	public function advance( int $steps = 1, ?string $detail = null ): void {
		$this->done += $steps;
		$this->write();
	}

	/**
	 * Mark all steps complete.
	 *
	 * @param string|null $summary Optional summary string (unused).
	 */
	public function finish( ?string $summary = null ): void {
		$this->done = max( $this->done, $this->total );
		$this->write();
	}

	/**
	 * Persist the current counters into scolta_build_status.
	 */
	private function write(): void {
		$status = get_option( 'scolta_build_status', array() );
		if ( ! is_array( $status ) ) {
			$status = array();
		}
		$status['status']           = 'running';
		$status['total_chunks']     = $this->total;
		$status['completed_chunks'] = min( $this->done, $this->total );
		update_option( 'scolta_build_status', $status, false );
	}
}
