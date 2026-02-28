<?php
/**
 * Sync Handler Class
 *
 * Core synchronization logic for create, update, and delete operations
 * All products are imported as simple products with linked alternative buttons
 *
 * @package WC_Product_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCPS_Sync_Handler {

    /**
     * Logger instance
     */
    private $logger;

    /**
     * JSON Fetcher instance
     */
    private $fetcher;

    /**
     * Product Mapper instance
     */
    private $mapper;

    /**
     * State Manager instance
     */
    private $state_manager;

    /**
     * Whether previous state is available (checked once per sync)
     */
    private $has_previous_state = false;

    /**
     * Sync statistics
     */
    private $stats = [
        'created' => 0,
        'updated' => 0,
        'deleted' => 0,
        'skipped' => 0,
        'errors' => 0,
        'total' => 0,
    ];

    /**
     * Batch size for processing
     */
    private $batch_size = 50;

    /**
     * Constructor
     */
    public function __construct() {
        $this->logger = new WCPS_Logger();
        $this->fetcher = new WCPS_JSON_Fetcher();
        $this->mapper = new WCPS_Product_Mapper();
        $this->state_manager = new WCPS_State_Manager();
    }

    /**
     * Run the synchronization
     *
     * @return array|WP_Error Sync statistics or error
     */
    public function run_sync() {
        // Check if sync is enabled
        if (get_option('wcps_enabled', 'no') !== 'yes') {
            $this->logger->info('Sync is disabled, skipping');
            return new WP_Error('sync_disabled', __('Sync is disabled.', 'wc-product-sync'));
        }

        // Extend execution time and memory for large syncs
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        // Disable WordPress object cache to prevent memory buildup
        wp_suspend_cache_addition(true);

        $this->logger->start_sync();
        $start_time = microtime(true);

        // Update progress
        $this->update_progress('fetching', __('Fetching JSON data...', 'wc-product-sync'), 5);

        // Fetch JSON
        $json_data = $this->fetcher->fetch();

        if (is_wp_error($json_data)) {
            $this->update_sync_status('error', $json_data->get_error_message());
            return $json_data;
        }

        $this->stats['total'] = count($json_data);

        // Check if previous state is available for field-level change detection
        $previous_index = $this->state_manager->load_previous_state();
        if ($previous_index === null) {
            $this->has_previous_state = false;
            $this->logger->info('No previous state found - this is the first sync or cache was cleared');
        } else {
            $this->has_previous_state = true;
            $this->logger->info('Previous state index loaded for streaming comparison', [
                'previous_products' => count($previous_index),
            ]);
        }

        // Get existing synced products
        $existing_products = $this->mapper->get_all_synced_products();

        // PHASE 1: Process all products as simple products
        $this->update_progress('processing', __('Processing products...', 'wc-product-sync'), 10);

        $processed = 0;
        $total = count($json_data);

        foreach ($json_data as $external_id => $product_data) {
            $this->process_product($external_id, $product_data, $existing_products);
            $processed++;

            // Update progress every 10 products
            if ($processed % 10 === 0) {
                $progress = 10 + (($processed / max($total, 1)) * 70);
                $this->update_progress(
                    'processing',
                    sprintf(__('Processing product %d of %d...', 'wc-product-sync'), $processed, $total),
                    $progress
                );
            }
        }

        $this->logger->info('Products processed', ['count' => $processed]);

        // PHASE 2: Cleanup - find products to delete (in existing but not in JSON)
        $this->update_progress('cleanup', __('Cleaning up removed products...', 'wc-product-sync'), 82);

        foreach ($existing_products as $ext_id => $product_id) {
            if (!isset($json_data[$ext_id])) {
                // Skip products already in trash — avoids inflating deleted count
                // with no-op wp_trash_post() calls on already-trashed products
                if (get_post_status($product_id) !== 'trash') {
                    $this->delete_product($product_id, $ext_id);
                }
            }
        }

        // PHASE 3: Finalize
        // Link alternative products (cross-sells)
        $this->update_progress('linking', __('Linking alternative products...', 'wc-product-sync'), 88);
        $this->mapper->link_alternative_products();

        // Resolve alternative product URLs for frontend rendering
        $this->update_progress('resolving', __('Resolving alternative product URLs...', 'wc-product-sync'), 90);
        $this->mapper->resolve_alternative_product_urls();

        // Save hash for future comparison
        $this->fetcher->save_hash($json_data);

        // Persist state for next sync comparison
        $this->update_progress('saving_state', __('Saving sync state...', 'wc-product-sync'), 92);
        if ($this->state_manager->rotate_state()) {
            if ($this->state_manager->save_state($json_data)) {
                $this->logger->info('Sync state persisted for future comparison');
            }
        }

        // Calculate duration
        $duration = round(microtime(true) - $start_time, 2);
        $this->stats['duration'] = $duration;

        // Update last sync info
        update_option('wcps_last_sync', current_time('mysql'));
        update_option('wcps_last_sync_stats', $this->stats);

        $this->update_progress('complete', __('Sync completed!', 'wc-product-sync'), 100);
        $this->update_sync_status('success', sprintf(
            __('Created: %d, Updated: %d, Deleted: %d, Skipped: %d, Errors: %d', 'wc-product-sync'),
            $this->stats['created'],
            $this->stats['updated'],
            $this->stats['deleted'],
            $this->stats['skipped'],
            $this->stats['errors']
        ));

        $this->logger->end_sync($this->stats);

        return $this->stats;
    }

    /**
     * Process a single product (simple product path)
     *
     * @param string $external_id External product ID
     * @param array $product_data Product data from JSON
     * @param array $existing_products Map of external_id => product_id
     * @return bool Success
     */
    private function process_product($external_id, $product_data, $existing_products) {
        // Ensure the product data has the correct ID
        $product_data['Id'] = $external_id;

        // Check if product exists
        $existing_product_id = $existing_products[$external_id] ?? null;

        if ($existing_product_id) {
            // Check if product has changed
            $stored_hash = get_post_meta($existing_product_id, '_wcps_hash', true);
            $current_hash = $this->fetcher->get_product_hash($product_data);

            if ($stored_hash === $current_hash) {
                // No changes, skip
                $this->stats['skipped']++;
                return true;
            }

            // Detect field-level changes for logging (streaming - loads only this product)
            $field_changes = [];
            if ($this->has_previous_state) {
                $previous_product = $this->state_manager->get_previous_product($external_id);
                if ($previous_product !== null) {
                    $field_changes = $this->state_manager->compare_products(
                        $previous_product,
                        $product_data
                    );
                }
            }

            // Update existing product
            $result = $this->mapper->map_product($product_data, $existing_product_id);

            if (is_wp_error($result)) {
                $this->stats['errors']++;
                $this->logger->error('Failed to update product', [
                    'external_id' => $external_id,
                    'error' => $result->get_error_message(),
                ]);
                return false;
            }

            $this->stats['updated']++;

            // Log with field-level changes if detected
            if (!empty($field_changes)) {
                $this->logger->info('Updated product with field changes', [
                    'external_id' => $external_id,
                    'product_id' => $result,
                    'changed_fields' => array_keys($field_changes),
                    'changes' => $field_changes,
                ]);
            } else {
                $this->logger->info('Updated product', [
                    'external_id' => $external_id,
                    'product_id' => $result,
                ]);
            }

        } else {
            // Create new product
            $result = $this->mapper->map_product($product_data);

            if (is_wp_error($result)) {
                $this->stats['errors']++;
                $this->logger->error('Failed to create product', [
                    'external_id' => $external_id,
                    'error' => $result->get_error_message(),
                ]);
                return false;
            }

            $this->stats['created']++;
            $this->logger->info('Created product', [
                'external_id' => $external_id,
                'product_id' => $result,
            ]);
        }

        return true;
    }

    /**
     * Delete (trash) a product
     *
     * @param int $product_id WC Product ID
     * @param string $external_id External ID for logging
     */
    private function delete_product($product_id, $external_id) {
        $delete_behavior = get_option('wcps_delete_behavior', 'trash');

        if ($delete_behavior === 'trash') {
            wp_trash_post($product_id);
            $this->logger->info('Trashed product', [
                'external_id' => $external_id,
                'product_id' => $product_id,
            ]);
        } else {
            wp_delete_post($product_id, true);
            $this->logger->info('Deleted product permanently', [
                'external_id' => $external_id,
                'product_id' => $product_id,
            ]);
        }

        $this->stats['deleted']++;
    }

    /**
     * Update sync progress
     *
     * @param string $status Current status
     * @param string $message Status message
     * @param int $progress Progress percentage (0-100)
     */
    private function update_progress($status, $message, $progress) {
        update_option('wcps_sync_progress', [
            'status' => $status,
            'message' => $message,
            'progress' => $progress,
            'stats' => $this->stats,
        ]);
    }

    /**
     * Update final sync status
     *
     * @param string $status 'success' or 'error'
     * @param string $message Status message
     */
    private function update_sync_status($status, $message) {
        update_option('wcps_last_sync_status', [
            'status' => $status,
            'message' => $message,
            'time' => current_time('mysql'),
        ]);
    }

    /**
     * Get sync statistics
     *
     * @return array
     */
    public function get_stats() {
        return $this->stats;
    }

    /**
     * Check if sync is currently running
     *
     * @return bool
     */
    public static function is_running() {
        return (bool) get_option('wcps_sync_running', false);
    }

    /**
     * Get current progress
     *
     * @return array
     */
    public static function get_progress() {
        return get_option('wcps_sync_progress', [
            'status' => 'idle',
            'message' => '',
            'progress' => 0,
        ]);
    }

    /**
     * Initialize batch sync - fetches JSON and prepares for batch processing
     *
     * @return array|WP_Error Batch state or error
     */
    public function init_batch() {
        // Check if sync is enabled
        if (get_option('wcps_enabled', 'no') !== 'yes') {
            return new WP_Error('sync_disabled', __('Sync is disabled.', 'wc-product-sync'));
        }

        // Check if already running
        $existing_state = get_option('wcps_batch_state');
        if ($existing_state && $existing_state['status'] === 'running') {
            return $existing_state;
        }

        $this->logger->start_sync();
        $this->logger->info('Initializing batch sync');

        // Fetch JSON
        $json_data = $this->fetcher->fetch();
        if (is_wp_error($json_data)) {
            return $json_data;
        }

        // Store JSON in transient (expires in 2 hours)
        set_transient('wcps_batch_json', $json_data, 2 * HOUR_IN_SECONDS);

        // All products are simple — just use all IDs
        $all_ids = array_keys($json_data);
        set_transient('wcps_batch_product_ids', $all_ids, 2 * HOUR_IN_SECONDS);

        // Get existing synced products
        $existing_products = $this->mapper->get_all_synced_products();
        set_transient('wcps_batch_existing', $existing_products, 2 * HOUR_IN_SECONDS);

        // Init batch state
        $state = [
            'status' => 'running',
            'phase' => 'products',
            'offset' => 0,
            'total_products' => count($json_data),
            'stats' => [
                'created' => 0,
                'updated' => 0,
                'deleted' => 0,
                'skipped' => 0,
                'errors' => 0,
            ],
            'started_at' => time(),
            'updated_at' => time(),
        ];

        update_option('wcps_batch_state', $state);

        $this->logger->info('Batch sync initialized', [
            'total_products' => count($json_data),
        ]);

        return $state;
    }

    /**
     * Process one batch of products
     *
     * @return array Batch state with progress info
     */
    public function process_batch() {
        @set_time_limit(60);
        @ini_set('memory_limit', '512M');

        $state = get_option('wcps_batch_state');
        if (!$state || $state['status'] !== 'running') {
            return ['status' => 'idle', 'message' => 'No batch in progress'];
        }

        $json_data = get_transient('wcps_batch_json');
        if (!$json_data) {
            $state['status'] = 'error';
            $state['message'] = 'Batch data expired. Please restart sync.';
            update_option('wcps_batch_state', $state);
            return $state;
        }

        $this->stats = $state['stats'];

        // Process based on current phase
        if ($state['phase'] === 'products') {
            $state = $this->process_products_batch($state, $json_data);
        } elseif ($state['phase'] === 'cleanup') {
            $state = $this->process_cleanup_batch($state, $json_data);
        } elseif ($state['phase'] === 'finalize') {
            $state = $this->finalize_batch($state, $json_data);
        }

        $state['stats'] = $this->stats;
        $state['updated_at'] = time();
        update_option('wcps_batch_state', $state);

        // Update progress display
        $this->update_batch_progress($state);

        return $state;
    }

    /**
     * Process a batch of products
     */
    private function process_products_batch($state, $json_data) {
        $product_ids = get_transient('wcps_batch_product_ids');
        $existing_products = get_transient('wcps_batch_existing');
        $batch_size = 50;

        $offset = $state['offset'];
        $total = $state['total_products'];

        // Get batch of IDs to process
        $batch_ids = array_slice($product_ids, $offset, $batch_size);

        if (empty($batch_ids)) {
            // Done with products, move to cleanup
            $state['phase'] = 'cleanup';
            $this->logger->info('Products batch complete', ['processed' => $offset]);
            return $state;
        }

        foreach ($batch_ids as $external_id) {
            if (isset($json_data[$external_id])) {
                $this->process_product($external_id, $json_data[$external_id], $existing_products);
            }
        }

        $state['offset'] = $offset + count($batch_ids);

        $this->logger->info('Batch processed', [
            'offset' => $state['offset'],
            'total' => $total,
        ]);

        return $state;
    }

    /**
     * Process cleanup batch (delete removed products)
     */
    private function process_cleanup_batch($state, $json_data) {
        $existing_products = get_transient('wcps_batch_existing');

        if ($existing_products) {
            foreach ($existing_products as $ext_id => $product_id) {
                if (!isset($json_data[$ext_id])) {
                    // Skip products already in trash — avoids inflating deleted count
                    // with no-op wp_trash_post() calls on already-trashed products
                    if (get_post_status($product_id) !== 'trash') {
                        $this->delete_product($product_id, $ext_id);
                    }
                }
            }
        }

        $state['phase'] = 'finalize';
        $this->logger->info('Cleanup batch complete');

        return $state;
    }

    /**
     * Finalize batch sync
     */
    private function finalize_batch($state, $json_data) {
        // Link alternative products
        $this->mapper->link_alternative_products();

        // Resolve alternative product URLs for frontend rendering
        $this->mapper->resolve_alternative_product_urls();

        // Save hash for future comparison
        $this->fetcher->save_hash($json_data);

        // Calculate duration
        $duration = time() - $state['started_at'];
        $state['stats']['duration'] = $duration;

        // Update last sync info
        update_option('wcps_last_sync', current_time('mysql'));
        update_option('wcps_last_sync_stats', $state['stats']);

        // Mark as complete
        $state['status'] = 'complete';
        $state['phase'] = 'done';

        // Clear transients
        delete_transient('wcps_batch_json');
        delete_transient('wcps_batch_product_ids');
        delete_transient('wcps_batch_existing');

        $this->update_sync_status('success', sprintf(
            __('Created: %d, Updated: %d, Deleted: %d, Skipped: %d, Errors: %d', 'wc-product-sync'),
            $state['stats']['created'],
            $state['stats']['updated'],
            $state['stats']['deleted'],
            $state['stats']['skipped'],
            $state['stats']['errors']
        ));

        $this->logger->end_sync($state['stats']);

        return $state;
    }

    /**
     * Update progress display for batch mode
     */
    private function update_batch_progress($state) {
        $phase = $state['phase'];
        $progress = 0;
        $message = '';

        if ($phase === 'products') {
            $progress = ($state['offset'] / max($state['total_products'], 1)) * 85;
            $message = sprintf(
                __('Processing products: %d / %d', 'wc-product-sync'),
                $state['offset'],
                $state['total_products']
            );
        } elseif ($phase === 'cleanup') {
            $progress = 88;
            $message = __('Cleaning up...', 'wc-product-sync');
        } elseif ($phase === 'finalize') {
            $progress = 95;
            $message = __('Finalizing...', 'wc-product-sync');
        } elseif ($phase === 'done') {
            $progress = 100;
            $message = __('Complete!', 'wc-product-sync');
        }

        update_option('wcps_sync_progress', [
            'status' => $phase,
            'message' => $message,
            'progress' => round($progress),
            'stats' => $state['stats'],
        ]);
    }

    /**
     * Reset batch state (for manual reset)
     */
    public static function reset_batch() {
        delete_option('wcps_batch_state');
        delete_transient('wcps_batch_json');
        delete_transient('wcps_batch_product_ids');
        delete_transient('wcps_batch_existing');
    }

    /**
     * Get batch state
     */
    public static function get_batch_state() {
        return get_option('wcps_batch_state', ['status' => 'idle']);
    }
}
