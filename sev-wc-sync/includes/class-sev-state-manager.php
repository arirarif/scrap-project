<?php
/**
 * State Manager — MD5-based per-product change detection.
 *
 * Stores and compares a hash of the fields that matter for syncing.
 * Products whose hash hasn't changed since the last sync are skipped,
 * saving both time and WooCommerce database load.
 *
 * Meta key used on the WC product post: _sev_hash
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SEV_State_Manager {

	/**
	 * Compute a reproducible MD5 hash of a product's sync-relevant fields.
	 *
	 * Fields included in the hash:
	 *   name, vendor, sku, price, stock_status, stock_qty,
	 *   category, features, photos
	 *
	 * Changes to any of these fields will cause is_changed() to return true
	 * and trigger a full re-sync of that product (including image re-check).
	 *
	 * @param  array  $product  Internal product array from SEV_Transformer.
	 * @return string           32-character hex MD5 digest.
	 */
	public function get_hash( array $product ): string {
		$hashable = [
			'name'         => $product['name']        ?? '',
			'vendor'       => $product['vendor']       ?? '',
			'sku'          => $product['sku']          ?? '',
			'price'        => $product['price']        ?? 0.0,
			'stock_status' => $product['stock_status'] ?? '',
			'stock_qty'    => $product['stock_qty']    ?? null,
			'category'     => $product['category']     ?? '',
			'features'     => $product['features']     ?? [],
			'photos'       => $product['photos']       ?? [],
		];

		return md5( serialize( $hashable ) );
	}

	/**
	 * Determine whether a product has changed since the last sync.
	 *
	 * Compares the stored hash (_sev_hash post meta) with the hash
	 * computed from the current internal product data.
	 *
	 * @param  int   $wc_id    WooCommerce post ID of the existing product.
	 * @param  array $product  Internal product array (from transformer).
	 * @return bool            true  → product has changed, needs update.
	 *                         false → product is unchanged, safe to skip.
	 */
	public function is_changed( int $wc_id, array $product ): bool {
		$stored  = (string) get_post_meta( $wc_id, '_sev_hash', true );
		$current = $this->get_hash( $product );
		return $stored !== $current;
	}

	/**
	 * Persist the current product hash after a successful sync.
	 *
	 * Call this immediately after SEV_Product_Mapper::map_product() succeeds.
	 *
	 * @param int   $wc_id    WooCommerce product ID.
	 * @param array $product  Internal product array.
	 */
	public function store_hash( int $wc_id, array $product ): void {
		update_post_meta( $wc_id, '_sev_hash', $this->get_hash( $product ) );
	}
}
