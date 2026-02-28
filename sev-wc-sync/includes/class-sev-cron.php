<?php
/**
 * Cron — registers WP Cron schedules and drives automatic syncs.
 *
 * Default interval: every 8 hours (3× daily).
 * Configurable via Settings: 6h, 8h, 12h, 24h.
 *
 * WP Cron hook used: sev_sync_cron
 * Custom schedule slugs registered: sev_every_6h, sev_every_8h
 * (WP built-ins used for 12h → twicedaily, 24h → daily)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SEV_Cron {

	/** @var self|null */
	private static $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'cron_schedules', [ $this, 'add_schedules' ] );
		add_action( 'sev_sync_cron',  [ $this, 'run_sync' ] );
		add_action( 'init',           [ $this, 'maybe_schedule' ] );
	}

	// ── Schedule registration ──────────────────────────────────────────────────

	/**
	 * Register the custom 6h and 8h intervals WP doesn't have built-in.
	 *
	 * @param  array $schedules  Existing WP Cron schedules.
	 * @return array
	 */
	public function add_schedules( array $schedules ): array {
		$schedules['sev_every_6h'] = [
			'interval' => 6 * HOUR_IN_SECONDS,
			'display'  => 'Every 6 hours',
		];
		$schedules['sev_every_8h'] = [
			'interval' => 8 * HOUR_IN_SECONDS,
			'display'  => 'Every 8 hours',
		];
		return $schedules;
	}

	// ── Scheduling logic ───────────────────────────────────────────────────────

	/**
	 * Check and (re)schedule the cron event on every page load.
	 *
	 * - If sync is disabled: clears any existing scheduled event.
	 * - If sync is enabled and not yet scheduled: schedules it.
	 * - If sync is enabled but with a stale interval: reschedules.
	 */
	public function maybe_schedule(): void {
		$enabled    = get_option( 'sev_enabled', 'no' ) === 'yes';
		$recurrence = $this->get_recurrence();
		$next       = wp_get_scheduled_event( 'sev_sync_cron' );

		if ( ! $enabled ) {
			if ( $next ) {
				wp_clear_scheduled_hook( 'sev_sync_cron' );
			}
			return;
		}

		if ( ! $next ) {
			// Schedule first run one hour from now to avoid running immediately after save.
			wp_schedule_event( time() + HOUR_IN_SECONDS, $recurrence, 'sev_sync_cron' );
			return;
		}

		// Reschedule if the configured interval has changed.
		if ( $next->schedule !== $recurrence ) {
			wp_clear_scheduled_hook( 'sev_sync_cron' );
			wp_schedule_event( time() + HOUR_IN_SECONDS, $recurrence, 'sev_sync_cron' );
		}
	}

	// ── Cron callback ─────────────────────────────────────────────────────────

	/**
	 * Invoked by WP Cron. Runs a full blocking sync.
	 */
	public function run_sync(): void {
		$handler = new SEV_Sync_Handler();
		$handler->run_full_sync();
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Map the configured hour count to a WP Cron recurrence slug.
	 *
	 * @return string  WP Cron recurrence slug.
	 */
	public function get_recurrence(): string {
		$hours = (int) get_option( 'sev_sync_interval', '8' );
		switch ( $hours ) {
			case 6:
				return 'sev_every_6h';
			case 12:
				return 'twicedaily';
			case 24:
				return 'daily';
			default:
				return 'sev_every_8h'; // 8h default
		}
	}

	/**
	 * Human-readable time until the next scheduled sync.
	 *
	 * @return string  e.g. "in 3h 22m", "Not scheduled".
	 */
	public static function get_next_run_text(): string {
		$next = wp_next_scheduled( 'sev_sync_cron' );
		if ( ! $next ) {
			return 'Not scheduled';
		}

		$diff = $next - time();
		if ( $diff <= 0 ) {
			return 'Imminent';
		}

		$hours = (int) floor( $diff / 3600 );
		$mins  = (int) floor( ( $diff % 3600 ) / 60 );

		if ( $hours > 0 ) {
			return sprintf( 'in %dh %02dm', $hours, $mins );
		}
		return sprintf( 'in %dm', $mins );
	}
}
