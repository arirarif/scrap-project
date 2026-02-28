<?php
/**
 * State Manager Class
 *
 * Handles JSON state persistence for detailed change detection between syncs
 * Uses NDJSON (Newline Delimited JSON) format for streaming large files
 *
 * @package WC_Product_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCPS_State_Manager {

    /**
     * Cache directory path
     */
    private $cache_dir;

    /**
     * Current state file path (NDJSON format)
     */
    private $current_file;

    /**
     * Previous state file path (NDJSON format)
     */
    private $previous_file;

    /**
     * Index file for fast product lookup
     */
    private $index_file;

    /**
     * Previous index file
     */
    private $previous_index_file;

    /**
     * Logger instance
     */
    private $logger;

    /**
     * Maximum file age in days before cleanup
     */
    private $max_file_age_days = 7;

    /**
     * Cached previous index (loaded once per sync)
     */
    private $previous_index_cache = null;

    /**
     * Previous file handle for streaming reads
     */
    private $previous_file_handle = null;

    /**
     * Fields to compare for change detection
     */
    private $comparable_fields = [
        'Name',
        'Price',
        'Stock',
        'Category',
        'Producer',
        'ProducerCode',
        'Photos',
        'Features',
        'AlternativeProducts',
    ];

    /**
     * Constructor
     */
    public function __construct() {
        $this->cache_dir = WCPS_PLUGIN_DIR . 'cache/';
        $this->current_file = $this->cache_dir . 'last-sync.ndjson';
        $this->previous_file = $this->cache_dir . 'previous-sync.ndjson';
        $this->index_file = $this->cache_dir . 'last-sync.index';
        $this->previous_index_file = $this->cache_dir . 'previous-sync.index';
        $this->logger = new WCPS_Logger();
        $this->ensure_cache_dir();
    }

    /**
     * Destructor - close any open file handles
     */
    public function __destruct() {
        $this->close_previous_file();
    }

    /**
     * Close previous file handle if open
     */
    private function close_previous_file() {
        if ($this->previous_file_handle !== null) {
            @fclose($this->previous_file_handle);
            $this->previous_file_handle = null;
        }
    }

    /**
     * Ensure cache directory exists with security files
     */
    public function ensure_cache_dir() {
        if (!file_exists($this->cache_dir)) {
            wp_mkdir_p($this->cache_dir);
            file_put_contents($this->cache_dir . '.htaccess', 'deny from all');
            file_put_contents($this->cache_dir . 'index.php', '<?php // Silence is golden');
        }
    }

    /**
     * Rotate state files: move current to previous
     *
     * @return bool Success status
     */
    public function rotate_state() {
        // Close any open handles first
        $this->close_previous_file();
        $this->previous_index_cache = null;

        if (!file_exists($this->current_file)) {
            // No current file to rotate, this is fine for first sync
            return true;
        }

        // Delete existing previous files
        foreach ([$this->previous_file, $this->previous_index_file] as $file) {
            if (file_exists($file)) {
                if (!@unlink($file)) {
                    $this->logger->warning('Failed to delete previous file', ['file' => $file]);
                }
            }
        }

        // Move current data file to previous
        if (!@rename($this->current_file, $this->previous_file)) {
            $this->logger->warning('Failed to rotate state file, will copy instead');
            if (@copy($this->current_file, $this->previous_file)) {
                @unlink($this->current_file);
            } else {
                $this->logger->error('Failed to rotate state file');
                return false;
            }
        }

        // Move current index file to previous
        if (file_exists($this->index_file)) {
            if (!@rename($this->index_file, $this->previous_index_file)) {
                @copy($this->index_file, $this->previous_index_file);
                @unlink($this->index_file);
            }
        }

        return true;
    }

    /**
     * Save current JSON state in NDJSON format (one product per line)
     * Also creates an index file for fast lookups
     *
     * @param array $json_data Complete JSON data indexed by external ID
     * @return bool Success status
     */
    public function save_state(array $json_data) {
        $data_handle = @fopen($this->current_file, 'w');
        if ($data_handle === false) {
            $this->logger->error('Failed to open state file for writing');
            return false;
        }

        $index = [];
        $position = 0;
        $product_count = 0;

        foreach ($json_data as $external_id => $product_data) {
            // Create line: {"id":"123","data":{...}}
            $line_data = [
                'id' => (string) $external_id,
                'data' => $product_data,
            ];
            $line = wp_json_encode($line_data, JSON_UNESCAPED_UNICODE);

            if ($line === false) {
                $this->logger->warning('Failed to encode product', ['external_id' => $external_id]);
                continue;
            }

            // Store position in index
            $index[$external_id] = $position;

            // Write line with newline
            $bytes_written = fwrite($data_handle, $line . "\n");
            if ($bytes_written === false) {
                $this->logger->error('Failed to write product to state file');
                fclose($data_handle);
                return false;
            }

            $position += $bytes_written;
            $product_count++;
        }

        fclose($data_handle);

        // Save index file
        $index_content = wp_json_encode($index, JSON_UNESCAPED_UNICODE);
        if (@file_put_contents($this->index_file, $index_content, LOCK_EX) === false) {
            $this->logger->warning('Failed to write index file - lookups will be slower');
        }

        $file_size = file_exists($this->current_file) ? filesize($this->current_file) : 0;

        $this->logger->info('State saved successfully (NDJSON streaming format)', [
            'products' => $product_count,
            'size_bytes' => $file_size,
            'index_entries' => count($index),
        ]);

        return true;
    }

    /**
     * Load previous state index (lightweight - just the lookup table)
     * Returns array of external_id => file_position
     *
     * @return array|null Index array or null if not available
     */
    public function load_previous_state() {
        // Return cached index if already loaded
        if ($this->previous_index_cache !== null) {
            return $this->previous_index_cache;
        }

        if (!file_exists($this->previous_file)) {
            // First sync - no previous state is normal
            return null;
        }

        // Try to load index file for fast lookups
        if (file_exists($this->previous_index_file)) {
            $index_content = @file_get_contents($this->previous_index_file);
            if ($index_content !== false) {
                $index = json_decode($index_content, true);
                if (is_array($index)) {
                    $this->previous_index_cache = $index;
                    $this->logger->info('Loaded previous state index', [
                        'products' => count($index),
                    ]);
                    return $index;
                }
            }
        }

        // Fallback: build index by scanning the NDJSON file
        $this->logger->info('Building index from NDJSON file (no index file found)');
        $index = $this->build_index_from_ndjson($this->previous_file);

        if ($index !== null) {
            $this->previous_index_cache = $index;
        }

        return $index;
    }

    /**
     * Build index by scanning NDJSON file
     *
     * @param string $file_path Path to NDJSON file
     * @return array|null Index array or null on failure
     */
    private function build_index_from_ndjson($file_path) {
        $handle = @fopen($file_path, 'r');
        if ($handle === false) {
            return null;
        }

        $index = [];
        $position = 0;

        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if (empty($line)) {
                $position = ftell($handle);
                continue;
            }

            // Quick parse to get just the ID
            $data = json_decode($line, true);
            if (is_array($data) && isset($data['id'])) {
                $index[$data['id']] = $position;
            }

            $position = ftell($handle);
        }

        fclose($handle);
        return $index;
    }

    /**
     * Get a single product from previous state by external ID
     * Uses streaming - only reads the specific line, not the entire file
     *
     * @param string $external_id Product external ID
     * @return array|null Product data or null if not found
     */
    public function get_previous_product($external_id) {
        $index = $this->load_previous_state();

        if ($index === null || !isset($index[$external_id])) {
            return null;
        }

        $position = $index[$external_id];

        // Open file handle if not already open
        if ($this->previous_file_handle === null) {
            $this->previous_file_handle = @fopen($this->previous_file, 'r');
            if ($this->previous_file_handle === false) {
                $this->previous_file_handle = null;
                return null;
            }
        }

        // Seek to position
        if (fseek($this->previous_file_handle, $position) !== 0) {
            return null;
        }

        // Read the line
        $line = fgets($this->previous_file_handle);
        if ($line === false) {
            return null;
        }

        $line = trim($line);
        $data = json_decode($line, true);

        if (!is_array($data) || !isset($data['data'])) {
            return null;
        }

        return $data['data'];
    }

    /**
     * Check if a product exists in previous state
     *
     * @param string $external_id Product external ID
     * @return bool True if exists
     */
    public function has_previous_product($external_id) {
        $index = $this->load_previous_state();
        return $index !== null && isset($index[$external_id]);
    }

    /**
     * Compare two product arrays and return field-level changes
     *
     * @param array $old Previous product data
     * @param array $new Current product data
     * @return array Changed fields with old and new values
     */
    public function compare_products(array $old, array $new) {
        $changes = [];

        foreach ($this->comparable_fields as $field) {
            $old_value = $old[$field] ?? null;
            $new_value = $new[$field] ?? null;

            if ($this->values_differ($old_value, $new_value)) {
                $changes[$field] = [
                    'old' => $this->summarize_value($old_value),
                    'new' => $this->summarize_value($new_value),
                ];
            }
        }

        return $changes;
    }

    /**
     * Check if two values differ
     *
     * @param mixed $old Old value
     * @param mixed $new New value
     * @return bool True if values are different
     */
    private function values_differ($old, $new) {
        // Handle null cases
        if ($old === null && $new === null) {
            return false;
        }
        if ($old === null || $new === null) {
            return true;
        }

        // Handle arrays - compare JSON representations
        if (is_array($old) || is_array($new)) {
            return wp_json_encode($old) !== wp_json_encode($new);
        }

        // Handle numeric comparison with float tolerance
        if (is_numeric($old) && is_numeric($new)) {
            return abs((float) $old - (float) $new) > 0.001;
        }

        // Direct comparison for other types
        return $old !== $new;
    }

    /**
     * Summarize a value for logging (truncate large arrays/strings)
     *
     * @param mixed $value Value to summarize
     * @return mixed Summarized value
     */
    private function summarize_value($value) {
        if (is_array($value)) {
            $count = count($value);
            if ($count > 3) {
                return sprintf('[Array with %d items]', $count);
            }
            return $value;
        }

        if (is_string($value) && strlen($value) > 100) {
            return substr($value, 0, 100) . '...';
        }

        return $value;
    }

    /**
     * Get detailed changes for a specific product
     *
     * @param string $external_id Product external ID
     * @param array $new_data New product data
     * @return array Status and changes
     */
    public function get_product_changes($external_id, array $new_data) {
        $previous = $this->load_previous_state();

        if ($previous === null) {
            return [
                'status' => 'first_sync',
                'changes' => [],
            ];
        }

        if (!isset($previous[$external_id])) {
            return [
                'status' => 'new',
                'changes' => [],
            ];
        }

        $changes = $this->compare_products($previous[$external_id], $new_data);

        if (empty($changes)) {
            return [
                'status' => 'unchanged',
                'changes' => [],
            ];
        }

        return [
            'status' => 'changed',
            'changes' => $changes,
        ];
    }

    /**
     * Get products that were removed (in previous but not in new)
     *
     * @param array $new_data New JSON data
     * @return array External IDs of removed products
     */
    public function get_removed_products(array $new_data) {
        $previous = $this->load_previous_state();

        if ($previous === null) {
            return [];
        }

        $removed = [];
        foreach (array_keys($previous) as $external_id) {
            if (!isset($new_data[$external_id])) {
                $removed[] = $external_id;
            }
        }

        return $removed;
    }

    /**
     * Get cache directory info
     *
     * @return array Cache statistics
     */
    public function get_cache_info() {
        $index = $this->load_previous_state();

        return [
            'cache_dir' => $this->cache_dir,
            'format' => 'NDJSON (streaming)',
            'current_exists' => file_exists($this->current_file),
            'previous_exists' => file_exists($this->previous_file),
            'current_size' => file_exists($this->current_file)
                ? filesize($this->current_file) : 0,
            'previous_size' => file_exists($this->previous_file)
                ? filesize($this->previous_file) : 0,
            'index_size' => file_exists($this->previous_index_file)
                ? filesize($this->previous_index_file) : 0,
            'previous_products' => $index !== null ? count($index) : 0,
            'current_modified' => file_exists($this->current_file)
                ? date('Y-m-d H:i:s', filemtime($this->current_file)) : null,
            'previous_modified' => file_exists($this->previous_file)
                ? date('Y-m-d H:i:s', filemtime($this->previous_file)) : null,
        ];
    }

    /**
     * Get total cache size in bytes
     *
     * @return int Total size in bytes
     */
    public function get_total_cache_size() {
        $size = 0;
        $files = [
            $this->current_file,
            $this->previous_file,
            $this->index_file,
            $this->previous_index_file,
        ];

        foreach ($files as $file) {
            if (file_exists($file)) {
                $size += filesize($file);
            }
        }

        return $size;
    }

    /**
     * Clean up old cache files
     */
    public function cleanup() {
        $threshold = time() - ($this->max_file_age_days * DAY_IN_SECONDS);

        // Only clean previous files if they're old
        $files_to_check = [$this->previous_file, $this->previous_index_file];

        foreach ($files_to_check as $file) {
            if (file_exists($file) && filemtime($file) < $threshold) {
                @unlink($file);
                $this->logger->info('Cleaned up old cache file', ['file' => basename($file)]);
            }
        }
    }

    /**
     * Clear all cache files
     *
     * @return bool Success status
     */
    public function clear_cache() {
        $this->close_previous_file();
        $this->previous_index_cache = null;

        $success = true;
        $files = [
            $this->current_file,
            $this->previous_file,
            $this->index_file,
            $this->previous_index_file,
        ];

        foreach ($files as $file) {
            if (file_exists($file)) {
                if (!@unlink($file)) {
                    $success = false;
                }
            }
        }

        if ($success) {
            $this->logger->info('Cache cleared successfully');
        } else {
            $this->logger->error('Failed to clear some cache files');
        }

        return $success;
    }

    /**
     * Get list of all product IDs in previous state
     *
     * @return array Array of external IDs
     */
    public function get_previous_product_ids() {
        $index = $this->load_previous_state();
        return $index !== null ? array_keys($index) : [];
    }
}
