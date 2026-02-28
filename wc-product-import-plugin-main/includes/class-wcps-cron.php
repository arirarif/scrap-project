<?php
/**
 * Cron Handler Class
 *
 * Handles WP-Cron scheduling for automatic sync
 *
 * @package WC_Product_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCPS_Cron {

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

        // Add custom cron schedules
        add_filter('cron_schedules', [$this, 'add_cron_schedules']);

        // Hook sync action
        add_action('wcps_sync_cron', [$this, 'run_scheduled_sync']);

        // Schedule on settings save if enabled
        add_action('update_option_wcps_enabled', [$this, 'handle_enabled_change'], 10, 2);
    }

    /**
     * Add custom cron schedules
     *
     * @param array $schedules Existing schedules
     * @return array Modified schedules
     */
    public function add_cron_schedules($schedules) {
        // Add custom interval based on settings
        $interval_seconds = $this->get_interval_seconds();
        $interval_display = $this->get_interval_display();

        $schedules['wcps_custom_interval'] = [
            'interval' => $interval_seconds,
            'display' => $interval_display,
        ];

        return $schedules;
    }

    /**
     * Get interval in seconds from settings
     *
     * @return int Interval in seconds
     */
    public function get_interval_seconds() {
        $value = (int) get_option('wcps_sync_interval_value', 1);
        $unit = get_option('wcps_sync_interval_unit', 'hours');

        // Ensure minimum values
        if ($value < 1) {
            $value = 1;
        }

        if ($unit === 'minutes') {
            // Minimum 5 minutes to prevent server overload
            if ($value < 5) {
                $value = 5;
            }
            return $value * MINUTE_IN_SECONDS;
        }

        // Hours
        return $value * HOUR_IN_SECONDS;
    }

    /**
     * Get human-readable interval display
     *
     * @return string
     */
    public function get_interval_display() {
        $value = (int) get_option('wcps_sync_interval_value', 1);
        $unit = get_option('wcps_sync_interval_unit', 'hours');

        if ($unit === 'minutes') {
            return sprintf(_n('Every %d Minute', 'Every %d Minutes', $value, 'wc-product-sync'), $value);
        }

        return sprintf(_n('Every %d Hour', 'Every %d Hours', $value, 'wc-product-sync'), $value);
    }

    /**
     * Run scheduled sync
     */
    public function run_scheduled_sync() {
        // Check if sync is enabled
        if (get_option('wcps_enabled', 'no') !== 'yes') {
            $this->logger->info('Scheduled sync skipped - sync is disabled');
            return;
        }

        // Check if sync is already running
        if (WCPS_Sync_Handler::is_running()) {
            $this->logger->warning('Scheduled sync skipped - sync already running');
            return;
        }

        $this->logger->info('Starting scheduled sync');

        // Set flag that sync is running
        update_option('wcps_sync_running', true);

        try {
            $sync_handler = new WCPS_Sync_Handler();
            $result = $sync_handler->run_sync();

            if (is_wp_error($result)) {
                $this->logger->error('Scheduled sync failed', [
                    'error' => $result->get_error_message(),
                ]);
            } else {
                $this->logger->success('Scheduled sync completed', $result);
            }
        } catch (Exception $e) {
            $this->logger->error('Scheduled sync exception', [
                'error' => $e->getMessage(),
            ]);
        }

        // Clear running flag
        delete_option('wcps_sync_running');
    }

    /**
     * Handle enabled option change
     *
     * @param mixed $old_value Old value
     * @param mixed $new_value New value
     */
    public function handle_enabled_change($old_value, $new_value) {
        if ($new_value === 'yes' && $old_value !== 'yes') {
            // Sync was just enabled - schedule cron
            $this->schedule_sync();
            $this->logger->info('Sync enabled - scheduled cron job');
        } elseif ($new_value !== 'yes' && $old_value === 'yes') {
            // Sync was just disabled - unschedule cron
            $this->unschedule_sync();
            $this->logger->info('Sync disabled - unscheduled cron job');
        }
    }

    /**
     * Schedule sync cron job
     */
    public function schedule_sync() {
        // Clear any existing schedule
        $this->unschedule_sync();

        // Only schedule if enabled
        if (get_option('wcps_enabled', 'no') !== 'yes') {
            return;
        }

        // Schedule new event with custom interval
        wp_schedule_event(time(), 'wcps_custom_interval', 'wcps_sync_cron');

        $this->logger->info('Scheduled sync', [
            'interval' => $this->get_interval_display(),
            'seconds' => $this->get_interval_seconds(),
        ]);
    }

    /**
     * Unschedule sync cron job
     */
    public function unschedule_sync() {
        $timestamp = wp_next_scheduled('wcps_sync_cron');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'wcps_sync_cron');
        }
        wp_clear_scheduled_hook('wcps_sync_cron');
    }

    /**
     * Get next scheduled run time
     *
     * @return int|false Timestamp or false if not scheduled
     */
    public function get_next_scheduled() {
        return wp_next_scheduled('wcps_sync_cron');
    }

    /**
     * Check if cron is scheduled
     *
     * @return bool
     */
    public function is_scheduled() {
        return (bool) $this->get_next_scheduled();
    }
}
