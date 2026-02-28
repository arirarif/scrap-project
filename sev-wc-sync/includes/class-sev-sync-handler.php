<?php
/**
 * Sync Handler — orchestrates the full scrape → transform → import → cleanup pipeline.
 *
 * Two modes:
 *
 *   Cron mode  (run_full_sync):
 *     Single blocking PHP execution. set_time_limit(0) removes the PHP timeout.
 *     Called by SEV_Cron via the 'sev_sync_cron' WP Cron hook.
 *
 *   AJAX mode  (init_batch / process_batch):
 *     init_batch  — one call — fetches all Shopify pages, stores raw data in a
 *                   transient, returns the initial state object to the browser.
 *     process_batch — called repeatedly — transforms + imports BATCH_SIZE products
 *                   per call until offset >= total, then runs cleanup and finishes.
 *
 * State storage:
 *   WP option  'sev_batch_state'      — current phase/offset/stats (survives page loads)
 *   Transient  'sev_batch_products'   — raw Shopify product array   (24h TTL)
 *   Transient  'sev_batch_existing'   — [external_id → wc_id] map   (24h TTL)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SEV_Sync_Handler {

	/** Number of products to import per AJAX process_batch call. */
	private const BATCH_SIZE = 10;

	// ── Cron entry point ───────────────────────────────────────────────────────

	/**
	 * Run a complete sync in one blocking PHP execution.
	 *
	 * Called by SEV_Cron — no browser is waiting, so there is no JS timeout.
	 * @set_time_limit(0) removes the PHP execution limit for the duration.
	 *
	 * @return array  Sync stats: {created, updated, skipped, deleted, errors, duration}.
	 */
	public function run_full_sync(): array {
		@set_time_limit( 0 );
		@ini_set( 'memory_limit', '512M' );
		wp_suspend_cache_addition( true );

		$logger  = new SEV_Logger();
		$started = microtime( true );
		$stats   = [ 'created' => 0, 'updated' => 0, 'skipped' => 0, 'deleted' => 0, 'errors' => 0 ];

		$logger->clean_old_logs();
		$logger->start_sync();

		// ── Fetch all Shopify products ─────────────────────────────────────────
		$api          = new SEV_Api_Client();
		$raw_products = $api->fetch_all();

		if ( empty( $raw_products ) ) {
			$logger->error( 'Full sync aborted — no products fetched from API' );
			$stats['duration'] = 0;
			return $stats;
		}

		$logger->info( 'Products fetched', [ 'count' => count( $raw_products ) ] );

		// ── Prepare collaborators ─────────────────────────────────────────────
		$mapper      = new SEV_Product_Mapper();
		$state_mgr   = new SEV_State_Manager();
		$transformer = new SEV_Transformer();

		// Remove duplicate WC products (same Shopify ID mapped to multiple WC posts)
		// before the import loop. Duplicates arise when a prior broken sync created
		// new _sev_external_id products without cleaning up old _external_id ones.
		// They cause WC's SKU uniqueness check to fire and must be cleared first.
		$dupes = $mapper->delete_duplicate_products();
		if ( $dupes > 0 ) {
			$logger->info( 'Duplicate products purged before sync', [ 'removed' => $dupes ] );
		}

		$existing    = $mapper->get_all_synced_products(); // [external_id => wc_id]

		// ── Import ────────────────────────────────────────────────────────────
		foreach ( $raw_products as $raw ) {
			$id = (string) ( $raw['id'] ?? '' );
			if ( $id === '' ) {
				continue;
			}

			try {
				$product = $transformer->transform( $raw );
				$wc_id   = $existing[ $id ] ?? null;

				// Skip if nothing changed since last sync.
				if ( $wc_id && ! $state_mgr->is_changed( $wc_id, $product ) ) {
					$stats['skipped']++;
					continue;
				}

				$result = $mapper->map_product( $product, $wc_id );

				if ( is_wp_error( $result ) ) {
					$stats['errors']++;
					$logger->error( 'Product mapping failed', [
						'id'    => $id,
						'error' => $result->get_error_message(),
					] );
				} elseif ( $wc_id ) {
					$state_mgr->store_hash( $result, $product );
					$stats['updated']++;
				} else {
					$state_mgr->store_hash( $result, $product );
					$stats['created']++;
				}
			} catch ( Exception $e ) {
				$stats['errors']++;
				$logger->error( 'Exception while processing product', [
					'id'    => $id,
					'error' => $e->getMessage(),
				] );
			}
		}

		// ── Cleanup products removed from Shopify ─────────────────────────────
		$stats['deleted'] = $this->cleanup( $raw_products, $existing );

		$stats['duration'] = round( microtime( true ) - $started, 1 );

		update_option( 'sev_last_sync',       current_time( 'mysql' ) );
		update_option( 'sev_last_sync_stats', $stats );

		$logger->end_sync( $stats );

		return $stats;
	}

	// ── AJAX batch entry points ────────────────────────────────────────────────

	/**
	 * Initialise a batch sync.
	 *
	 * Fetches all Shopify pages (expensive — may take 30-150 s).
	 * Stores the raw product array and the existing product map in transients.
	 * Returns the initial state object; the JS then calls process_batch() repeatedly.
	 *
	 * @return array|WP_Error  Initial batch state, or WP_Error on failure.
	 */
	public function init_batch() {
		@set_time_limit( 0 );
		@ini_set( 'memory_limit', '512M' );

		$logger = new SEV_Logger();
		$logger->clean_old_logs();
		$logger->start_sync();

		// Wipe any leftover state from a previous (possibly incomplete) run.
		self::reset_batch();

		// ── Fetch ─────────────────────────────────────────────────────────────
		$api          = new SEV_Api_Client();
		$raw_products = $api->fetch_all();

		if ( empty( $raw_products ) ) {
			$logger->error( 'Batch sync aborted — no products fetched from API' );
			return new WP_Error(
				'fetch_failed',
				'No products could be fetched from the Shopify API. Check the Source URL setting and make sure the server can reach sev-stromerzeuger.com.'
			);
		}

		$total = count( $raw_products );
		$logger->info( 'Products fetched', [ 'count' => $total ] );

		// Persist raw data for process_batch() calls (24h TTL — far longer than any sync).
		set_transient( 'sev_batch_products', $raw_products, DAY_IN_SECONDS );

		// Remove duplicate WC products before taking the existing-map snapshot.
		// Duplicates cause SKU uniqueness failures during batch import.
		$mapper = new SEV_Product_Mapper();
		$dupes  = $mapper->delete_duplicate_products();
		if ( $dupes > 0 ) {
			$logger->info( 'Duplicate products purged before batch', [ 'removed' => $dupes ] );
		}

		// Snapshot the existing product map at init time.
		// process_batch() uses this to decide create vs. update.
		$existing = $mapper->get_all_synced_products();
		set_transient( 'sev_batch_existing', $existing, DAY_IN_SECONDS );

		// ── Build and store initial state ─────────────────────────────────────
		$state = [
			'status'     => 'processing',
			'phase'      => 'import',
			'offset'     => 0,
			'total'      => $total,
			'progress'   => 0,
			'stats'      => [ 'created' => 0, 'updated' => 0, 'skipped' => 0, 'deleted' => 0, 'errors' => 0 ],
			'started_at' => current_time( 'mysql' ),
			'message'    => sprintf( 'Fetched %d products. Starting import…', $total ),
		];

		update_option( 'sev_batch_state', $state, false );

		return $state;
	}

	/**
	 * Process the next BATCH_SIZE products (transform → import).
	 *
	 * Called repeatedly by the browser until state['status'] === 'complete'.
	 * Each call updates and returns the batch state.
	 *
	 * @return array  Batch state: {status, phase, offset, total, progress, stats, message}.
	 */
	public function process_batch(): array {
		$state = get_option( 'sev_batch_state', [ 'status' => 'idle' ] );

		// Nothing to do if we're not actively processing.
		if ( $state['status'] !== 'processing' ) {
			return $state;
		}

		// Load persisted raw data.
		$raw_products = get_transient( 'sev_batch_products' );
		if ( $raw_products === false ) {
			$state['status']  = 'error';
			$state['message'] = 'Batch data expired or missing. Please start the sync again.';
			update_option( 'sev_batch_state', $state, false );
			return $state;
		}

		$existing = get_transient( 'sev_batch_existing' ) ?: [];

		$transformer = new SEV_Transformer();
		$state_mgr   = new SEV_State_Manager();
		$mapper      = new SEV_Product_Mapper();

		$offset = (int) $state['offset'];
		$total  = (int) $state['total'];
		$batch  = array_slice( $raw_products, $offset, self::BATCH_SIZE );

		// ── Process this slice ────────────────────────────────────────────────
		foreach ( $batch as $raw ) {
			$id = (string) ( $raw['id'] ?? '' );
			if ( $id === '' ) {
				continue;
			}

			try {
				$product = $transformer->transform( $raw );
				$wc_id   = $existing[ $id ] ?? null;

				if ( $wc_id && ! $state_mgr->is_changed( $wc_id, $product ) ) {
					$state['stats']['skipped']++;
					continue;
				}

				$result = $mapper->map_product( $product, $wc_id );

				if ( is_wp_error( $result ) ) {
					$state['stats']['errors']++;
				} elseif ( $wc_id ) {
					$state_mgr->store_hash( $result, $product );
					$state['stats']['updated']++;
				} else {
					$state_mgr->store_hash( $result, $product );
					$state['stats']['created']++;
				}
			} catch ( Exception $e ) {
				$state['stats']['errors']++;
			}
		}

		$state['offset'] = $offset + count( $batch );

		// ── All products processed — run cleanup and finish ───────────────────
		if ( $state['offset'] >= $total ) {
			$deleted                   = $this->cleanup( $raw_products, $existing );
			$state['stats']['deleted'] = $deleted;

			$state['status']      = 'complete';
			$state['phase']       = 'complete';
			$state['progress']    = 100;
			$state['finished_at'] = current_time( 'mysql' );
			$state['message']     = 'Sync complete.';

			update_option( 'sev_last_sync',       current_time( 'mysql' ) );
			update_option( 'sev_last_sync_stats', $state['stats'] );

			// Free transient memory.
			delete_transient( 'sev_batch_products' );
			delete_transient( 'sev_batch_existing' );

			$logger = new SEV_Logger();
			$logger->end_sync( $state['stats'] );
		} else {
			$state['progress'] = (int) round( ( $state['offset'] / $total ) * 100 );
			$state['message']  = sprintf(
				'Importing %d of %d products…',
				min( $state['offset'], $total ),
				$total
			);
		}

		update_option( 'sev_batch_state', $state, false );

		return $state;
	}

	// ── Static helpers (used by main plugin file) ──────────────────────────────

	/**
	 * Clear all batch state and transients.
	 * Called by "Reset Cache" button and at the start of init_batch().
	 */
	public static function reset_batch(): void {
		delete_option( 'sev_batch_state' );
		delete_transient( 'sev_batch_products' );
		delete_transient( 'sev_batch_product_ids' ); // legacy key — kept for safety
		delete_transient( 'sev_batch_existing' );
	}

	/**
	 * Return the current batch state for display in the admin UI.
	 *
	 * @return array  Batch state, or ['status' => 'idle'] if no sync is queued.
	 */
	public static function get_batch_state(): array {
		return get_option( 'sev_batch_state', [ 'status' => 'idle' ] );
	}

	// ── Cleanup ────────────────────────────────────────────────────────────────

	/**
	 * Trash or permanently delete WC products no longer present on Shopify.
	 *
	 * Compares $existing (products in WC at sync-start) against $raw_products
	 * (everything currently on Shopify). Any WC product whose external ID is
	 * absent from the fetched batch is treated as removed from the source store.
	 *
	 * @param  array $raw_products  All raw Shopify products from the fetch.
	 * @param  array $existing      [ external_id => wc_id ] snapshot from before the sync.
	 * @return int                  Number of products removed.
	 */
	private function cleanup( array $raw_products, array $existing ): int {
		if ( empty( $existing ) ) {
			return 0;
		}

		// Build a fast lookup set from the live Shopify data.
		$fetched_ids = [];
		foreach ( $raw_products as $raw ) {
			$id = (string) ( $raw['id'] ?? '' );
			if ( $id !== '' ) {
				$fetched_ids[ $id ] = true;
			}
		}

		$delete_behavior = get_option( 'sev_delete_behavior', 'trash' );
		$logger          = new SEV_Logger();
		$removed         = 0;

		foreach ( $existing as $external_id => $wc_id ) {
			if ( isset( $fetched_ids[ (string) $external_id ] ) ) {
				continue; // still live on Shopify
			}

			// Product has disappeared from Shopify — remove it from WC.
			if ( $delete_behavior === 'delete' ) {
				wp_delete_post( (int) $wc_id, true );
			} else {
				wp_trash_post( (int) $wc_id );
			}

			$logger->info( 'Removed stale product', [
				'external_id' => $external_id,
				'wc_id'       => $wc_id,
				'action'      => $delete_behavior,
			] );
			$removed++;
		}

		if ( $removed > 0 ) {
			$logger->info( 'Cleanup complete', [ 'removed' => $removed ] );
		}

		return $removed;
	}
}
