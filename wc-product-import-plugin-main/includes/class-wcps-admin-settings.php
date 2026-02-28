<?php
/**
 * Admin Settings Class
 *
 * Handles the admin settings page
 *
 * @package WC_Product_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCPS_Admin_Settings {

    /**
     * Single instance
     */
    private static $instance = null;

    /**
     * Logger instance
     */
    private $logger;

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
        $this->logger = new WCPS_Logger();

        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    /**
     * Add menu item
     */
    public function add_menu() {
        add_submenu_page(
            'woocommerce',
            __('Product Sync', 'wc-product-sync'),
            __('Product Sync', 'wc-product-sync'),
            'manage_woocommerce',
            'wc-product-sync',
            [$this, 'render_page']
        );
    }

    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('wcps_settings', 'wcps_json_url', [
            'sanitize_callback' => 'esc_url_raw',
        ]);

        register_setting('wcps_settings', 'wcps_sync_interval_value', [
            'sanitize_callback' => 'absint',
        ]);

        register_setting('wcps_settings', 'wcps_sync_interval_unit', [
            'sanitize_callback' => 'sanitize_text_field',
        ]);

        register_setting('wcps_settings', 'wcps_enabled', [
            'sanitize_callback' => 'sanitize_text_field',
        ]);

        register_setting('wcps_settings', 'wcps_delete_behavior', [
            'sanitize_callback' => 'sanitize_text_field',
        ]);

        // Reschedule cron when interval changes
        add_action('update_option_wcps_sync_interval_value', [$this, 'reschedule_cron'], 10, 2);
        add_action('update_option_wcps_sync_interval_unit', [$this, 'reschedule_cron'], 10, 2);
    }

    /**
     * Reschedule cron when interval changes
     */
    public function reschedule_cron($old_value, $new_value) {
        if ($old_value !== $new_value) {
            $cron = WCPS_Cron::get_instance();
            $cron->schedule_sync();
        }
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_assets($hook) {
        if ($hook !== 'woocommerce_page_wc-product-sync') {
            return;
        }

        wp_enqueue_style(
            'wcps-admin',
            WCPS_PLUGIN_URL . 'assets/css/admin.css',
            [],
            WCPS_VERSION
        );

        wp_enqueue_script(
            'wcps-admin',
            WCPS_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery'],
            WCPS_VERSION,
            true
        );

        wp_localize_script('wcps-admin', 'wcps', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wcps_admin_nonce'),
            'strings' => [
                'syncing' => __('Syncing...', 'wc-product-sync'),
                'sync_complete' => __('Sync complete!', 'wc-product-sync'),
                'sync_error' => __('Sync failed!', 'wc-product-sync'),
                'confirm_sync' => __('Are you sure you want to run sync now?', 'wc-product-sync'),
            ],
        ]);
    }

    /**
     * Render settings page
     */
    public function render_page() {
        // Get current tab
        $tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'settings';
        ?>
        <div class="wrap wcps-wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <nav class="nav-tab-wrapper">
                <a href="?page=wc-product-sync&tab=settings"
                   class="nav-tab <?php echo $tab === 'settings' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Settings', 'wc-product-sync'); ?>
                </a>
                <a href="?page=wc-product-sync&tab=status"
                   class="nav-tab <?php echo $tab === 'status' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Status', 'wc-product-sync'); ?>
                </a>
                <a href="?page=wc-product-sync&tab=logs"
                   class="nav-tab <?php echo $tab === 'logs' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Logs', 'wc-product-sync'); ?>
                </a>
            </nav>

            <div class="wcps-content">
                <?php
                switch ($tab) {
                    case 'status':
                        $this->render_status_tab();
                        break;
                    case 'logs':
                        $this->render_logs_tab();
                        break;
                    default:
                        $this->render_settings_tab();
                        break;
                }
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render settings tab
     */
    private function render_settings_tab() {
        ?>
        <form method="post" action="options.php">
            <?php settings_fields('wcps_settings'); ?>

            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="wcps_enabled"><?php esc_html_e('Enable Sync', 'wc-product-sync'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="wcps_enabled" id="wcps_enabled" value="yes"
                                <?php checked(get_option('wcps_enabled', 'no'), 'yes'); ?>>
                            <?php esc_html_e('Enable automatic product synchronization', 'wc-product-sync'); ?>
                        </label>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="wcps_json_url"><?php esc_html_e('JSON URL', 'wc-product-sync'); ?></label>
                    </th>
                    <td>
                        <input type="url" name="wcps_json_url" id="wcps_json_url" class="regular-text"
                            value="<?php echo esc_attr(get_option('wcps_json_url', '')); ?>"
                            placeholder="https://example.com/products.json">
                        <p class="description">
                            <?php esc_html_e('Enter the URL to your product JSON file.', 'wc-product-sync'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="wcps_sync_interval_value"><?php esc_html_e('Sync Interval', 'wc-product-sync'); ?></label>
                    </th>
                    <td>
                        <input type="number"
                            name="wcps_sync_interval_value"
                            id="wcps_sync_interval_value"
                            class="small-text"
                            min="1"
                            max="999"
                            value="<?php echo esc_attr(get_option('wcps_sync_interval_value', 1)); ?>">
                        <select name="wcps_sync_interval_unit" id="wcps_sync_interval_unit">
                            <?php
                            $units = [
                                'minutes' => __('Minutes', 'wc-product-sync'),
                                'hours' => __('Hours', 'wc-product-sync'),
                            ];
                            $current_unit = get_option('wcps_sync_interval_unit', 'hours');
                            foreach ($units as $value => $label) {
                                printf(
                                    '<option value="%s" %s>%s</option>',
                                    esc_attr($value),
                                    selected($current_unit, $value, false),
                                    esc_html($label)
                                );
                            }
                            ?>
                        </select>
                        <p class="description">
                            <?php esc_html_e('How often to sync products from the JSON file.', 'wc-product-sync'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="wcps_delete_behavior"><?php esc_html_e('Delete Behavior', 'wc-product-sync'); ?></label>
                    </th>
                    <td>
                        <select name="wcps_delete_behavior" id="wcps_delete_behavior">
                            <?php
                            $behaviors = [
                                'trash' => __('Move to Trash', 'wc-product-sync'),
                                'delete' => __('Delete Permanently', 'wc-product-sync'),
                            ];
                            $current = get_option('wcps_delete_behavior', 'trash');
                            foreach ($behaviors as $value => $label) {
                                printf(
                                    '<option value="%s" %s>%s</option>',
                                    esc_attr($value),
                                    selected($current, $value, false),
                                    esc_html($label)
                                );
                            }
                            ?>
                        </select>
                        <p class="description">
                            <?php esc_html_e('What to do when a product is removed from the JSON file.', 'wc-product-sync'); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <?php submit_button(); ?>
        </form>
        <?php
    }

    /**
     * Render status tab
     */
    private function render_status_tab() {
        $last_sync = get_option('wcps_last_sync', '');
        $last_status = get_option('wcps_last_sync_status', []);
        $last_stats = get_option('wcps_last_sync_stats', []);
        $next_sync = wp_next_scheduled('wcps_sync_cron');
        $is_enabled = get_option('wcps_enabled', 'no') === 'yes';
        $cron = WCPS_Cron::get_instance();
        $interval_display = $cron->get_interval_display();
        ?>
        <div class="wcps-status-box">
            <h2><?php esc_html_e('Sync Status', 'wc-product-sync'); ?></h2>

            <table class="widefat">
                <tr>
                    <th><?php esc_html_e('Status', 'wc-product-sync'); ?></th>
                    <td>
                        <?php if ($is_enabled): ?>
                            <span class="wcps-status wcps-status-enabled">
                                <?php esc_html_e('Enabled', 'wc-product-sync'); ?>
                            </span>
                        <?php else: ?>
                            <span class="wcps-status wcps-status-disabled">
                                <?php esc_html_e('Disabled', 'wc-product-sync'); ?>
                            </span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Last Sync', 'wc-product-sync'); ?></th>
                    <td>
                        <?php
                        if ($last_sync) {
                            echo esc_html($last_sync);
                            if (!empty($last_status['status'])) {
                                $status_class = $last_status['status'] === 'success' ? 'success' : 'error';
                                printf(
                                    ' <span class="wcps-status wcps-status-%s">%s</span>',
                                    esc_attr($status_class),
                                    esc_html($last_status['message'] ?? '')
                                );
                            }
                        } else {
                            esc_html_e('Never', 'wc-product-sync');
                        }
                        ?>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Sync Interval', 'wc-product-sync'); ?></th>
                    <td><?php echo esc_html($interval_display); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Next Scheduled Sync', 'wc-product-sync'); ?></th>
                    <td>
                        <?php
                        if ($next_sync && $is_enabled) {
                            echo esc_html(date_i18n('Y-m-d H:i:s', $next_sync));
                        } else {
                            esc_html_e('Not scheduled', 'wc-product-sync');
                        }
                        ?>
                    </td>
                </tr>
                <?php if (!empty($last_stats)): ?>
                <tr>
                    <th><?php esc_html_e('Last Sync Statistics', 'wc-product-sync'); ?></th>
                    <td>
                        <ul class="wcps-stats">
                            <li><?php printf(__('Total Products: %d', 'wc-product-sync'), $last_stats['total'] ?? 0); ?></li>
                            <li><?php printf(__('Created: %d', 'wc-product-sync'), $last_stats['created'] ?? 0); ?></li>
                            <li><?php printf(__('Updated: %d', 'wc-product-sync'), $last_stats['updated'] ?? 0); ?></li>
                            <li><?php printf(__('Deleted: %d', 'wc-product-sync'), $last_stats['deleted'] ?? 0); ?></li>
                            <li><?php printf(__('Skipped: %d', 'wc-product-sync'), $last_stats['skipped'] ?? 0); ?></li>
                            <li><?php printf(__('Errors: %d', 'wc-product-sync'), $last_stats['errors'] ?? 0); ?></li>
                            <?php if (isset($last_stats['duration'])): ?>
                            <li><?php printf(__('Duration: %s seconds', 'wc-product-sync'), $last_stats['duration']); ?></li>
                            <?php endif; ?>
                        </ul>
                    </td>
                </tr>
                <?php endif; ?>
            </table>

            <div class="wcps-actions">
                <button type="button" id="wcps-manual-sync" class="button button-primary">
                    <?php esc_html_e('Run Sync Now', 'wc-product-sync'); ?>
                </button>
                <span id="wcps-sync-status"></span>
            </div>

            <div id="wcps-progress" style="display: none;">
                <div class="wcps-progress-bar">
                    <div class="wcps-progress-fill" style="width: 0%"></div>
                </div>
                <p class="wcps-progress-message"></p>
            </div>
        </div>
        <?php
    }

    /**
     * Render logs tab
     */
    private function render_logs_tab() {
        $log_files = $this->logger->get_log_files();
        $current_log = isset($_GET['log']) ? sanitize_file_name($_GET['log']) : '';

        if (empty($current_log) && !empty($log_files)) {
            $current_log = basename($log_files[0]);
        }
        ?>
        <div class="wcps-logs-box">
            <h2><?php esc_html_e('Sync Logs', 'wc-product-sync'); ?></h2>

            <?php if (empty($log_files)): ?>
                <p><?php esc_html_e('No log files found.', 'wc-product-sync'); ?></p>
            <?php else: ?>
                <div class="wcps-log-selector">
                    <label for="wcps-log-select"><?php esc_html_e('Select Log File:', 'wc-product-sync'); ?></label>
                    <select id="wcps-log-select" onchange="location.href='?page=wc-product-sync&tab=logs&log=' + this.value">
                        <?php foreach ($log_files as $file): ?>
                            <?php $filename = basename($file); ?>
                            <option value="<?php echo esc_attr($filename); ?>"
                                <?php selected($current_log, $filename); ?>>
                                <?php echo esc_html($filename); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="wcps-log-content">
                    <pre><?php echo esc_html($this->logger->get_log_content($current_log, 200)); ?></pre>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
