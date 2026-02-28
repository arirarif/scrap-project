<?php
/**
 * Sync Handler Class
 *
 * Core synchronization logic for create, update, and delete operations
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

        // Check if anything changed (optional quick check)
        // We'll still do per-product comparison
        $this->update_progress('comparing', __('Comparing products...', 'wc-product-sync'), 10);

        // Get existing synced products
        $existing_products = $this->mapper->get_all_synced_products();
        $json_ids = array_keys($json_data);

        // Process products in batches
        $processed = 0;
        $total = count($json_data);

        foreach ($json_data as $external_id => $product_data) {
            $result = $this->process_product($external_id, $product_data, $existing_products);
            $processed++;

            // Update progress every 10 products
            if ($processed % 10 === 0) {
                $progress = 10 + (($processed / $total) * 70);
                $this->update_progress(
                    'processing',
                    sprintf(__('Processing product %d of %d...', 'wc-product-sync'), $processed, $total),
                    $progress
                );
            }
        }

        // Find products to delete (in existing but not in JSON)
        $this->update_progress('cleanup', __('Cleaning up removed products...', 'wc-product-sync'), 85);

        foreach ($existing_products as $ext_id => $product_id) {
            if (!isset($json_data[$ext_id])) {
                $this->delete_product($product_id, $ext_id);
            }
        }

        // Link alternative products
        $this->update_progress('linking', __('Linking alternative products...', 'wc-product-sync'), 90);
        $this->mapper->link_alternative_products();

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
     * Process a single product
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
}
