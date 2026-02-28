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
        require_once WCPS_PLUGIN_DIR . 'includes/class-wcps-state-manager.php';
        require_once WCPS_PLUGIN_DIR . 'includes/class-wcps-sync-handler.php';
        require_once WCPS_PLUGIN_DIR . 'includes/class-wcps-admin-settings.php';
        require_once WCPS_PLUGIN_DIR . 'includes/class-wcps-cron.php';
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
        add_action('wp_ajax_wcps_get_sync_status', [$this, 'ajax_get_sync_status']);
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
     * AJAX: Manual sync trigger
     */
    public function ajax_manual_sync() {
        check_ajax_referer('wcps_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied.', 'wc-product-sync')]);
        }

        // Set flag that sync is running
        update_option('wcps_sync_running', true);
        update_option('wcps_sync_progress', [
            'status' => 'starting',
            'message' => __('Starting sync...', 'wc-product-sync'),
            'progress' => 0,
        ]);

        // Run sync
        $sync_handler = new WCPS_Sync_Handler();
        $result = $sync_handler->run_sync();

        // Clear running flag
        delete_option('wcps_sync_running');

        if (is_wp_error($result)) {
            wp_send_json_error([
                'message' => $result->get_error_message(),
            ]);
        }

        wp_send_json_success([
            'message' => __('Sync completed successfully!', 'wc-product-sync'),
            'stats' => $result,
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
}

/**
 * Initialize plugin
 */
function wcps_init() {
    return WC_Product_Sync::get_instance();
}

// Start the plugin
add_action('plugins_loaded', 'wcps_init', 0);
