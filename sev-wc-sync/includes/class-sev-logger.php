<?php
/**
 * Logger — writes dated log files to /logs/.
 *
 * Format: [2026-02-27 08:14:05] [INFO] Message | {"key":"value"}
 * Rotation: logs older than 7 days are deleted automatically.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SEV_Logger {

	// ── Internal write ─────────────────────────────────────────────────────────

	private function write( string $level, string $message, array $context = [] ): void {
		$log_dir  = SEV_PLUGIN_DIR . 'logs/';
		$log_file = $log_dir . 'sev-sync-' . date( 'Y-m-d' ) . '.log';

		if ( ! is_dir( $log_dir ) ) {
			wp_mkdir_p( $log_dir );
		}

		$context_str = ! empty( $context )
			? ' | ' . json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
			: '';

		$line = '[' . date( 'Y-m-d H:i:s' ) . '] [' . strtoupper( $level ) . '] ' . $message . $context_str . "\n";

		file_put_contents( $log_file, $line, FILE_APPEND | LOCK_EX );
	}

	// ── Public API ─────────────────────────────────────────────────────────────

	public function info( string $message, array $context = [] ): void {
		$this->write( 'INFO', $message, $context );
	}

	public function warning( string $message, array $context = [] ): void {
		$this->write( 'WARNING', $message, $context );
	}

	public function error( string $message, array $context = [] ): void {
		$this->write( 'ERROR', $message, $context );
	}

	public function success( string $message, array $context = [] ): void {
		$this->write( 'SUCCESS', $message, $context );
	}

	// ── Sync session markers ───────────────────────────────────────────────────

	public function start_sync(): void {
		$this->write( 'INFO', str_repeat( '=', 48 ) );
		$this->write( 'INFO', 'SYNC SESSION STARTED' );
		$this->write( 'INFO', str_repeat( '=', 48 ) );
	}

	public function end_sync( array $stats ): void {
		$this->write( 'INFO', 'SYNC SESSION COMPLETE', $stats );
		$this->write( 'INFO', str_repeat( '=', 48 ) );
	}

	// ── Maintenance ────────────────────────────────────────────────────────────

	/**
	 * Delete log files older than $days days.
	 * Called at the start of each sync session.
	 */
	public function clean_old_logs( int $days = 7 ): void {
		$files  = glob( SEV_PLUGIN_DIR . 'logs/sev-sync-*.log' ) ?: [];
		$cutoff = time() - ( $days * DAY_IN_SECONDS );

		foreach ( $files as $file ) {
			if ( filemtime( $file ) < $cutoff ) {
				@unlink( $file );
			}
		}
	}
}
