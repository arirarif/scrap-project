<?php
/**
 * Product Mapper — creates and updates WooCommerce products
 * from the internal product format produced by SEV_Transformer.
 *
 * Internal format expected:
 *   'id'           => string    Shopify product ID
 *   'name'         => string    Product title
 *   'vendor'       => string    Brand / producer
 *   'sku'          => string    Shopify variant SKU, or '' if none
 *   'price'        => float     Regular price (EUR)
 *   'stock_status' => string    'instock' | 'outofstock'
 *   'stock_qty'    => int|null  Inventory quantity if > 0, null otherwise
 *   'photos'       => string[]  CDN image URLs (query string already stripped)
 *   'category'     => string    WC category path, e.g. "Stromerzeuger - Portable Generators"
 *   'features'     => array     [ ['Id'=>slug, 'Name'=>label, 'Value'=>[['Name'=>val]]], ... ]
 *
 * Meta keys written (all prefixed _sev_):
 *   _sev_external_id  — Shopify product ID (used for product matching on re-sync)
 *   _sev_synced       — 'yes'
 *   _sev_last_sync    — datetime of last successful sync (mysql format)
 *   _sev_source_url   — stored on WP media attachment for image deduplication
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SEV_Product_Mapper {

	/** @var SEV_Logger */
	private $logger;

	/** @var array<string,int>  attribute slug → WC attribute ID cache */
	private $attribute_cache = [];

	/** @var array<string,int[]> category path → [term_id, ...] cache */
	private $category_cache = [];

	public function __construct() {
		$this->logger = new SEV_Logger();
	}

	// ── Public API ─────────────────────────────────────────────────────────────

	/**
	 * Create or update a WooCommerce product from internal product data.
	 *
	 * @param  array    $product  Internal product array from SEV_Transformer.
	 * @param  int|null $wc_id    Existing WC product ID (null = create new).
	 * @return int|WP_Error       Product post ID on success, WP_Error on failure.
	 */
	public function map_product( array $product, $wc_id = null ) {
		try {
			// ── Load or create product ──────────────────────────────────────
			if ( $wc_id ) {
				$wc_product = wc_get_product( $wc_id );
				if ( ! $wc_product ) {
					$wc_product = new WC_Product_Simple();
				}
			} else {
				$wc_product = new WC_Product_Simple();
			}

			// ── Name ────────────────────────────────────────────────────────
			// Prepend vendor/brand to the title if it's not already there.
			$name   = trim( $product['name'] ?? '' );
			$vendor = trim( $product['vendor'] ?? '' );
			if ( $vendor !== '' && stripos( $name, $vendor ) !== 0 ) {
				$name = $vendor . ' ' . $name;
			}
			$wc_product->set_name( $name );

			// ── SKU ─────────────────────────────────────────────────────────
			// Use the Shopify variant SKU as-is; leave blank when Shopify has none.
			// Guard against WC uniqueness enforcement: if a stale duplicate product
			// still holds this SKU, save without it rather than aborting the import.
			$sku = (string) ( $product['sku'] ?? '' );
			if ( $sku !== '' ) {
				$sku_owner = wc_get_product_id_by_sku( $sku );
				if ( $sku_owner && $sku_owner !== ( $wc_id ?? 0 ) ) {
					$sku = ''; // another product owns this SKU — will be fixed once duplicates are purged
				}
			}
			$wc_product->set_sku( $sku );

			// ── Price ───────────────────────────────────────────────────────
			$wc_product->set_regular_price( (string) ( $product['price'] ?? 0 ) );

			// ── Stock ───────────────────────────────────────────────────────
			// stock_qty is int when Shopify reported a positive inventory count,
			// null when the public API returned 0 / didn't report a quantity.
			$stock_qty = $product['stock_qty'] ?? null;
			if ( $stock_qty !== null ) {
				$wc_product->set_manage_stock( true );
				$wc_product->set_stock_quantity( (int) $stock_qty );
			} else {
				$wc_product->set_manage_stock( false );
				$wc_product->set_stock_quantity( null );
			}
			$wc_product->set_stock_status( $product['stock_status'] ?? 'instock' );

			// ── Visibility ──────────────────────────────────────────────────
			$wc_product->set_status( 'publish' );
			$wc_product->set_catalog_visibility( 'visible' );

			// ── Category ────────────────────────────────────────────────────
			if ( ! empty( $product['category'] ) ) {
				$cat_ids = $this->get_or_create_categories( (string) $product['category'] );
				$wc_product->set_category_ids( $cat_ids );
			}

			// ── Attributes (vendor + spec features) ─────────────────────────
			$wc_product->set_attributes( $this->build_attributes( $product ) );

			// ── Save ────────────────────────────────────────────────────────
			$product_id = $wc_product->save();

			if ( ! $product_id ) {
				throw new Exception( 'WC_Product::save() returned 0 — product could not be saved.' );
			}

			// ── Plugin meta ─────────────────────────────────────────────────
			update_post_meta( $product_id, '_sev_external_id', (string) $product['id'] );
			update_post_meta( $product_id, '_sev_synced',      'yes' );
			update_post_meta( $product_id, '_sev_last_sync',   current_time( 'mysql' ) );

			// ── Images ──────────────────────────────────────────────────────
			if ( ! empty( $product['photos'] ) ) {
				$this->handle_photos( $product_id, (array) $product['photos'] );
			}

			return $product_id;

		} catch ( Exception $e ) {
			$this->logger->error( 'Failed to map product', [
				'id'    => $product['id'] ?? '?',
				'name'  => mb_substr( $product['name'] ?? '', 0, 60, 'UTF-8' ),
				'error' => $e->getMessage(),
			] );
			return new WP_Error( 'mapping_error', $e->getMessage() );
		}
	}

	/**
	 * Get all plugin-managed products, keyed by external (Shopify) ID.
	 *
	 * Queries both the current meta key (_sev_external_id) and the legacy key
	 * from the previous plugin version (_external_id) so that products imported
	 * by the old plugin are found, matched, and cleaned up correctly.
	 *
	 * On the first sync after a plugin upgrade, existing products are matched via
	 * _external_id; map_product() then writes _sev_external_id to each one,
	 * migrating them for all subsequent syncs.
	 *
	 * Used by the sync handler to build the ID map before processing batches.
	 *
	 * @return array<string,int>  [ external_id => wc_product_id ]
	 */
	public function get_all_synced_products(): array {
		global $wpdb;

		// ORDER BY FIELD puts _sev_external_id rows first so they win the
		// dedup check below when a product has both meta keys set.
		$rows = $wpdb->get_results(
			"SELECT post_id, meta_value
			   FROM {$wpdb->postmeta}
			  WHERE meta_key IN ('_sev_external_id', '_external_id')
			  ORDER BY FIELD(meta_key, '_sev_external_id', '_external_id') ASC",
			ARRAY_A
		);

		$map = [];
		foreach ( $rows as $row ) {
			$ext_id = (string) $row['meta_value'];
			if ( $ext_id === '' ) {
				continue;
			}
			// First occurrence wins — _sev_external_id takes priority over legacy key.
			if ( ! isset( $map[ $ext_id ] ) ) {
				$map[ $ext_id ] = (int) $row['post_id'];
			}
		}

		return $map;
	}

	/**
	 * Find and permanently remove WooCommerce product posts that are duplicates
	 * of the same Shopify external ID.
	 *
	 * When a previous (broken) sync created new _sev_external_id products
	 * alongside the old plugin's _external_id products for the same Shopify ID,
	 * the extras block WC's SKU uniqueness check. This method keeps one canonical
	 * product per external ID (preferring _sev_external_id, then lowest post_id)
	 * and permanently deletes the rest.
	 *
	 * @return int  Number of duplicate products removed.
	 */
	public function delete_duplicate_products(): int {
		global $wpdb;

		// Fetch all rows ordered so _sev_external_id rows come first, then by
		// ascending post_id so the oldest surviving post wins any tiebreaker.
		$rows = $wpdb->get_results(
			"SELECT post_id, meta_value
			   FROM {$wpdb->postmeta}
			  WHERE meta_key IN ('_sev_external_id', '_external_id')
			  ORDER BY FIELD(meta_key, '_sev_external_id', '_external_id') ASC,
			           post_id ASC",
			ARRAY_A
		);

		$canonical = []; // external_id -> canonical post_id
		$to_delete = []; // [ post_id => true ]  keyed to deduplicate

		foreach ( $rows as $row ) {
			$ext_id  = (string) $row['meta_value'];
			$post_id = (int) $row['post_id'];

			if ( $ext_id === '' ) {
				continue;
			}

			if ( ! isset( $canonical[ $ext_id ] ) ) {
				$canonical[ $ext_id ] = $post_id;
			} elseif ( $canonical[ $ext_id ] !== $post_id ) {
				// Different post for the same external ID — it's a duplicate.
				$to_delete[ $post_id ] = true;
			}
			// Same post has both meta keys — no action needed.
		}

		if ( empty( $to_delete ) ) {
			return 0;
		}

		$removed = 0;
		foreach ( array_keys( $to_delete ) as $pid ) {
			$post = get_post( $pid );
			if ( ! $post || $post->post_type !== 'product' ) {
				continue;
			}
			// Permanently delete — duplicates are invisible to the user and exist
			// only because of a prior failed sync run.
			wp_delete_post( $pid, true );
			$removed++;
		}

		if ( $removed > 0 ) {
			$this->logger->info( 'Removed duplicate synced products', [ 'removed' => $removed ] );
		}

		return $removed;
	}

	// ── Attributes ─────────────────────────────────────────────────────────────

	/**
	 * Build WC_Product_Attribute objects for the vendor and spec features.
	 *
	 * @param  array $product  Internal product array.
	 * @return WC_Product_Attribute[]
	 */
	private function build_attributes( array $product ): array {
		$attributes = [];

		// Vendor / brand attribute (always first)
		$vendor = trim( $product['vendor'] ?? '' );
		if ( $vendor !== '' ) {
			$attr = $this->create_attribute( 'Producer', [ $vendor ] );
			if ( $attr ) {
				$attributes[] = $attr;
			}
		}

		// Spec feature attributes (from body_html table rows)
		foreach ( $product['features'] ?? [] as $feature ) {
			if ( empty( $feature['Name'] ) || empty( $feature['Value'] ) ) {
				continue;
			}

			$values = [];
			foreach ( (array) $feature['Value'] as $v ) {
				if ( ! empty( $v['Name'] ) ) {
					$values[] = $v['Name'];
				}
			}

			if ( ! empty( $values ) ) {
				$attr = $this->create_attribute( $feature['Name'], $values );
				if ( $attr ) {
					$attributes[] = $attr;
				}
			}
		}

		return $attributes;
	}

	/**
	 * Create a WC_Product_Attribute backed by a global WC attribute taxonomy.
	 *
	 * Falls back to a local (non-taxonomy) custom attribute when the global
	 * attribute cannot be registered (e.g. DB error). Local attributes are
	 * still visible on the product page but are not filterable in WC layered nav.
	 *
	 * @param  string   $label   Human-readable label, e.g. "Nennleistung (kVA)".
	 * @param  string[] $values  Attribute values.
	 * @return WC_Product_Attribute|null
	 */
	private function create_attribute( string $label, array $values ) {
		$slug     = $this->make_attribute_slug( $label );
		$taxonomy = 'pa_' . $slug;

		$attr_id = $this->get_or_create_global_attribute( $label, $slug );

		if ( ! $attr_id ) {
			// Fallback: local attribute — visible but not taxonomy-backed.
			$attr = new WC_Product_Attribute();
			$attr->set_name( $label );
			$attr->set_options( $values );
			$attr->set_visible( true );
			$attr->set_variation( false );
			return $attr;
		}

		// Ensure taxonomy terms exist
		$term_ids = [];
		foreach ( $values as $value ) {
			$term = get_term_by( 'name', $value, $taxonomy );
			if ( ! $term ) {
				$result = wp_insert_term( $value, $taxonomy );
				if ( ! is_wp_error( $result ) ) {
					$term_ids[] = (int) $result['term_id'];
				}
			} else {
				$term_ids[] = (int) $term->term_id;
			}
		}

		$attr = new WC_Product_Attribute();
		$attr->set_id( $attr_id );
		$attr->set_name( $taxonomy );
		$attr->set_options( $term_ids );
		$attr->set_visible( true );
		$attr->set_variation( false );

		return $attr;
	}

	/**
	 * Get or create a WooCommerce global attribute taxonomy.
	 *
	 * @param  string $label  Human-readable name.
	 * @param  string $slug   Sanitised slug (max 28 chars, no pa_ prefix).
	 * @return int|false      Attribute ID, or false on failure.
	 */
	private function get_or_create_global_attribute( string $label, string $slug ) {
		if ( isset( $this->attribute_cache[ $slug ] ) ) {
			return $this->attribute_cache[ $slug ];
		}

		// Already exists?
		$attr_id = wc_attribute_taxonomy_id_by_name( $slug );
		if ( $attr_id ) {
			$this->attribute_cache[ $slug ] = $attr_id;
			return $attr_id;
		}

		// Create it
		$attr_id = wc_create_attribute( [
			'name'         => $label,
			'slug'         => $slug,
			'type'         => 'select',
			'order_by'     => 'menu_order',
			'has_archives' => false,
		] );

		if ( is_wp_error( $attr_id ) ) {
			$this->logger->warning( 'Could not create global attribute', [
				'label' => $label,
				'slug'  => $slug,
				'error' => $attr_id->get_error_message(),
			] );
			return false;
		}

		// Register the taxonomy for the current request
		register_taxonomy( 'pa_' . $slug, 'product', [
			'labels'       => [ 'name' => $label ],
			'hierarchical' => false,
			'show_ui'      => false,
			'query_var'    => true,
			'rewrite'      => false,
		] );

		$this->attribute_cache[ $slug ] = $attr_id;
		$this->logger->info( 'Created global attribute', [ 'label' => $label, 'slug' => $slug ] );

		return $attr_id;
	}

	// ── Categories ──────────────────────────────────────────────────────────────

	/**
	 * Get or create WooCommerce product_cat terms for a hierarchical category path.
	 *
	 * Path format: "Parent - Child" or just "Parent".
	 * Separator " - " (with spaces) is used by the transformer's CATEGORY_MAP.
	 *
	 * @param  string $category_path  e.g. "Stromerzeuger - Portable Generators"
	 * @return int[]                  Term IDs for all levels (parent first).
	 */
	private function get_or_create_categories( string $category_path ): array {
		if ( isset( $this->category_cache[ $category_path ] ) ) {
			return $this->category_cache[ $category_path ];
		}

		$parts     = array_map( 'trim', explode( ' - ', $category_path ) );
		$parent_id = 0;
		$term_ids  = [];

		foreach ( $parts as $name ) {
			if ( $name === '' ) {
				continue;
			}

			// Find existing term with the correct parent
			$term = get_term_by( 'name', $name, 'product_cat' );
			if ( $term && (int) $term->parent === $parent_id ) {
				$term_ids[] = $term->term_id;
				$parent_id  = $term->term_id;
				continue;
			}

			// Create new term
			$result = wp_insert_term( $name, 'product_cat', [ 'parent' => $parent_id ] );
			if ( is_wp_error( $result ) ) {
				if ( $result->get_error_code() === 'term_exists' ) {
					$existing = get_term_by( 'name', $name, 'product_cat' );
					if ( $existing ) {
						$term_ids[] = $existing->term_id;
						$parent_id  = $existing->term_id;
					}
				} else {
					$this->logger->warning( 'Could not create category', [
						'name'  => $name,
						'error' => $result->get_error_message(),
					] );
				}
			} else {
				$term_ids[] = $result['term_id'];
				$parent_id  = $result['term_id'];
				$this->logger->info( 'Created category', [ 'name' => $name ] );
			}
		}

		$this->category_cache[ $category_path ] = $term_ids;
		return $term_ids;
	}

	// ── Images ─────────────────────────────────────────────────────────────────

	/**
	 * Sideload product images into the WP media library.
	 *
	 * - Deduplication: images already present (by _sev_source_url) are reused.
	 * - First image → featured thumbnail.
	 * - Remaining images → product gallery (_product_image_gallery).
	 *
	 * @param int      $product_id  WC product post ID.
	 * @param string[] $photos      Array of image URLs (CDN query string already stripped).
	 */
	private function handle_photos( int $product_id, array $photos ): void {
		$attachment_ids = [];

		foreach ( $photos as $url ) {
			if ( $url === '' ) {
				continue;
			}

			// Reuse an already-sideloaded image if we have it
			$att_id = $this->get_attachment_by_url( $url );
			if ( ! $att_id ) {
				$att_id = $this->download_image( $url, $product_id );
			}

			if ( $att_id ) {
				$attachment_ids[] = $att_id;
			}
		}

		if ( empty( $attachment_ids ) ) {
			return;
		}

		set_post_thumbnail( $product_id, $attachment_ids[0] );

		if ( count( $attachment_ids ) > 1 ) {
			update_post_meta(
				$product_id,
				'_product_image_gallery',
				implode( ',', array_slice( $attachment_ids, 1 ) )
			);
		}
	}

	/**
	 * Find an existing WP media attachment by its original source URL.
	 *
	 * @param  string   $url  Source image URL.
	 * @return int|false      Attachment post ID, or false if not found.
	 */
	private function get_attachment_by_url( string $url ) {
		global $wpdb;

		$att_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT post_id
			   FROM {$wpdb->postmeta}
			  WHERE meta_key = '_sev_source_url'
			    AND meta_value = %s
			  LIMIT 1",
			$url
		) );

		return $att_id ? (int) $att_id : false;
	}

	/**
	 * Download a remote image URL and sideload it into the WP media library.
	 *
	 * @param  string   $url         Image URL.
	 * @param  int      $product_id  Parent product post ID (for attachment parent).
	 * @return int|false             Attachment post ID on success, false on failure.
	 */
	private function download_image( string $url, int $product_id ) {
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url( $url, 60 );

		if ( is_wp_error( $tmp ) ) {
			$this->logger->warning( 'Could not download image', [
				'url'   => $url,
				'error' => $tmp->get_error_message(),
			] );
			return false;
		}

		// Derive a sane filename from the URL path (query string already absent
		// because SEV_Transformer strips it with strtok).
		$url_path = parse_url( $url, PHP_URL_PATH ) ?? '';
		$filename = basename( $url_path );
		if ( $filename === '' ) {
			$filename = 'product-' . $product_id . '-' . uniqid() . '.jpg';
		}

		$file_array = [
			'name'     => $filename,
			'tmp_name' => $tmp,
		];

		$att_id = media_handle_sideload( $file_array, $product_id );

		if ( is_wp_error( $att_id ) ) {
			@unlink( $tmp );
			$this->logger->warning( 'Could not sideload image', [
				'url'   => $url,
				'error' => $att_id->get_error_message(),
			] );
			return false;
		}

		// Tag the attachment with its source URL so we can deduplicate on future syncs.
		update_post_meta( $att_id, '_sev_source_url', $url );

		return $att_id;
	}

	// ── Helpers ────────────────────────────────────────────────────────────────

	/**
	 * Build a WC-compatible attribute slug capped at 28 characters.
	 *
	 * Uses wc_sanitize_taxonomy_name() for the initial pass (handles umlauts
	 * via iconv transliteration), then normalises separators and trims to 28.
	 *
	 * @param  string $label  Human-readable label, e.g. "Nennleistung (kVA)".
	 * @return string         Slug,                 e.g. "nennleistung_kva".
	 */
	private function make_attribute_slug( string $label ): string {
		// WC's own sanitiser handles transliteration + lowercasing
		$slug = wc_sanitize_taxonomy_name( $label );

		// Normalise separators to underscores (WC uses hyphens by default)
		$slug = str_replace( '-', '_', $slug );
		$slug = preg_replace( '/_+/', '_', $slug );
		$slug = trim( $slug, '_' );

		// Enforce the 28-char hard limit
		if ( strlen( $slug ) > 28 ) {
			$truncated = substr( $slug, 0, 28 );
			$last_sep  = strrpos( $truncated, '_' );
			// Prefer a clean word boundary if one exists in the last 10 chars
			if ( $last_sep !== false && $last_sep > 18 ) {
				$truncated = substr( $truncated, 0, $last_sep );
			}
			$slug = rtrim( $truncated, '_' );
		}

		return $slug;
	}
}
