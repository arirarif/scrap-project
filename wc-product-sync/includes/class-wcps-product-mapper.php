<?php
/**
 * Product Mapper Class
 *
 * Maps JSON data to WooCommerce product fields
 *
 * @package WC_Product_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCPS_Product_Mapper {

    /**
     * Logger instance
     */
    private $logger;

    /**
     * Attribute cache
     */
    private $attribute_cache = [];

    /**
     * Category cache
     */
    private $category_cache = [];

    /**
     * Constructor
     */
    public function __construct() {
        $this->logger = new WCPS_Logger();
    }

    /**
     * Create or update a product from JSON data
     *
     * @param array $json_product The product data from JSON
     * @param int|null $existing_product_id Existing WC product ID if updating
     * @return int|WP_Error Product ID or error
     */
    public function map_product($json_product, $existing_product_id = null) {
        try {
            if ($existing_product_id) {
                $product = wc_get_product($existing_product_id);
                if (!$product) {
                    $product = new WC_Product_Simple();
                }
            } else {
                $product = new WC_Product_Simple();
            }

            // Basic fields — prepend Producer to Name
            $product_name = $json_product['Name'];
            if (!empty($json_product['Producer']) && stripos($product_name, $json_product['Producer']) !== 0) {
                $product_name = $json_product['Producer'] . ' ' . $product_name;
            }
            $product->set_name($product_name);
            $product->set_sku($this->generate_sku($json_product['Id']));
            $product->set_regular_price((string) $json_product['Price']);
            $product->set_manage_stock(true);
            $stock = max((int) ($json_product['Stock'] ?? 0), 1);
            $product->set_stock_quantity($stock);
            $product->set_stock_status('instock');

            // Status
            $product->set_status('publish');
            $product->set_catalog_visibility('visible');

            // Category
            if (!empty($json_product['Category'])) {
                $category_ids = $this->get_or_create_categories($json_product['Category']);
                $product->set_category_ids($category_ids);
            }

            // Attributes (Features + Producer)
            $attributes = $this->build_attributes($json_product);
            $product->set_attributes($attributes);

            // Save product first to get ID
            $product_id = $product->save();

            if (!$product_id) {
                throw new Exception(__('Failed to save product.', 'wc-product-sync'));
            }

            // Store external ID as meta
            update_post_meta($product_id, '_external_id', $json_product['Id']);
            update_post_meta($product_id, '_wcps_synced', 'yes');
            update_post_meta($product_id, '_wcps_last_sync', current_time('mysql'));

            // Producer code
            if (!empty($json_product['ProducerCode'])) {
                update_post_meta($product_id, '_producer_code', $json_product['ProducerCode']);
            }

            // Store product hash
            $fetcher = new WCPS_JSON_Fetcher();
            update_post_meta($product_id, '_wcps_hash', $fetcher->get_product_hash($json_product));

            // Store alternative product external IDs for later linking
            if (!empty($json_product['AlternativeProducts'])) {
                $this->store_alternative_products($product_id, $json_product['AlternativeProducts']);
            }

            // Handle photos
            if (!empty($json_product['Photos'])) {
                $this->handle_photos($product_id, $json_product['Photos']);
            }

            return $product_id;

        } catch (Exception $e) {
            $this->logger->error('Error mapping product', [
                'external_id' => $json_product['Id'],
                'error' => $e->getMessage(),
            ]);
            return new WP_Error('mapping_error', $e->getMessage());
        }
    }

    /**
     * Generate SKU from external ID
     *
     * @param string $external_id
     * @return string
     */
    public function generate_sku($external_id) {
        return (string) $external_id;
    }

    /**
     * Get or create categories from hierarchical string
     *
     * @param string $category_string Category string like "Parent - Child - Grandchild"
     * @return array Category IDs
     */
    public function get_or_create_categories($category_string) {
        // Check cache
        if (isset($this->category_cache[$category_string])) {
            return $this->category_cache[$category_string];
        }

        // Split by " - " for hierarchy
        $parts = array_map('trim', explode(' - ', $category_string));
        $parent_id = 0;
        $category_ids = [];

        foreach ($parts as $category_name) {
            if (empty($category_name)) {
                continue;
            }

            // Look for existing category
            $term = get_term_by('name', $category_name, 'product_cat');

            if ($term && $term->parent == $parent_id) {
                $category_ids[] = $term->term_id;
                $parent_id = $term->term_id;
            } else {
                // Create new category
                $result = wp_insert_term($category_name, 'product_cat', [
                    'parent' => $parent_id,
                ]);

                if (is_wp_error($result)) {
                    // If term exists, find it
                    if ($result->get_error_code() === 'term_exists') {
                        $term = get_term_by('name', $category_name, 'product_cat');
                        if ($term) {
                            $category_ids[] = $term->term_id;
                            $parent_id = $term->term_id;
                        }
                    } else {
                        $this->logger->warning('Failed to create category', [
                            'name' => $category_name,
                            'error' => $result->get_error_message(),
                        ]);
                    }
                } else {
                    $category_ids[] = $result['term_id'];
                    $parent_id = $result['term_id'];
                    $this->logger->info('Created category', ['name' => $category_name]);
                }
            }
        }

        // Cache result
        $this->category_cache[$category_string] = $category_ids;

        return $category_ids;
    }

    /**
     * Build product attributes from Features and Producer
     *
     * @param array $json_product
     * @return array WC_Product_Attribute objects
     */
    private function build_attributes($json_product) {
        $attributes = [];

        // Producer attribute
        if (!empty($json_product['Producer'])) {
            $attr = $this->create_attribute('Producer', [$json_product['Producer']]);
            if ($attr) {
                $attributes[] = $attr;
            }
        }

        // Feature attributes
        if (!empty($json_product['Features']) && is_array($json_product['Features'])) {
            foreach ($json_product['Features'] as $feature) {
                if (empty($feature['Name']) || empty($feature['Value'])) {
                    continue;
                }

                // Extract values
                $values = [];
                foreach ($feature['Value'] as $value_item) {
                    if (!empty($value_item['Name'])) {
                        $values[] = $value_item['Name'];
                    }
                }

                if (!empty($values)) {
                    $attr = $this->create_attribute($feature['Name'], $values);
                    if ($attr) {
                        $attributes[] = $attr;
                    }
                }
            }
        }

        return $attributes;
    }

    /**
     * Create a product attribute
     *
     * @param string $name Attribute name
     * @param array $values Attribute values
     * @param bool $for_variation Whether this attribute is used for variations
     * @return WC_Product_Attribute|null
     */
    public function create_attribute($name, $values, $for_variation = false) {
        // Sanitize attribute name for taxonomy
        $taxonomy_name = wc_sanitize_taxonomy_name($name);

        // WooCommerce has 28 character limit for attribute slugs
        if (strlen($taxonomy_name) > 28) {
            $taxonomy_name = $this->truncate_slug($taxonomy_name, 28);
        }

        $taxonomy = 'pa_' . $taxonomy_name;

        // Check if global attribute exists, if not create it
        $attribute_id = $this->get_or_create_global_attribute($name, $taxonomy_name);

        if (!$attribute_id) {
            // Fall back to custom attribute
            $attribute = new WC_Product_Attribute();
            $attribute->set_name($name);
            $attribute->set_options($values);
            $attribute->set_visible(true);
            $attribute->set_variation($for_variation);
            return $attribute;
        }

        // Create terms for the attribute
        $term_ids = [];
        foreach ($values as $value) {
            $term = get_term_by('name', $value, $taxonomy);

            if (!$term) {
                $result = wp_insert_term($value, $taxonomy);
                if (!is_wp_error($result)) {
                    $term_ids[] = $result['term_id'];
                }
            } else {
                $term_ids[] = $term->term_id;
            }
        }

        $attribute = new WC_Product_Attribute();
        $attribute->set_id($attribute_id);
        $attribute->set_name($taxonomy);
        $attribute->set_options($term_ids);
        $attribute->set_visible(true);
        $attribute->set_variation($for_variation);

        return $attribute;
    }

    /**
     * Get or create a global attribute
     *
     * @param string $label Human-readable label
     * @param string $slug Sanitized slug
     * @return int|false Attribute ID or false
     */
    public function get_or_create_global_attribute($label, $slug) {
        // Check cache
        if (isset($this->attribute_cache[$slug])) {
            return $this->attribute_cache[$slug];
        }

        // Check if attribute exists
        $attribute_id = wc_attribute_taxonomy_id_by_name($slug);

        if ($attribute_id) {
            $this->attribute_cache[$slug] = $attribute_id;
            return $attribute_id;
        }

        // Create new attribute
        $args = [
            'name' => $label,
            'slug' => $slug,
            'type' => 'select',
            'order_by' => 'menu_order',
            'has_archives' => false,
        ];

        $attribute_id = wc_create_attribute($args);

        if (is_wp_error($attribute_id)) {
            $this->logger->warning('Failed to create attribute', [
                'label' => $label,
                'error' => $attribute_id->get_error_message(),
            ]);
            return false;
        }

        // Register the taxonomy
        $taxonomy = 'pa_' . $slug;
        register_taxonomy($taxonomy, 'product', [
            'labels' => [
                'name' => $label,
            ],
            'hierarchical' => false,
            'show_ui' => false,
            'query_var' => true,
            'rewrite' => false,
        ]);

        $this->attribute_cache[$slug] = $attribute_id;
        $this->logger->info('Created global attribute', ['label' => $label, 'slug' => $slug]);

        return $attribute_id;
    }

    /**
     * Store alternative products for later linking
     *
     * @param int $product_id WC Product ID
     * @param array $alternative_products AlternativeProducts data from JSON
     */
    private function store_alternative_products($product_id, $alternative_products) {
        $linked_external_ids = [];

        foreach ($alternative_products as $key => $group) {
            if (!empty($group['products']) && is_array($group['products'])) {
                foreach ($group['products'] as $alt_product) {
                    if (!empty($alt_product['id'])) {
                        $linked_external_ids[] = $alt_product['id'];
                    }
                }
            }
        }

        // Remove self from linked products
        $linked_external_ids = array_filter($linked_external_ids, function($id) use ($product_id) {
            $current_external_id = get_post_meta($product_id, '_external_id', true);
            return $id !== $current_external_id;
        });

        if (!empty($linked_external_ids)) {
            update_post_meta($product_id, '_linked_external_ids', array_unique($linked_external_ids));
        }

        // Store the full AlternativeProducts structure for frontend rendering
        update_post_meta($product_id, '_wcps_alternative_products', $alternative_products);
    }

    /**
     * Handle product photos
     *
     * @param int $product_id WC Product ID
     * @param array $photos Array of photo URLs
     */
    private function handle_photos($product_id, $photos) {
        if (empty($photos) || !is_array($photos)) {
            return;
        }

        $attachment_ids = [];

        foreach ($photos as $index => $photo_url) {
            if (empty($photo_url)) {
                continue;
            }

            // Check if we already have this image
            $existing_id = $this->get_attachment_by_url($photo_url, $product_id);

            if ($existing_id) {
                $attachment_ids[] = $existing_id;
                continue;
            }

            // Download and attach image
            $attachment_id = $this->download_image($photo_url, $product_id);

            if ($attachment_id) {
                $attachment_ids[] = $attachment_id;
            }
        }

        if (!empty($attachment_ids)) {
            // First image as featured
            set_post_thumbnail($product_id, $attachment_ids[0]);

            // Rest as gallery
            if (count($attachment_ids) > 1) {
                $gallery_ids = array_slice($attachment_ids, 1);
                update_post_meta($product_id, '_product_image_gallery', implode(',', $gallery_ids));
            }
        }
    }

    /**
     * Get attachment by source URL
     *
     * @param string $url Source URL
     * @param int $product_id Product ID
     * @return int|false Attachment ID or false
     */
    private function get_attachment_by_url($url, $product_id) {
        global $wpdb;

        $attachment_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
            WHERE meta_key = '_wcps_source_url' AND meta_value = %s
            LIMIT 1",
            $url
        ));

        return $attachment_id ? (int) $attachment_id : false;
    }

    /**
     * Download and attach image
     *
     * @param string $url Image URL
     * @param int $product_id Product ID
     * @return int|false Attachment ID or false
     */
    private function download_image($url, $product_id) {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        // Download to temp file
        $tmp = download_url($url, 60);

        if (is_wp_error($tmp)) {
            $this->logger->warning('Failed to download image', [
                'url' => $url,
                'error' => $tmp->get_error_message(),
            ]);
            return false;
        }

        // Detect file type from downloaded content
        $filetype = wp_check_filetype_and_ext($tmp, basename(parse_url($url, PHP_URL_PATH)));

        // If no extension detected from URL, detect from file content
        if (empty($filetype['ext'])) {
            $mime_type = mime_content_type($tmp);
            $extension = $this->mime_to_extension($mime_type);

            if (!$extension) {
                // Try using finfo as fallback
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime_type = finfo_file($finfo, $tmp);
                finfo_close($finfo);
                $extension = $this->mime_to_extension($mime_type);
            }

            if (!$extension) {
                @unlink($tmp);
                $this->logger->warning('Could not determine image type', [
                    'url' => $url,
                    'mime' => $mime_type,
                ]);
                return false;
            }

            $filename = 'product-image-' . $product_id . '-' . uniqid() . '.' . $extension;
        } else {
            $filename = basename(parse_url($url, PHP_URL_PATH));
            // Ensure filename has extension
            if (!pathinfo($filename, PATHINFO_EXTENSION)) {
                $filename .= '.' . $filetype['ext'];
            }
        }

        // Get file info
        $file_array = [
            'name' => $filename,
            'tmp_name' => $tmp,
        ];

        // Sideload
        $attachment_id = media_handle_sideload($file_array, $product_id);

        if (is_wp_error($attachment_id)) {
            @unlink($tmp);
            $this->logger->warning('Failed to sideload image', [
                'url' => $url,
                'error' => $attachment_id->get_error_message(),
            ]);
            return false;
        }

        // Store source URL for future reference
        update_post_meta($attachment_id, '_wcps_source_url', $url);

        $this->logger->info('Downloaded product image', [
            'product_id' => $product_id,
            'attachment_id' => $attachment_id,
            'filename' => $filename,
        ]);

        return $attachment_id;
    }

    /**
     * Convert MIME type to file extension
     *
     * @param string $mime MIME type
     * @return string|false Extension or false
     */
    private function mime_to_extension($mime) {
        $mime_map = [
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/bmp' => 'bmp',
            'image/svg+xml' => 'svg',
        ];

        return $mime_map[$mime] ?? false;
    }

    /**
     * Truncate slug to max length, preferring word boundaries
     *
     * @param string $slug The slug to truncate
     * @param int $max_length Maximum length
     * @return string Truncated slug
     */
    private function truncate_slug($slug, $max_length) {
        if (strlen($slug) <= $max_length) {
            return $slug;
        }

        // Try to truncate at a hyphen (word boundary)
        $truncated = substr($slug, 0, $max_length);
        $last_hyphen = strrpos($truncated, '-');

        if ($last_hyphen !== false && $last_hyphen > $max_length - 10) {
            // Truncate at word boundary if hyphen is near the end
            $truncated = substr($truncated, 0, $last_hyphen);
        }

        // Remove trailing hyphen if any
        $truncated = rtrim($truncated, '-');

        return $truncated;
    }

    /**
     * Find WC product by external ID
     *
     * @param string $external_id External ID from JSON
     * @return int|false Product ID or false
     */
    public function find_product_by_external_id($external_id) {
        global $wpdb;

        $product_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
            WHERE meta_key = '_external_id' AND meta_value = %s
            LIMIT 1",
            $external_id
        ));

        return $product_id ? (int) $product_id : false;
    }

    /**
     * Get all synced product IDs with their external IDs
     *
     * @return array [external_id => product_id]
     */
    public function get_all_synced_products() {
        global $wpdb;

        $results = $wpdb->get_results(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta}
            WHERE meta_key = '_external_id'",
            ARRAY_A
        );

        $products = [];
        foreach ($results as $row) {
            $products[$row['meta_value']] = (int) $row['post_id'];
        }

        return $products;
    }

    /**
     * Link alternative products after all products are synced
     *
     * @return int Number of products linked
     */
    public function link_alternative_products() {
        global $wpdb;

        // Load the full external_id → product_id map in one bulk query
        // instead of calling find_product_by_external_id() per linked ID (O(N×M) queries)
        $product_map = $this->get_all_synced_products();

        // Get all products with linked external IDs
        $products = $wpdb->get_results(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta}
            WHERE meta_key = '_linked_external_ids'",
            ARRAY_A
        );

        $linked_count = 0;

        foreach ($products as $row) {
            $product_id = (int) $row['post_id'];
            $linked_external_ids = maybe_unserialize($row['meta_value']);

            if (!is_array($linked_external_ids)) {
                continue;
            }

            $linked_product_ids = [];

            foreach ($linked_external_ids as $external_id) {
                // Array lookup — no SQL query per iteration
                $linked_id = $product_map[$external_id] ?? null;
                if ($linked_id && $linked_id !== $product_id) {
                    $linked_product_ids[] = $linked_id;
                }
            }

            if (!empty($linked_product_ids)) {
                // Store as cross-sells (shown on product page)
                update_post_meta($product_id, '_crosssell_ids', $linked_product_ids);
                $linked_count++;
            }
        }

        $this->logger->info('Linked alternative products', ['count' => $linked_count]);

        return $linked_count;
    }

    /**
     * Resolve alternative product URLs after all products are synced
     *
     * Bulk-resolves external IDs to WC product permalinks and stores
     * the result as _wcps_resolved_alternatives meta for frontend rendering.
     *
     * @return int Number of products resolved
     */
    public function resolve_alternative_product_urls() {
        global $wpdb;

        // Get all synced products mapping: external_id => product_id
        $product_map = $this->get_all_synced_products();

        // Get all products that have alternative products data
        $products = $wpdb->get_results(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta}
            WHERE meta_key = '_wcps_alternative_products'",
            ARRAY_A
        );

        $resolved_count = 0;

        foreach ($products as $row) {
            $product_id = (int) $row['post_id'];
            $alternative_products = maybe_unserialize($row['meta_value']);

            if (!is_array($alternative_products) || empty($alternative_products)) {
                continue;
            }

            $current_external_id = get_post_meta($product_id, '_external_id', true);
            $resolved = [];

            foreach ($alternative_products as $attr_slug => $group) {
                if (empty($group['products']) || !is_array($group['products'])) {
                    continue;
                }

                $resolved_group = [
                    'name' => $group['name'] ?? $attr_slug,
                    'products' => [],
                ];

                foreach ($group['products'] as $alt_product) {
                    $alt_id = $alt_product['id'] ?? '';
                    $alt_label = $alt_product['label'] ?? '';

                    if (empty($alt_id)) {
                        continue;
                    }

                    $is_current = ($alt_id === $current_external_id);
                    $wc_product_id = $product_map[$alt_id] ?? null;
                    $permalink = $wc_product_id ? get_permalink($wc_product_id) : '';

                    $resolved_group['products'][] = [
                        'external_id' => $alt_id,
                        'label' => $alt_label,
                        'product_id' => $wc_product_id,
                        'url' => $permalink,
                        'is_current' => $is_current,
                    ];
                }

                if (!empty($resolved_group['products'])) {
                    $resolved[$attr_slug] = $resolved_group;
                }
            }

            if (!empty($resolved)) {
                update_post_meta($product_id, '_wcps_resolved_alternatives', $resolved);
                $resolved_count++;
            }
        }

        $this->logger->info('Resolved alternative product URLs', ['count' => $resolved_count]);

        return $resolved_count;
    }
}
