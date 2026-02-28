<?php
/**
 * JSON Fetcher Class
 *
 * Handles fetching and validating JSON from remote URL
 *
 * @package WC_Product_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCPS_JSON_Fetcher {

    /**
     * Logger instance
     */
    private $logger;

    /**
     * Constructor
     */
    public function __construct() {
        $this->logger = new WCPS_Logger();
    }

    /**
     * Fetch JSON from URL
     *
     * @param string $url The URL to fetch
     * @return array|WP_Error Decoded JSON array or WP_Error
     */
    public function fetch($url = '') {
        if (empty($url)) {
            $url = get_option('wcps_json_url', '');
        }

        if (empty($url)) {
            $this->logger->error('No JSON URL configured');
            return new WP_Error('no_url', __('No JSON URL configured.', 'wc-product-sync'));
        }

        $this->logger->info('Fetching JSON from URL', ['url' => $url]);

        // Fetch with extended timeout for large files
        $response = wp_remote_get($url, [
            'timeout' => 120,
            'sslverify' => true,
            'headers' => [
                'Accept' => 'application/json',
            ],
        ]);

        if (is_wp_error($response)) {
            $this->logger->error('Failed to fetch JSON', [
                'error' => $response->get_error_message(),
            ]);
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);

        if ($status_code !== 200) {
            $this->logger->error('Invalid HTTP response', ['status' => $status_code]);
            return new WP_Error(
                'http_error',
                sprintf(__('HTTP Error: %d', 'wc-product-sync'), $status_code)
            );
        }

        $body = wp_remote_retrieve_body($response);

        if (empty($body)) {
            $this->logger->error('Empty response body');
            return new WP_Error('empty_body', __('Empty response from server.', 'wc-product-sync'));
        }

        // Decode JSON
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->error('JSON decode error', [
                'error' => json_last_error_msg(),
            ]);
            return new WP_Error(
                'json_error',
                sprintf(__('JSON Error: %s', 'wc-product-sync'), json_last_error_msg())
            );
        }

        // Validate structure
        $validation = $this->validate_structure($data);
        if (is_wp_error($validation)) {
            return $validation;
        }

        $this->logger->success('JSON fetched successfully', [
            'products_count' => count($data),
        ]);

        return $data;
    }

    /**
     * Validate JSON structure
     *
     * @param array $data The decoded JSON data
     * @return true|WP_Error
     */
    private function validate_structure($data) {
        if (!is_array($data)) {
            $this->logger->error('Invalid JSON structure: not an array');
            return new WP_Error(
                'invalid_structure',
                __('JSON must be an object/array.', 'wc-product-sync')
            );
        }

        if (empty($data)) {
            $this->logger->warning('JSON is empty');
            return true; // Empty is valid, might want to delete all products
        }

        // Check first product for required fields
        $first_product = reset($data);
        $required_fields = ['Id', 'Name', 'Price'];

        foreach ($required_fields as $field) {
            if (!isset($first_product[$field])) {
                $this->logger->error('Missing required field in JSON', ['field' => $field]);
                return new WP_Error(
                    'missing_field',
                    sprintf(__('Missing required field: %s', 'wc-product-sync'), $field)
                );
            }
        }

        return true;
    }

    /**
     * Get JSON hash for comparison
     *
     * @param array $data The JSON data
     * @return string MD5 hash
     */
    public function get_hash($data) {
        return md5(wp_json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Get product hash for individual comparison
     *
     * @param array $product Single product data
     * @return string MD5 hash
     */
    public function get_product_hash($product) {
        // Hash only fields that matter for updates
        $hashable = [
            'Name' => $product['Name'] ?? '',
            'Price' => $product['Price'] ?? 0,
            'Stock' => $product['Stock'] ?? 0,
            'Category' => $product['Category'] ?? '',
            'Producer' => $product['Producer'] ?? '',
            'ProducerCode' => $product['ProducerCode'] ?? '',
            'Photos' => $product['Photos'] ?? [],
            'Features' => $product['Features'] ?? [],
            'AlternativeProducts' => $product['AlternativeProducts'] ?? [],
            'AlternativeProductIds' => $product['AlternativeProductIds'] ?? [],
        ];

        return md5(wp_json_encode($hashable, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Check if JSON has changed since last sync
     *
     * @param array $data The current JSON data
     * @return bool True if changed
     */
    public function has_changed($data) {
        $current_hash = $this->get_hash($data);
        $previous_hash = get_option('wcps_last_json_hash', '');

        return $current_hash !== $previous_hash;
    }

    /**
     * Save current hash
     *
     * @param array $data The JSON data
     */
    public function save_hash($data) {
        $hash = $this->get_hash($data);
        update_option('wcps_last_json_hash', $hash);
    }

    /**
     * Get stored product hashes
     *
     * @return array Product ID => hash mapping
     */
    public function get_stored_product_hashes() {
        return get_option('wcps_product_hashes', []);
    }

    /**
     * Save product hashes
     *
     * @param array $hashes Product ID => hash mapping
     */
    public function save_product_hashes($hashes) {
        update_option('wcps_product_hashes', $hashes);
    }
}
