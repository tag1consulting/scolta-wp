<?php
/**
 * PSR-3 logger for non-CLI contexts (cron, Action Scheduler, AJAX).
 *
 * Routes warning/error/critical to error_log() so build issues are
 * captured in the server error log when WP-CLI is not running. Info
 * and debug are dropped to keep logs clean.
 */

defined( 'ABSPATH' ) || exit;

use Psr\Log\AbstractLogger;

/**
 * Forwards PSR-3 warning/error calls to PHP's error_log().
 *
 * Used as the logger fallback in non-CLI build contexts so
 * IndexBuildOrchestrator output is not silently discarded.
 *
 * @since 0.3.3
 */
class Scolta_Logger extends AbstractLogger {

	/**
	 * Log a message at the given level.
	 *
	 * @param mixed   $level   PSR-3 log level string.
	 * @param string  $message Message with optional {placeholder} tokens.
	 * @param mixed[] $context Values to interpolate into the message.
	 */
	public function log( $level, $message, array $context = array() ): void {
		$level = (string) $level;
		if ( in_array( $level, array( 'debug', 'info', 'notice' ), true ) ) {
			return;
		}
		$prefix = '[scolta] [' . strtoupper( $level ) . '] ';
		$msg    = $prefix . $this->interpolate( (string) $message, $context );
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( $msg );
	}

	/**
	 * Interpolate context values into a message template.
	 *
	 * @param string  $message  The message template.
	 * @param mixed[] $context  Map of placeholder keys to values.
	 * @return string           The interpolated message.
	 */
	private function interpolate( string $message, array $context ): string {
		$replace = array();
		foreach ( $context as $key => $val ) {
			$has_to_string = is_object( $val ) && method_exists( $val, '__toString' );
			if ( is_scalar( $val ) || $has_to_string ) {
				$replace[ '{' . $key . '}' ] = (string) $val;
			}
		}
		return strtr( $message, $replace );
	}
}
