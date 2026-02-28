<?php
/**
 * Plugin Name: WC Product Sync
 * Plugin URI: https://fb.com/mahfuzcmt
 * Description: Sync WooCommerce products from a remote JSON file with automatic create, update, and delete operations.
 * Version: 1.0.0
 * Author: Mahfuz Ahmed
 * Author URI: https://fb.com/mahfuzcmt
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wc-product-sync
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('WCPS_VERSION', '1.0.0');
define('WCPS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WCPS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WCPS_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Declare compatibility with WooCommerce HPOS (High-Performance Order Storage)
 */
add_action('before_woocommerce_init', function() {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

/**
 * Main Plugin Class
 */
final class WC_Product_Sync {

    /**
     * Single instance
     */
    private static $instance = null;

    /**
     * Get instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->check_dependencies();
        $this->includes();
        $this->init_hooks();
    }

    /**
     * Check if WooCommerce is active
     */
    private function check_dependencies() {
        add_action('admin_init', function() {
            if (!class_exists('WooCommerce')) {
                add_action('admin_notices', function() {
                    echo '<div class="error"><p>';
                    echo esc_html__('WC Product Sync requires WooCommerce to be installed and active.', 'wc-product-sync');
                    echo '</p></div>';
                });
                deactivate_plugins(WCPS_PLUGIN_BASENAME);
            }
        });
    }

    /**
     * Include required files
     */
    private function includes() {
        require_once WCPS_PLUGIN_DIR . 'includes/class-wcps-logger.php';
        require_once WCPS_PLUGIN_DIR . 'includes/class-wcps-json-fetcher.php';
        require_once WCPS_PLUGIN_DIR . 'includes/class-wcps-product-mapper.php';
        require_once WCPS_PLUGIN_DIR . 'includes/class-wcps-variation-manager.php';
        require_once WCPS_PLUGIN_DIR . 'includes/class-wcps-state-manager.php';
        require_once WCPS_PLUGIN_DIR . 'includes/class-wcps-sync-handler.php';
        require_once WCPS_PLUGIN_DIR . 'includes/class-wcps-admin-settings.php';
        require_once WCPS_PLUGIN_DIR . 'includes/class-wcps-cron.php';
        require_once WCPS_PLUGIN_DIR . 'includes/class-wcps-frontend.php';
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Activation/Deactivation
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);

        // Initialize components
        add_action('plugins_loaded', [$this, 'init_components']);

        // AJAX handlers
        add_action('wp_ajax_wcps_manual_sync', [$this, 'ajax_manual_sync']);
        add_action('wp_ajax_wcps_continue_sync', [$this, 'ajax_continue_sync']);
        add_action('wp_ajax_wcps_get_sync_status', [$this, 'ajax_get_sync_status']);
        add_action('wp_ajax_wcps_trash_all', [$this, 'ajax_trash_all']);
        add_action('wp_ajax_wcps_empty_trash', [$this, 'ajax_empty_trash']);
        add_action('wp_ajax_wcps_reset_sync', [$this, 'ajax_reset_sync']);
    }

    /**
     * Initialize components after plugins loaded
     */
    public function init_components() {
        if (!class_exists('WooCommerce')) {
            return;
        }

        WCPS_Admin_Settings::get_instance();
        WCPS_Cron::get_instance();
        WCPS_Frontend::get_instance();
    }

    /**
     * Plugin activation
     */
    public function activate() {
        // Create logs directory
        $log_dir = WCPS_PLUGIN_DIR . 'logs';
        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir);
            // Protect logs directory
            file_put_contents($log_dir . '/.htaccess', 'deny from all');
            file_put_contents($log_dir . '/index.php', '<?php // Silence is golden');
        }

        // Create cache directory for state persistence
        $cache_dir = WCPS_PLUGIN_DIR . 'cache';
        if (!file_exists($cache_dir)) {
            wp_mkdir_p($cache_dir);
            // Protect cache directory
            file_put_contents($cache_dir . '/.htaccess', 'deny from all');
            file_put_contents($cache_dir . '/index.php', '<?php // Silence is golden');
        }

        // Set default options
        $defaults = [
            'json_url' => '',
            'sync_interval_value' => 1,
            'sync_interval_unit' => 'hours',
            'delete_behavior' => 'trash',
            'auto_create_categories' => 'yes',
            'sync_attributes' => 'yes',
            'last_sync' => '',
            'last_sync_status' => '',
            'enabled' => 'no',
        ];

        foreach ($defaults as $key => $value) {
            if (get_option('wcps_' . $key) === false) {
                update_option('wcps_' . $key, $value);
            }
        }

        // Note: Cron will be scheduled when user enables sync in settings

        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        wp_clear_scheduled_hook('wcps_sync_cron');
        flush_rewrite_rules();
    }

    /**
     * AJAX: Manual sync trigger (batch mode)
     */
    public function ajax_manual_sync() {
        // Enable error reporting for debugging
        @ini_set('display_errors', 0);
        error_reporting(E_ALL);

        try {
            check_ajax_referer('wcps_admin_nonce', 'nonce');

            if (!current_user_can('manage_woocommerce')) {
                wp_send_json_error(['message' => __('Permission denied.', 'wc-product-sync')]);
            }

            // Check if WooCommerce is active
            if (!class_exists('WooCommerce')) {
                wp_send_json_error(['message' => __('WooCommerce is not active.', 'wc-product-sync')]);
            }

            // Check if sync is enabled
            if (get_option('wcps_enabled', 'no') !== 'yes') {
                wp_send_json_error(['message' => __('Sync is disabled. Please enable it in Settings tab first.', 'wc-product-sync')]);
            }

            // Check if JSON URL is configured
            $json_url = get_option('wcps_json_url', '');
            if (empty($json_url)) {
                wp_send_json_error(['message' => __('No JSON URL configured. Please add a JSON URL in Settings tab.', 'wc-product-sync')]);
            }

            // Set flag that sync is running
            update_option('wcps_sync_running', true);
            update_option('wcps_sync_progress', [
                'status' => 'starting',
                'message' => __('Initializing batch sync...', 'wc-product-sync'),
                'progress' => 0,
            ]);

            // Initialize batch sync
            $sync_handler = new WCPS_Sync_Handler();
            $result = $sync_handler->init_batch();

            if (is_wp_error($result)) {
                delete_option('wcps_sync_running');
                wp_send_json_error([
                    'message' => $result->get_error_message(),
                ]);
            }

            // Process first batch
            $state = $sync_handler->process_batch();

            // Return state for frontend to continue
            wp_send_json_success([
                'status' => $state['status'],
                'phase' => $state['phase'] ?? 'unknown',
                'progress' => $this->calculate_batch_progress($state),
                'message' => $this->get_batch_message($state),
                'stats' => $state['stats'] ?? [],
            ]);

        } catch (Exception $e) {
            delete_option('wcps_sync_running');
            wp_send_json_error([
                'message' => 'Exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(),
            ]);
        } catch (Error $e) {
            delete_option('wcps_sync_running');
            wp_send_json_error([
                'message' => 'PHP Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(),
            ]);
        }
    }

    /**
     * AJAX: Continue batch sync
     */
    public function ajax_continue_sync() {
        try {
            check_ajax_referer('wcps_admin_nonce', 'nonce');

            if (!current_user_can('manage_woocommerce')) {
                wp_send_json_error(['message' => __('Permission denied.', 'wc-product-sync')]);
            }

            $sync_handler = new WCPS_Sync_Handler();
            $state = $sync_handler->process_batch();

            // If complete, clear running flag
            if ($state['status'] === 'complete' || $state['status'] === 'error' || $state['status'] === 'idle') {
                delete_option('wcps_sync_running');
            }

            wp_send_json_success([
                'status' => $state['status'],
                'phase' => $state['phase'] ?? 'unknown',
                'progress' => $this->calculate_batch_progress($state),
                'message' => $this->get_batch_message($state),
                'stats' => $state['stats'] ?? [],
            ]);

        } catch (Exception $e) {
            delete_option('wcps_sync_running');
            wp_send_json_error([
                'message' => 'Exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(),
            ]);
        } catch (Error $e) {
            delete_option('wcps_sync_running');
            wp_send_json_error([
                'message' => 'PHP Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(),
            ]);
        }
    }

    /**
     * Calculate progress percentage from batch state
     */
    private function calculate_batch_progress($state) {
        if ($state['status'] === 'complete') {
            return 100;
        }
        if ($state['status'] !== 'running') {
            return 0;
        }

        $phase = $state['phase'] ?? 'products';
        $progress = 0;

        if ($phase === 'products') {
            $total = max($state['total_products'] ?? 1, 1);
            $offset = $state['offset'] ?? 0;
            $progress = ($offset / $total) * 85;
        } elseif ($phase === 'cleanup') {
            $progress = 88;
        } elseif ($phase === 'finalize') {
            $progress = 95;
        } elseif ($phase === 'done') {
            $progress = 100;
        }

        return round($progress);
    }

    /**
     * Get human-readable message from batch state
     */
    private function get_batch_message($state) {
        if ($state['status'] === 'complete') {
            return __('Sync completed!', 'wc-product-sync');
        }
        if ($state['status'] === 'error') {
            return $state['message'] ?? __('Sync error', 'wc-product-sync');
        }
        if ($state['status'] === 'idle') {
            return __('No sync in progress', 'wc-product-sync');
        }

        $phase = $state['phase'] ?? 'products';

        if ($phase === 'products') {
            return sprintf(
                __('Processing products: %d / %d', 'wc-product-sync'),
                $state['offset'] ?? 0,
                $state['total_products'] ?? 0
            );
        } elseif ($phase === 'cleanup') {
            return __('Cleaning up removed products...', 'wc-product-sync');
        } elseif ($phase === 'finalize') {
            return __('Finalizing sync...', 'wc-product-sync');
        }

        return __('Processing...', 'wc-product-sync');
    }

    /**
     * AJAX: Reset stuck sync
     */
    public function ajax_reset_sync() {
        check_ajax_referer('wcps_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied.', 'wc-product-sync')]);
        }

        WCPS_Sync_Handler::reset_batch();
        delete_option('wcps_sync_running');

        wp_send_json_success([
            'message' => __('Sync state reset successfully.', 'wc-product-sync'),
        ]);
    }

    /**
     * AJAX: Get sync status
     */
    public function ajax_get_sync_status() {
        check_ajax_referer('wcps_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied.', 'wc-product-sync')]);
        }

        $is_running = get_option('wcps_sync_running', false);
        $progress = get_option('wcps_sync_progress', []);

        wp_send_json_success([
            'running' => $is_running,
            'progress' => $progress,
        ]);
    }

    /**
     * AJAX: Trash all synced products
     */
    public function ajax_trash_all() {
        check_ajax_referer('wcps_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied.', 'wc-product-sync')]);
        }

        global $wpdb;

        // Get all synced products (including variations)
        $product_ids = $wpdb->get_col(
            "SELECT DISTINCT post_id FROM {$wpdb->postmeta}
            WHERE meta_key = '_wcps_synced' AND meta_value = 'yes'"
        );

        if (empty($product_ids)) {
            wp_send_json_success([
                'message' => __('No synced products found.', 'wc-product-sync'),
                'count' => 0,
            ]);
        }

        $trashed = 0;
        $errors = 0;

        foreach ($product_ids as $product_id) {
            $result = wp_trash_post($product_id);
            if ($result) {
                $trashed++;
            } else {
                $errors++;
            }
        }

        // Clear sync state
        delete_option('wcps_last_json_hash');
        delete_option('wcps_product_hashes');

        // Clear state files
        $state_manager = new WCPS_State_Manager();
        $state_manager->clear_cache();

        // Log the action
        $logger = new WCPS_Logger();
        $logger->info('Trashed all synced products', [
            'trashed' => $trashed,
            'errors' => $errors,
        ]);

        wp_send_json_success([
            'message' => sprintf(
                __('Moved %d products to trash. Errors: %d', 'wc-product-sync'),
                $trashed,
                $errors
            ),
            'count' => $trashed,
            'errors' => $errors,
        ]);
    }

    /**
     * AJAX: Empty trash - permanently delete trashed synced products
     */
    public function ajax_empty_trash() {
        check_ajax_referer('wcps_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied.', 'wc-product-sync')]);
        }

        global $wpdb;

        // Get all trashed synced products (including variations)
        $product_ids = $wpdb->get_col(
            "SELECT DISTINCT p.ID FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE p.post_status = 'trash'
            AND pm.meta_key = '_wcps_synced' AND pm.meta_value = 'yes'"
        );

        if (empty($product_ids)) {
            wp_send_json_success([
                'message' => __('No trashed synced products found.', 'wc-product-sync'),
                'count' => 0,
            ]);
        }

        $deleted = 0;
        $errors = 0;

        foreach ($product_ids as $product_id) {
            $result = wp_delete_post($product_id, true);
            if ($result) {
                $deleted++;
            } else {
                $errors++;
            }
        }

        // Log the action
        $logger = new WCPS_Logger();
        $logger->info('Permanently deleted trashed products', [
            'deleted' => $deleted,
            'errors' => $errors,
        ]);

        wp_send_json_success([
            'message' => sprintf(
                __('Permanently deleted %d products. Errors: %d', 'wc-product-sync'),
                $deleted,
                $errors
            ),
            'count' => $deleted,
            'errors' => $errors,
        ]);
    }

}

/**
 * Initialize plugin
 */
function wcps_init() {
    return WC_Product_Sync::get_instance();
}

// Start the plugin
add_action('plugins_loaded', 'wcps_init', 0);
