<?php
/**
 * Logger Class
 *
 * Handles logging for sync operations
 *
 * @package WC_Product_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCPS_Logger {

    /**
     * Log directory
     */
    private $log_dir;

    /**
     * Current log file
     */
    private $log_file;

    /**
     * Constructor
     */
    public function __construct() {
        $this->log_dir = WCPS_PLUGIN_DIR . 'logs/';
        $this->log_file = $this->log_dir . 'sync-' . date('Y-m-d') . '.log';
        $this->ensure_log_dir();
    }

    /**
     * Ensure log directory exists
     */
    private function ensure_log_dir() {
        if (!file_exists($this->log_dir)) {
            wp_mkdir_p($this->log_dir);
            file_put_contents($this->log_dir . '.htaccess', 'deny from all');
            file_put_contents($this->log_dir . 'index.php', '<?php // Silence is golden');
        }
    }

    /**
     * Log a message
     *
     * @param string $message Log message
     * @param string $level Log level (info, warning, error, success)
     * @param array $context Additional context
     */
    public function log($message, $level = 'info', $context = []) {
        $timestamp = date('Y-m-d H:i:s');
        $level = strtoupper($level);

        $log_entry = "[{$timestamp}] [{$level}] {$message}";

        if (!empty($context)) {
            $log_entry .= ' | Context: ' . wp_json_encode($context, JSON_UNESCAPED_UNICODE);
        }

        $log_entry .= PHP_EOL;

        file_put_contents($this->log_file, $log_entry, FILE_APPEND | LOCK_EX);
    }

    /**
     * Log info message
     */
    public function info($message, $context = []) {
        $this->log($message, 'info', $context);
    }

    /**
     * Log warning message
     */
    public function warning($message, $context = []) {
        $this->log($message, 'warning', $context);
    }

    /**
     * Log error message
     */
    public function error($message, $context = []) {
        $this->log($message, 'error', $context);
    }

    /**
     * Log success message
     */
    public function success($message, $context = []) {
        $this->log($message, 'success', $context);
    }

    /**
     * Get log files
     *
     * @param int $limit Number of files to return
     * @return array
     */
    public function get_log_files($limit = 10) {
        $files = glob($this->log_dir . 'sync-*.log');

        if (empty($files)) {
            return [];
        }

        // Sort by modification time, newest first
        usort($files, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });

        return array_slice($files, 0, $limit);
    }

    /**
     * Get log content
     *
     * @param string $filename Log filename
     * @param int $lines Number of lines to return
     * @return string
     */
    public function get_log_content($filename, $lines = 100) {
        $filepath = $this->log_dir . basename($filename);

        if (!file_exists($filepath)) {
            return '';
        }

        $content = file_get_contents($filepath);
        $all_lines = explode(PHP_EOL, $content);
        $last_lines = array_slice($all_lines, -$lines);

        return implode(PHP_EOL, $last_lines);
    }

    /**
     * Clear old logs (older than X days)
     *
     * @param int $days Days to keep
     */
    public function clear_old_logs($days = 30) {
        $files = glob($this->log_dir . 'sync-*.log');
        $threshold = time() - ($days * 24 * 60 * 60);

        foreach ($files as $file) {
            if (filemtime($file) < $threshold) {
                unlink($file);
            }
        }
    }

    /**
     * Start sync session
     */
    public function start_sync() {
        $this->log('========================================', 'info');
        $this->log('SYNC SESSION STARTED', 'info');
        $this->log('========================================', 'info');
    }

    /**
     * End sync session
     *
     * @param array $stats Sync statistics
     */
    public function end_sync($stats = []) {
        $this->log('========================================', 'info');
        $this->log('SYNC SESSION COMPLETED', 'success', $stats);
        $this->log('========================================', 'info');
    }
}
