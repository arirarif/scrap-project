<?php
/**
 * Variation Manager Class
 *
 * Handles WooCommerce variable product and variation creation from JSON data
 *
 * @package WC_Product_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCPS_Variation_Manager {

    /**
     * Logger instance
     */
    private $logger;

    /**
     * Product mapper instance
     */
    private $mapper;

    /**
     * Variation groups registry
     */
    private $variation_groups = [];

    /**
     * Constructor
     */
    public function __construct() {
        $this->logger = new WCPS_Logger();
    }

    /**
     * Set the product mapper instance
     *
     * @param WCPS_Product_Mapper $mapper
     */
    public function set_mapper($mapper) {
        $this->mapper = $mapper;
    }

    /**
     * Analyze JSON data and build variation groups
     *
     * @param array $json_data All products from JSON
     */
    public function analyze_variation_groups($json_data) {
        $this->logger->info('analyze_variation_groups: Starting fresh analysis', [
            'product_count' => count($json_data),
        ]);
        $this->variation_groups = [];

        foreach ($json_data as $external_id => $product) {
            // Skip products without AlternativeProductIds or with only one (self)
            if (empty($product['AlternativeProductIds']) || count($product['AlternativeProductIds']) <= 1) {
                continue;
            }

            // Generate group key from sorted alternative IDs
            $alt_ids = $product['AlternativeProductIds'];
            sort($alt_ids);
            $group_key = md5(implode(',', $alt_ids));

            if (!isset($this->variation_groups[$group_key])) {
                $this->variation_groups[$group_key] = [
                    'external_ids' => $alt_ids,
                    'parent_external_id' => null,
                    'parent_wc_id' => null,
                    'attributes' => [],
                    'variations' => [],
                ];
            }

            // Extract this product's attribute values from AlternativeProducts
            $this->extract_variation_attributes($group_key, $external_id, $product);
        }

        // Determine parent for each group (highest stock)
        foreach ($this->variation_groups as $key => &$group) {
            $group['parent_external_id'] = $this->select_parent_product($group, $json_data);
        }

        $this->logger->info('Variation groups analyzed', [
            'groups' => count($this->variation_groups),
            'total_variations' => array_sum(array_map(function ($g) {
                return count($g['external_ids']);
            }, $this->variation_groups)),
        ]);
    }

    /**
     * Detect groups with duplicate attribute combinations
     *
     * WooCommerce requires each variation to have a unique combo of attribute values.
     * Groups where two products map to the same combo cannot be variable products.
     *
     * @return array ['clean' => [...groups], 'broken' => [...groups]]
     */
    public function detect_duplicate_groups() {
        $clean_groups = [];
        $broken_groups = [];

        foreach ($this->variation_groups as $group_key => $group) {
            $has_duplicates = false;
            $seen_combos = [];

            foreach ($group['variations'] as $ext_id => $attrs) {
                // Sort by key to ensure consistent ordering
                ksort($attrs);
                $combo_key = md5(serialize($attrs));

                if (isset($seen_combos[$combo_key])) {
                    $has_duplicates = true;
                    break;
                }
                $seen_combos[$combo_key] = $ext_id;
            }

            if ($has_duplicates) {
                $broken_groups[$group_key] = $group;
            } else {
                $clean_groups[$group_key] = $group;
            }
        }

        $this->logger->info('Duplicate detection complete', [
            'clean_groups' => count($clean_groups),
            'broken_groups' => count($broken_groups),
            'clean_products' => array_sum(array_map(function ($g) { return count($g['external_ids']); }, $clean_groups)),
            'broken_products' => array_sum(array_map(function ($g) { return count($g['external_ids']); }, $broken_groups)),
        ]);

        return [
            'clean' => $clean_groups,
            'broken' => $broken_groups,
        ];
    }

    /**
     * Extract variation attributes from AlternativeProducts data
     *
     * @param string $group_key Group identifier
     * @param string $external_id Product external ID
     * @param array $product Product data
     */
    private function extract_variation_attributes($group_key, $external_id, $product) {
        if (empty($product['AlternativeProducts'])) {
            return;
        }

        $variation_attrs = [];

        foreach ($product['AlternativeProducts'] as $attr_slug => $attr_data) {
            $attr_name = $attr_data['name'];

            // Initialize attribute in group if not exists
            if (!isset($this->variation_groups[$group_key]['attributes'][$attr_slug])) {
                $this->variation_groups[$group_key]['attributes'][$attr_slug] = [
                    'name' => $attr_name,
                    'options' => [],
                ];
            }

            // Find the label for this product in this attribute group
            if (!empty($attr_data['products']) && is_array($attr_data['products'])) {
                foreach ($attr_data['products'] as $alt_product) {
                    $alt_id_str = (string) $alt_product['id'];
                    $ext_id_str = (string) $external_id;
                    $match = ($alt_id_str === $ext_id_str);

                    if ($match && !empty($alt_product['label'])) {
                        $label = $alt_product['label'];

                        // Ensure label is a string (fix for rawurldecode() type error)
                        if (!is_string($label)) {
                            if (is_array($label)) {
                                $label = implode(', ', array_map('strval', $label));
                            } else {
                                $label = (string) $label;
                            }
                            $this->logger->warning('Non-string label converted to string', [
                                'external_id' => $external_id,
                                'attribute' => $attr_slug,
                                'original_type' => gettype($alt_product['label']),
                                'converted_value' => $label,
                            ]);
                        }

                        // Handle duplicate attributes - skip if already set
                        if (isset($variation_attrs[$attr_slug])) {
                            $this->logger->warning('Duplicate attribute detected, keeping first value', [
                                'external_id' => $external_id,
                                'attribute' => $attr_slug,
                                'existing_value' => $variation_attrs[$attr_slug],
                                'skipped_value' => $label,
                            ]);
                            continue;
                        }

                        $variation_attrs[$attr_slug] = $label;

                        // Add to unique options
                        if (!in_array($label, $this->variation_groups[$group_key]['attributes'][$attr_slug]['options'])) {
                            $this->variation_groups[$group_key]['attributes'][$attr_slug]['options'][] = $label;
                        }
                        break;
                    }
                }
            }
        }

        $this->variation_groups[$group_key]['variations'][$external_id] = $variation_attrs;
    }

    /**
     * Select which product should be the parent variable product
     * Strategy: highest stock
     *
     * @param array $group Variation group data
     * @param array $json_data All products from JSON
     * @return string Selected parent external ID
     */
    private function select_parent_product($group, $json_data) {
        $best_id = null;
        $best_stock = -1;

        foreach ($group['external_ids'] as $ext_id) {
            if (!isset($json_data[$ext_id])) {
                continue;
            }

            $stock = (int) ($json_data[$ext_id]['Stock'] ?? 0);
            if ($stock > $best_stock) {
                $best_stock = $stock;
                $best_id = $ext_id;
            }
        }

        // Fallback to first ID if none have stock
        return $best_id ?? $group['external_ids'][0];
    }

    /**
     * Get all variation groups
     *
     * @return array
     */
    public function get_variation_groups() {
        return $this->variation_groups;
    }

    /**
     * Set variation groups (for batch processing)
     *
     * @param array $groups Variation groups data
     */
    public function set_variation_groups($groups) {
        $this->variation_groups = $groups;
    }

    /**
     * Check if external ID belongs to a variation group
     *
     * @param string $external_id
     * @return bool
     */
    public function is_variation_product($external_id) {
        foreach ($this->variation_groups as $group) {
            if (in_array($external_id, $group['external_ids'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get group key for an external ID
     *
     * @param string $external_id
     * @return string|null
     */
    public function get_group_for_product($external_id) {
        foreach ($this->variation_groups as $key => $group) {
            if (in_array($external_id, $group['external_ids'])) {
                return $key;
            }
        }
        return null;
    }

    /**
     * Sync a complete variation group
     *
     * @param string $group_key Group identifier
     * @param array $products_data Products in this group
     * @return int|false Parent product ID or false on failure
     */
    public function sync_variation_group($group_key, $products_data) {
        if (!isset($this->variation_groups[$group_key])) {
            $this->logger->error('Unknown variation group', ['group_key' => $group_key]);
            return false;
        }

        try {
            $group = $this->variation_groups[$group_key];
            $parent_ext_id = $group['parent_external_id'];

            $this->logger->info('sync_variation_group: Starting', [
                'parent_ext_id' => $parent_ext_id,
                'attributes' => array_keys($group['attributes']),
            ]);

            if (!isset($products_data[$parent_ext_id])) {
                $this->logger->error('Parent product data not found', ['external_id' => $parent_ext_id]);
                return false;
            }

            // Check if parent already exists
            $this->logger->info('sync_variation_group: Checking existing product');
            $existing_parent_id = $this->mapper->find_product_by_external_id($parent_ext_id);
            $this->logger->info('sync_variation_group: Existing check done', ['existing_id' => $existing_parent_id]);

            if ($existing_parent_id) {
                $existing_product = wc_get_product($existing_parent_id);

                // If it's a simple product, we need to convert it
                if ($existing_product && !$existing_product->is_type('variable')) {
                    $this->logger->info('sync_variation_group: Converting simple to variable');
                    $parent_id = $this->convert_simple_to_variable(
                        $existing_parent_id,
                        $products_data[$parent_ext_id],
                        $group
                    );
                } else {
                    // Update existing variable product
                    $this->logger->info('sync_variation_group: Updating existing variable');
                    $parent_id = $this->update_variable_product(
                        $existing_parent_id,
                        $products_data[$parent_ext_id],
                        $group
                    );
                }
            } else {
                // Create new variable product
                $this->logger->info('sync_variation_group: Creating new variable product');
                $parent_id = $this->mapper->create_variable_product(
                    $products_data[$parent_ext_id],
                    $group['attributes'],
                    $group_key
                );
                $this->logger->info('sync_variation_group: Variable product created', ['parent_id' => $parent_id]);

                // Build and set attributes on the new parent
                if ($parent_id) {
                    $result = $this->build_attributes_array($group['attributes']);
                    $attributes_array = $result['attributes'];
                    $term_ids_by_taxonomy = $result['term_ids'];

                    update_post_meta($parent_id, '_product_attributes', $attributes_array);

                    foreach ($term_ids_by_taxonomy as $taxonomy => $term_ids) {
                        if (!empty($term_ids)) {
                            wp_set_object_terms($parent_id, $term_ids, $taxonomy);
                        }
                    }
                }
            }

            if (!$parent_id) {
                $this->logger->error('Failed to create/update variable product', [
                    'external_id' => $parent_ext_id,
                ]);
                return false;
            }

            $this->logger->info('sync_variation_group: Parent ready, starting variations', ['parent_id' => $parent_id]);

            // Store parent ID in group
            $this->variation_groups[$group_key]['parent_wc_id'] = $parent_id;

            // Create/update variations (skip parent's own external ID — it's already the variable product)
            $variation_count = 0;
            $this->logger->info('sync_variation_group: Variations to process', ['count' => count($group['variations']) - 1]);
            foreach ($group['variations'] as $ext_id => $attrs) {
                // Skip the parent product — it IS the variable product, not a variation of itself
                if ((string) $ext_id === (string) $parent_ext_id) {
                    continue;
                }

                $this->logger->info('sync_variation_group: Processing variation', ['ext_id' => $ext_id]);
                try {
                    if (!isset($products_data[$ext_id])) {
                        $this->logger->warning('Variation product data not found', ['external_id' => $ext_id]);
                        continue;
                    }

                    $variation_id = $this->sync_variation($parent_id, $ext_id, $products_data[$ext_id], $attrs, $group);
                    if ($variation_id) {
                        $variation_count++;
                    }
                } catch (Exception $e) {
                    $this->logger->error('Exception creating variation', [
                        'external_id' => $ext_id,
                        'parent_id' => $parent_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Sync parent data (price range, stock status)
            $this->sync_variable_product_data($parent_id);

            $this->logger->info('Variation group synced', [
                'group_key' => $group_key,
                'parent_id' => $parent_id,
                'variations' => $variation_count,
            ]);

            return $parent_id;

        } catch (Exception $e) {
            $this->logger->error('Exception syncing variation group', [
                'group_key' => $group_key,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return false;
        }
    }

    /**
     * Create or update a single variation
     *
     * @param int $parent_id Parent variable product ID
     * @param string $external_id Variation external ID
     * @param array $product_data Product data from JSON
     * @param array $attributes Variation attribute values
     * @param array $group Group data for attribute names
     * @return int|false Variation ID or false
     */
    private function sync_variation($parent_id, $external_id, $product_data, $attributes, $group) {
        $this->logger->info('sync_variation: Starting', [
            'parent_id' => $parent_id,
            'external_id' => $external_id,
            'attributes_received' => $attributes,
            'attributes_count' => count($attributes),
        ]);

        // Check if variation exists
        $existing_id = $this->find_variation_by_external_id($external_id);
        $this->logger->info('sync_variation: Existing check', ['existing_id' => $existing_id]);

        // Format attributes for WooCommerce (taxonomy => slug)
        $wc_attributes = [];
        foreach ($attributes as $slug => $value) {
            // Ensure value is a string (fix for rawurldecode() type error)
            if (!is_string($value)) {
                $this->logger->warning('Invalid attribute value type, skipping', [
                    'slug' => $slug,
                    'value_type' => gettype($value),
                    'external_id' => $external_id,
                ]);
                // Try to convert to string if possible
                if (is_array($value)) {
                    $value = implode(', ', array_map('strval', $value));
                } elseif (is_object($value)) {
                    // Skip objects (like WC_Product_Attribute) entirely
                    continue;
                } else {
                    $value = (string) $value;
                }
            }

            // Skip empty values
            if (empty($value)) {
                continue;
            }

            $taxonomy_name = wc_sanitize_taxonomy_name($slug);
            if (strlen($taxonomy_name) > 28) {
                $taxonomy_name = substr($taxonomy_name, 0, 28);
                $taxonomy_name = rtrim($taxonomy_name, '-');
            }
            $taxonomy = 'pa_' . $taxonomy_name;
            $wc_attributes[$taxonomy] = sanitize_title($value);
        }

        $this->logger->info('sync_variation: WC attributes built', [
            'wc_attributes' => $wc_attributes,
            'wc_attributes_count' => count($wc_attributes),
        ]);

        if ($existing_id) {
            // Update existing variation using direct meta updates to avoid WooCommerce object issues
            $this->logger->info('sync_variation: Updating existing variation', ['variation_id' => $existing_id]);

            // Sanitize any corrupted attribute meta
            $this->sanitize_variation_attributes($existing_id);

            // Update variation data directly via post meta to avoid rawurldecode errors
            $price = (string) $product_data['Price'];
            $stock = (int) ($product_data['Stock'] ?? 0);
            $stock_status = $stock > 0 ? 'instock' : 'outofstock';

            update_post_meta($existing_id, '_regular_price', $price);
            update_post_meta($existing_id, '_price', $price);
            update_post_meta($existing_id, '_manage_stock', 'yes');
            update_post_meta($existing_id, '_stock', $stock);
            update_post_meta($existing_id, '_stock_status', $stock_status);

            // Update variation attributes directly as post meta
            // First, clear existing attribute meta
            global $wpdb;
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key LIKE 'attribute_%%'",
                $existing_id
            ));

            // Set new attribute values
            foreach ($wc_attributes as $taxonomy => $value) {
                // Ensure value is a string
                $meta_value = is_string($value) ? $value : (string) $value;
                update_post_meta($existing_id, 'attribute_' . $taxonomy, $meta_value);
            }

            // Update sync meta
            update_post_meta($existing_id, '_wcps_last_sync', current_time('mysql'));

            // Clear caches
            clean_post_cache($existing_id);
            wc_delete_product_transients($existing_id);

            $this->logger->info('sync_variation: Variation updated successfully', [
                'variation_id' => $existing_id,
                'price' => $price,
                'stock' => $stock,
            ]);

            return $existing_id;
        }

        // Check if this external_id exists as a simple product and delete it
        // (it's being replaced by a variation — but never delete the parent variable product)
        $existing_simple = $this->mapper->find_product_by_external_id($external_id);
        if ($existing_simple && get_post_type($existing_simple) === 'product' && (int) $existing_simple !== (int) $parent_id) {
            wp_delete_post($existing_simple, true);
            $this->logger->info('Deleted simple product to re-create as variation', [
                'external_id' => $external_id,
                'old_product_id' => $existing_simple,
            ]);
        }

        // Create new variation
        $this->logger->info('sync_variation: Creating new variation', [
            'parent_id' => $parent_id,
            'external_id' => $external_id,
        ]);
        $variation_id = $this->mapper->create_variation($product_data, $parent_id, $wc_attributes);
        $this->logger->info('sync_variation: Variation created', [
            'variation_id' => $variation_id,
            'success' => $variation_id ? true : false,
        ]);
        return $variation_id;
    }

    /**
     * Find variation by external ID
     *
     * @param string $external_id
     * @return int|false Variation ID or false
     */
    private function find_variation_by_external_id($external_id) {
        global $wpdb;

        $variation_id = $wpdb->get_var($wpdb->prepare(
            "SELECT pm.post_id FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
            WHERE pm.meta_key = '_external_id' AND pm.meta_value = %s
            AND p.post_type = 'product_variation'
            LIMIT 1",
            $external_id
        ));

        return $variation_id ? (int) $variation_id : false;
    }

    /**
     * Convert existing simple product to variable
     * Uses direct meta updates to avoid WooCommerce rawurldecode errors
     *
     * @param int $product_id Existing product ID
     * @param array $parent_data Parent product data
     * @param array $group Group data
     * @return int Product ID
     */
    private function convert_simple_to_variable($product_id, $parent_data, $group) {
        // Sanitize any existing corrupted attributes before conversion
        $this->sanitize_stored_attributes($product_id);

        // Change product type
        wp_set_object_terms($product_id, 'variable', 'product_type');

        // Update post title directly — prepend Producer to Name
        $product_name = $parent_data['Name'];
        if (!empty($parent_data['Producer']) && stripos($product_name, $parent_data['Producer']) !== 0) {
            $product_name = $parent_data['Producer'] . ' ' . $product_name;
        }
        wp_update_post([
            'ID' => $product_id,
            'post_title' => $product_name,
            'post_status' => 'publish',
        ]);

        // Remove simple product specific data
        delete_post_meta($product_id, '_regular_price');
        delete_post_meta($product_id, '_sale_price');
        delete_post_meta($product_id, '_price');

        // Preserve SKU on the parent (set to parent's external ID)
        $external_id = get_post_meta($product_id, '_external_id', true);
        if ($external_id) {
            update_post_meta($product_id, '_sku', (string) $external_id);
        }

        // Set product attributes directly as array (not WC_Product_Attribute objects)
        $result = $this->build_attributes_array($group['attributes']);
        $attributes_array = $result['attributes'];
        $term_ids_by_taxonomy = $result['term_ids'];

        update_post_meta($product_id, '_product_attributes', $attributes_array);

        // Assign attribute terms to the product (required for frontend display)
        foreach ($term_ids_by_taxonomy as $taxonomy => $term_ids) {
            if (!empty($term_ids)) {
                wp_set_object_terms($product_id, $term_ids, $taxonomy);
            }
        }

        // Set visibility
        update_post_meta($product_id, '_visibility', 'visible');

        // Update meta
        update_post_meta($product_id, '_wcps_is_variable_parent', 'yes');
        update_post_meta($product_id, '_wcps_last_sync', current_time('mysql'));

        // Clear caches
        clean_post_cache($product_id);
        wc_delete_product_transients($product_id);

        $this->logger->info('Converted simple to variable', ['product_id' => $product_id]);

        return $product_id;
    }

    /**
     * Update existing variable product
     * Uses direct meta updates to avoid WooCommerce rawurldecode errors
     *
     * @param int $product_id Product ID
     * @param array $parent_data Parent product data
     * @param array $group Group data
     * @return int Product ID
     */
    private function update_variable_product($product_id, $parent_data, $group) {
        $this->logger->info('update_variable_product: Starting', ['product_id' => $product_id]);

        // Sanitize any existing corrupted attributes before updating
        $this->sanitize_stored_attributes($product_id);

        // Check if it's a variable product
        $product_type = wp_get_post_terms($product_id, 'product_type', ['fields' => 'slugs']);
        if (empty($product_type) || !in_array('variable', $product_type)) {
            $this->logger->info('update_variable_product: Not a variable product, returning');
            return $product_id;
        }

        // Update post title directly — prepend Producer to Name
        $product_name = $parent_data['Name'];
        if (!empty($parent_data['Producer']) && stripos($product_name, $parent_data['Producer']) !== 0) {
            $product_name = $parent_data['Producer'] . ' ' . $product_name;
        }
        wp_update_post([
            'ID' => $product_id,
            'post_title' => $product_name,
        ]);

        // Set product attributes directly as array (not WC_Product_Attribute objects)
        $this->logger->info('update_variable_product: Building attributes array');
        $result = $this->build_attributes_array($group['attributes']);
        $attributes_array = $result['attributes'];
        $term_ids_by_taxonomy = $result['term_ids'];

        $this->logger->info('update_variable_product: Setting attributes', ['count' => count($attributes_array)]);
        update_post_meta($product_id, '_product_attributes', $attributes_array);

        // Assign attribute terms to the product (required for frontend display)
        foreach ($term_ids_by_taxonomy as $taxonomy => $term_ids) {
            if (!empty($term_ids)) {
                wp_set_object_terms($product_id, $term_ids, $taxonomy);
            }
        }

        // Update meta
        update_post_meta($product_id, '_wcps_last_sync', current_time('mysql'));

        // Clear caches
        clean_post_cache($product_id);
        wc_delete_product_transients($product_id);

        $this->logger->info('update_variable_product: Done');
        return $product_id;
    }

    /**
     * Build attributes array for direct storage (bypassing WC_Product_Attribute objects)
     *
     * @param array $variation_attrs Attributes data [slug => ['name' => ..., 'options' => [...]]]
     * @return array ['attributes' => attributes array, 'term_ids' => [taxonomy => [term_ids]]]
     */
    private function build_attributes_array($variation_attrs) {
        $attributes = [];
        $term_ids_by_taxonomy = [];

        foreach ($variation_attrs as $slug => $data) {
            $name = $data['name'];
            $options = $data['options'];

            // Ensure name is a string
            if (!is_string($name)) {
                $name = is_array($name) ? implode(', ', $name) : (string) $name;
            }

            // Sanitize options to ensure all are strings
            $sanitized_options = [];
            foreach ($options as $option) {
                if (is_string($option)) {
                    $sanitized_options[] = $option;
                } elseif (!is_object($option)) {
                    $sanitized_options[] = (string) $option;
                }
            }

            // Create taxonomy name
            $taxonomy_name = wc_sanitize_taxonomy_name($slug);
            if (strlen($taxonomy_name) > 28) {
                $taxonomy_name = substr($taxonomy_name, 0, 28);
                $taxonomy_name = rtrim($taxonomy_name, '-');
            }
            $taxonomy = 'pa_' . $taxonomy_name;

            // Ensure global attribute exists
            $attribute_id = $this->ensure_global_attribute($name, $taxonomy_name);

            // Create terms for the attribute and collect term IDs
            $term_ids_by_taxonomy[$taxonomy] = [];
            foreach ($sanitized_options as $value) {
                $term = get_term_by('name', $value, $taxonomy);
                if (!$term) {
                    $term = get_term_by('slug', sanitize_title($value), $taxonomy);
                }
                if (!$term) {
                    $result = wp_insert_term($value, $taxonomy);
                    if (!is_wp_error($result)) {
                        $term = get_term($result['term_id'], $taxonomy);
                    }
                }
                if ($term) {
                    $term_ids_by_taxonomy[$taxonomy][] = $term->term_id;
                }
            }

            // Build attribute array in WooCommerce storage format
            $attributes[$taxonomy] = [
                'name' => $taxonomy,
                'value' => '',  // Empty for taxonomy-based attributes
                'position' => count($attributes),
                'is_visible' => 1,
                'is_variation' => 1,
                'is_taxonomy' => 1,
            ];
        }

        return [
            'attributes' => $attributes,
            'term_ids' => $term_ids_by_taxonomy,
        ];
    }

    /**
     * Ensure a global attribute exists
     *
     * @param string $label Human-readable label
     * @param string $slug Sanitized slug
     * @return int|false Attribute ID or false
     */
    private function ensure_global_attribute($label, $slug) {
        // Check if attribute exists
        $attribute_id = wc_attribute_taxonomy_id_by_name($slug);

        if ($attribute_id) {
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
        if (!taxonomy_exists($taxonomy)) {
            register_taxonomy($taxonomy, 'product', [
                'labels' => ['name' => $label],
                'hierarchical' => false,
                'show_ui' => false,
                'query_var' => true,
                'rewrite' => false,
            ]);
        }

        $this->logger->info('Created global attribute', ['label' => $label, 'slug' => $slug]);

        return $attribute_id;
    }

    /**
     * Sync variable product data (price range, stock)
     * Uses manual calculation instead of WC_Product_Variable::sync() to avoid rawurldecode errors
     *
     * @param int $parent_id Variable product ID
     */
    private function sync_variable_product_data($parent_id) {
        // Clear all caches to ensure fresh data
        wc_delete_product_transients($parent_id);
        clean_post_cache($parent_id);

        // Sanitize stored attributes to fix any corrupted data
        $this->sanitize_stored_attributes($parent_id);

        // Get fresh product instance
        $product = wc_get_product($parent_id);
        if (!$product || !$product->is_type('variable')) {
            return;
        }

        // Get children and sanitize their attributes
        $children = $product->get_children();
        foreach ($children as $child_id) {
            $this->sanitize_variation_attributes($child_id);
            clean_post_cache($child_id);
        }

        // Manually calculate price range and stock status instead of using WC_Product_Variable::sync()
        // This avoids the rawurldecode() error in WooCommerce core
        $this->manual_sync_variable_product($parent_id, $children);
    }

    /**
     * Manually sync variable product data without using WC_Product_Variable::sync()
     * This avoids the rawurldecode() type error in WooCommerce core
     *
     * @param int $parent_id Variable product ID
     * @param array $children Array of variation IDs
     */
    private function manual_sync_variable_product($parent_id, $children) {
        $min_price = null;
        $max_price = null;
        $stock_status = 'outofstock';
        $total_stock = 0;
        $manage_stock = false;

        foreach ($children as $child_id) {
            // Get variation data directly from post meta to avoid object loading issues
            $variation_price = get_post_meta($child_id, '_price', true);
            $variation_regular_price = get_post_meta($child_id, '_regular_price', true);
            $variation_stock_status = get_post_meta($child_id, '_stock_status', true);
            $variation_stock = get_post_meta($child_id, '_stock', true);
            $variation_manage_stock = get_post_meta($child_id, '_manage_stock', true);

            // Use regular price if price is not set
            $price = $variation_price !== '' ? $variation_price : $variation_regular_price;

            // Calculate min/max prices
            if ($price !== '' && $price !== null) {
                $price_float = (float) $price;
                if ($min_price === null || $price_float < $min_price) {
                    $min_price = $price_float;
                }
                if ($max_price === null || $price_float > $max_price) {
                    $max_price = $price_float;
                }
            }

            // Calculate stock status
            if ($variation_stock_status === 'instock') {
                $stock_status = 'instock';
            }

            // Calculate total stock
            if ($variation_manage_stock === 'yes') {
                $manage_stock = true;
                $total_stock += (int) $variation_stock;
            }
        }

        // Update parent product price meta
        if ($min_price !== null) {
            update_post_meta($parent_id, '_price', $min_price);
            update_post_meta($parent_id, '_min_variation_price', $min_price);
            update_post_meta($parent_id, '_min_variation_regular_price', $min_price);
        }
        if ($max_price !== null) {
            update_post_meta($parent_id, '_max_variation_price', $max_price);
            update_post_meta($parent_id, '_max_variation_regular_price', $max_price);
        }

        // Update stock status
        update_post_meta($parent_id, '_stock_status', $stock_status);
        wc_update_product_stock_status($parent_id, $stock_status);

        // Clear transients again after updates
        wc_delete_product_transients($parent_id);

        $this->logger->info('Manual variable product sync completed', [
            'parent_id' => $parent_id,
            'children' => count($children),
            'min_price' => $min_price,
            'max_price' => $max_price,
            'stock_status' => $stock_status,
        ]);
    }

    /**
     * Sanitize stored product attributes to fix corrupted data
     * Removes WC_Product_Attribute objects that were incorrectly serialized
     *
     * @param int $product_id Product ID
     */
    private function sanitize_stored_attributes($product_id) {
        $attributes = get_post_meta($product_id, '_product_attributes', true);

        if (empty($attributes) || !is_array($attributes)) {
            return;
        }

        $sanitized = [];
        $needs_update = false;
        $terms_to_reassign = []; // Track terms that need to be re-assigned

        foreach ($attributes as $key => $attribute) {
            // If it's a WC_Product_Attribute object, convert to array
            if (is_object($attribute) && $attribute instanceof WC_Product_Attribute) {
                $needs_update = true;
                $is_taxonomy = $attribute->is_taxonomy();
                $attr_name = $attribute->get_name();
                $options = $attribute->get_options();

                // For taxonomy-based attributes, value should be empty
                // For custom attributes, value is the pipe-separated list of options
                $value = '';
                if (!$is_taxonomy) {
                    if (is_array($options)) {
                        $value = implode(' | ', $options);
                    }
                } else {
                    // For taxonomy attributes, get_options() returns term IDs
                    // We need to re-assign these terms to the product
                    if (is_array($options) && !empty($options)) {
                        $terms_to_reassign[$attr_name] = array_map('intval', $options);
                    }
                }

                $sanitized[$key] = [
                    'name' => $attr_name,
                    'value' => $value,
                    'position' => $attribute->get_position(),
                    'is_visible' => $attribute->get_visible() ? 1 : 0,
                    'is_variation' => $attribute->get_variation() ? 1 : 0,
                    'is_taxonomy' => $is_taxonomy ? 1 : 0,
                ];
                $this->logger->warning('Converted WC_Product_Attribute object to array', [
                    'product_id' => $product_id,
                    'attribute_key' => $key,
                    'is_taxonomy' => $is_taxonomy,
                ]);
            } elseif (is_array($attribute)) {
                // Ensure all values in the attribute array are strings/scalars
                $clean_attr = [];
                foreach ($attribute as $attr_key => $attr_value) {
                    if (is_object($attr_value)) {
                        $needs_update = true;
                        $clean_attr[$attr_key] = '';
                    } elseif (is_array($attr_value)) {
                        $needs_update = true;
                        $clean_attr[$attr_key] = implode(' | ', array_map('strval', $attr_value));
                    } else {
                        $clean_attr[$attr_key] = $attr_value;
                    }
                }
                $sanitized[$key] = $clean_attr;
            } else {
                // Skip non-array, non-object entries
                $needs_update = true;
                $this->logger->warning('Skipping invalid attribute entry', [
                    'product_id' => $product_id,
                    'attribute_key' => $key,
                    'type' => gettype($attribute),
                ]);
            }
        }

        if ($needs_update) {
            update_post_meta($product_id, '_product_attributes', $sanitized);

            // Re-assign terms that were extracted from WC_Product_Attribute objects
            foreach ($terms_to_reassign as $taxonomy => $term_ids) {
                if (!empty($term_ids)) {
                    wp_set_object_terms($product_id, $term_ids, $taxonomy);
                    $this->logger->info('Re-assigned attribute terms after sanitization', [
                        'product_id' => $product_id,
                        'taxonomy' => $taxonomy,
                        'term_count' => count($term_ids),
                    ]);
                }
            }

            $this->logger->info('Sanitized product attributes', [
                'product_id' => $product_id,
                'attribute_count' => count($sanitized),
            ]);
        }
    }

    /**
     * Sanitize variation attribute meta values
     * Ensures all attribute_pa_* meta values are strings, not objects
     *
     * @param int $variation_id Variation ID
     */
    private function sanitize_variation_attributes($variation_id) {
        global $wpdb;

        // Get all attribute meta for this variation
        $metas = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_id, meta_key, meta_value FROM {$wpdb->postmeta}
            WHERE post_id = %d AND meta_key LIKE 'attribute_%%'",
            $variation_id
        ), ARRAY_A);

        foreach ($metas as $meta) {
            $value = maybe_unserialize($meta['meta_value']);

            // Check if the value is an object (corrupted data)
            if (is_object($value)) {
                $this->logger->warning('Found corrupted variation attribute (object), fixing', [
                    'variation_id' => $variation_id,
                    'meta_key' => $meta['meta_key'],
                    'object_type' => get_class($value),
                ]);

                // Try to extract a string value from the object
                $string_value = '';
                if ($value instanceof WC_Product_Attribute) {
                    $options = $value->get_options();
                    $string_value = is_array($options) && !empty($options) ? sanitize_title(reset($options)) : '';
                }

                // Update with sanitized string value
                update_post_meta($variation_id, $meta['meta_key'], $string_value);
            } elseif (is_array($value)) {
                $this->logger->warning('Found corrupted variation attribute (array), fixing', [
                    'variation_id' => $variation_id,
                    'meta_key' => $meta['meta_key'],
                ]);

                // Convert array to string
                $string_value = sanitize_title(implode('-', array_map('strval', $value)));
                update_post_meta($variation_id, $meta['meta_key'], $string_value);
            }
        }
    }

    /**
     * Delete orphaned variations (parent deleted or group changed)
     *
     * @param array $valid_external_ids All valid external IDs from JSON
     * @return int Number of deleted variations
     */
    public function cleanup_orphaned_variations($valid_external_ids) {
        global $wpdb;

        // Find all variations created by this plugin
        $variations = $wpdb->get_results(
            "SELECT pm.post_id, pm.meta_value as external_id
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
            WHERE pm.meta_key = '_external_id'
            AND p.post_type = 'product_variation'
            AND EXISTS (
                SELECT 1 FROM {$wpdb->postmeta} pm2
                WHERE pm2.post_id = pm.post_id
                AND pm2.meta_key = '_wcps_is_variation'
                AND pm2.meta_value = 'yes'
            )",
            ARRAY_A
        );

        $deleted = 0;
        foreach ($variations as $variation) {
            if (!in_array($variation['external_id'], $valid_external_ids)) {
                wp_delete_post($variation['post_id'], true);
                $deleted++;
                $this->logger->info('Deleted orphaned variation', [
                    'variation_id' => $variation['post_id'],
                    'external_id' => $variation['external_id'],
                ]);
            }
        }

        return $deleted;
    }

    /**
     * Scan for corrupted product attribute data
     * Returns a report of all products with corrupted attributes
     *
     * @return array Report of corrupted products
     */
    public function scan_corrupted_attributes() {
        global $wpdb;

        $report = [
            'corrupted_products' => [],
            'corrupted_variations' => [],
            'total_corrupted' => 0,
        ];

        // Scan _product_attributes meta for all products
        $products = $wpdb->get_results(
            "SELECT pm.post_id, pm.meta_value, p.post_title
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
            WHERE pm.meta_key = '_product_attributes'
            AND p.post_type IN ('product', 'product_variation')
            AND pm.meta_value != ''",
            ARRAY_A
        );

        foreach ($products as $row) {
            $attributes = maybe_unserialize($row['meta_value']);

            if (!is_array($attributes)) {
                continue;
            }

            $corrupted_attrs = [];
            foreach ($attributes as $key => $attr) {
                if (is_object($attr)) {
                    $corrupted_attrs[] = [
                        'key' => $key,
                        'type' => get_class($attr),
                        'issue' => 'Object instead of array',
                    ];
                } elseif (is_array($attr)) {
                    foreach ($attr as $attr_key => $attr_value) {
                        if (is_object($attr_value)) {
                            $corrupted_attrs[] = [
                                'key' => $key . '.' . $attr_key,
                                'type' => get_class($attr_value),
                                'issue' => 'Object value in attribute array',
                            ];
                        }
                    }
                }
            }

            if (!empty($corrupted_attrs)) {
                $report['corrupted_products'][] = [
                    'product_id' => $row['post_id'],
                    'title' => $row['post_title'],
                    'corrupted_attributes' => $corrupted_attrs,
                ];
                $report['total_corrupted']++;
            }
        }

        // Scan variation attribute_* meta
        $variations = $wpdb->get_results(
            "SELECT pm.post_id, pm.meta_key, pm.meta_value, p.post_title
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
            WHERE pm.meta_key LIKE 'attribute_%'
            AND p.post_type = 'product_variation'",
            ARRAY_A
        );

        foreach ($variations as $row) {
            $value = maybe_unserialize($row['meta_value']);

            if (is_object($value) || is_array($value)) {
                $report['corrupted_variations'][] = [
                    'variation_id' => $row['post_id'],
                    'title' => $row['post_title'],
                    'meta_key' => $row['meta_key'],
                    'type' => is_object($value) ? get_class($value) : 'array',
                    'issue' => is_object($value) ? 'Object instead of string' : 'Array instead of string',
                ];
                $report['total_corrupted']++;
            }
        }

        return $report;
    }

    /**
     * Fix all corrupted attribute data in the database
     *
     * @return array Summary of fixes applied
     */
    public function repair_all_corrupted_attributes() {
        global $wpdb;

        $summary = [
            'products_fixed' => 0,
            'variations_fixed' => 0,
            'errors' => [],
        ];

        // Fix _product_attributes meta
        $products = $wpdb->get_results(
            "SELECT pm.post_id, pm.meta_value
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
            WHERE pm.meta_key = '_product_attributes'
            AND p.post_type IN ('product', 'product_variation')
            AND pm.meta_value != ''",
            ARRAY_A
        );

        foreach ($products as $row) {
            try {
                $attributes = maybe_unserialize($row['meta_value']);

                if (!is_array($attributes)) {
                    continue;
                }

                $needs_fix = false;
                $sanitized = [];

                foreach ($attributes as $key => $attr) {
                    if (is_object($attr) && $attr instanceof WC_Product_Attribute) {
                        $needs_fix = true;
                        $sanitized[$key] = [
                            'name' => $attr->get_name(),
                            'value' => is_array($attr->get_options()) ? implode(' | ', $attr->get_options()) : '',
                            'position' => $attr->get_position(),
                            'is_visible' => $attr->get_visible() ? 1 : 0,
                            'is_variation' => $attr->get_variation() ? 1 : 0,
                            'is_taxonomy' => $attr->is_taxonomy() ? 1 : 0,
                        ];
                    } elseif (is_array($attr)) {
                        $clean_attr = [];
                        foreach ($attr as $attr_key => $attr_value) {
                            if (is_object($attr_value)) {
                                $needs_fix = true;
                                $clean_attr[$attr_key] = '';
                            } elseif (is_array($attr_value)) {
                                $needs_fix = true;
                                $clean_attr[$attr_key] = implode(' | ', array_map('strval', $attr_value));
                            } else {
                                $clean_attr[$attr_key] = $attr_value;
                            }
                        }
                        $sanitized[$key] = $clean_attr;
                    }
                }

                if ($needs_fix) {
                    update_post_meta($row['post_id'], '_product_attributes', $sanitized);
                    $summary['products_fixed']++;
                    $this->logger->info('Repaired corrupted product attributes', [
                        'product_id' => $row['post_id'],
                    ]);
                }
            } catch (Exception $e) {
                $summary['errors'][] = [
                    'product_id' => $row['post_id'],
                    'error' => $e->getMessage(),
                ];
            }
        }

        // Fix variation attribute_* meta
        $variations = $wpdb->get_results(
            "SELECT pm.post_id, pm.meta_key, pm.meta_value
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
            WHERE pm.meta_key LIKE 'attribute_%'
            AND p.post_type = 'product_variation'",
            ARRAY_A
        );

        foreach ($variations as $row) {
            try {
                $value = maybe_unserialize($row['meta_value']);

                if (is_object($value)) {
                    $string_value = '';
                    if ($value instanceof WC_Product_Attribute) {
                        $options = $value->get_options();
                        $string_value = is_array($options) && !empty($options) ? sanitize_title(reset($options)) : '';
                    }
                    update_post_meta($row['post_id'], $row['meta_key'], $string_value);
                    $summary['variations_fixed']++;
                    $this->logger->info('Repaired corrupted variation attribute', [
                        'variation_id' => $row['post_id'],
                        'meta_key' => $row['meta_key'],
                    ]);
                } elseif (is_array($value)) {
                    $string_value = sanitize_title(implode('-', array_map('strval', $value)));
                    update_post_meta($row['post_id'], $row['meta_key'], $string_value);
                    $summary['variations_fixed']++;
                    $this->logger->info('Repaired corrupted variation attribute (array)', [
                        'variation_id' => $row['post_id'],
                        'meta_key' => $row['meta_key'],
                    ]);
                }
            } catch (Exception $e) {
                $summary['errors'][] = [
                    'variation_id' => $row['post_id'],
                    'meta_key' => $row['meta_key'],
                    'error' => $e->getMessage(),
                ];
            }
        }

        // Clear all product transients after repair
        wc_delete_product_transients();

        return $summary;
    }
}
