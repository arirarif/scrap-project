<?php
/**
 * Frontend Class
 *
 * Renders alternative product buttons on single product pages
 *
 * @package WC_Product_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCPS_Frontend {

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
        add_action('woocommerce_single_product_summary', [$this, 'render_alternative_products'], 25);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_styles']);
    }

    /**
     * Enqueue frontend styles
     */
    public function enqueue_styles() {
        if (!is_product()) {
            return;
        }

        wp_enqueue_style(
            'wcps-frontend',
            WCPS_PLUGIN_URL . 'assets/css/frontend.css',
            [],
            WCPS_VERSION
        );
    }

    /**
     * Render alternative product buttons on the product page
     */
    public function render_alternative_products() {
        global $product;

        if (!$product) {
            return;
        }

        $product_id = $product->get_id();
        $alternatives = get_post_meta($product_id, '_wcps_resolved_alternatives', true);

        if (empty($alternatives) || !is_array($alternatives)) {
            return;
        }

        echo '<div class="wcps-alternative-products">';

        foreach ($alternatives as $attr_slug => $group) {
            if (empty($group['products']) || !is_array($group['products'])) {
                continue;
            }

            $group_name = $group['name'] ?? $attr_slug;

            echo '<div class="wcps-alt-group">';
            echo '<span class="wcps-alt-label">' . esc_html($group_name) . ':</span>';
            echo '<div class="wcps-alt-buttons">';

            foreach ($group['products'] as $alt) {
                $label = $alt['label'] ?? '';
                $is_current = !empty($alt['is_current']);
                $url = $alt['url'] ?? '';
                $product_id = $alt['product_id'] ?? null;

                if (empty($label)) {
                    continue;
                }

                if ($is_current) {
                    echo '<span class="wcps-alt-btn wcps-alt-btn-active">' . esc_html($label) . '</span>';
                } elseif ($url && $product_id) {
                    echo '<a href="' . esc_url($url) . '" class="wcps-alt-btn">' . esc_html($label) . '</a>';
                } else {
                    echo '<span class="wcps-alt-btn wcps-alt-btn-unavailable">' . esc_html($label) . '</span>';
                }
            }

            echo '</div>';
            echo '</div>';
        }

        echo '</div>';
    }
}
