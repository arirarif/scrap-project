<?php
/**
 * Transformer — maps raw Shopify product data to the internal product format.
 *
 * PHP port of sev-scraper/transformer.py + category_map.py.
 *
 * Internal format produced:
 * [
 *   'id'           => '9270157705563',
 *   'name'         => 'Product Title',
 *   'vendor'       => 'Honda',           // brand / producer
 *   'sku'          => 'SEV-1234',        // '' if Shopify has none
 *   'price'        => 29.16,
 *   'stock_status' => 'instock',         // 'instock' | 'outofstock'
 *   'stock_qty'    => null,              // int if > 0, null otherwise
 *   'photos'       => ['https://...'],
 *   'category'     => 'Stromerzeuger - Portable Generators',
 *   'features'     => [ ['Id'=>..., 'Name'=>..., 'Value'=>[['Name'=>...]]], ... ],
 * ]
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SEV_Transformer {

	/** @var SEV_Html_Parser */
	private $parser;

	public function __construct() {
		$this->parser = new SEV_Html_Parser();
	}

	// ── Public API ─────────────────────────────────────────────────────────────

	/**
	 * Transform a single raw Shopify product to internal format.
	 *
	 * @param  array $p  Raw product object from Shopify JSON API.
	 * @return array     Internal product array.
	 */
	public function transform( array $p ): array {
		$id        = (string) ( $p['id'] ?? '' );
		$name      = trim( $p['title'] ?? '' );
		$vendor    = trim( $p['vendor'] ?? '' );
		$body_html = $p['body_html'] ?? '';

		// ── Variant fields (always use first variant) ──────────────────────────
		$variants = $p['variants'] ?? [];
		$variant  = $variants[0] ?? [];

		$sku_raw      = trim( $variant['sku'] ?? '' );
		$price_raw    = $variant['price'] ?? '0';
		$available    = (bool) ( $variant['available'] ?? false );
		$inv_qty      = (int) ( $variant['inventory_quantity'] ?? 0 );

		// SKU: use Shopify SKU if non-empty; otherwise leave blank.
		// Never generate a synthetic SKU from the product ID.
		$sku = $sku_raw !== '' ? $sku_raw : '';

		// Price: Shopify returns a string like "29.16"
		$price = (float) $price_raw;

		// Stock: use the `available` boolean as the reliable source.
		// inventory_quantity is unreliable on the public API without auth.
		$stock_status = $available ? 'instock' : 'outofstock';
		$stock_qty    = $inv_qty > 0 ? $inv_qty : null;

		// ── Photos ─────────────────────────────────────────────────────────────
		// Strip Shopify CDN versioning query string (?v=XXXXXXX) from URLs.
		$photos = [];
		foreach ( $p['images'] ?? [] as $img ) {
			$src = $img['src'] ?? '';
			if ( $src !== '' ) {
				$photos[] = strtok( $src, '?' ); // everything before '?'
			}
		}
		// Reset strtok internal pointer
		strtok( '', '' );

		// ── Category ───────────────────────────────────────────────────────────
		$product_type = trim( $p['product_type'] ?? '' );
		$category     = $this->get_category( $product_type );

		// ── Features (spec table rows from body_html) ──────────────────────────
		$features = $this->parser->parse_features( (string) $body_html );

		return [
			'id'           => $id,
			'name'         => $name,
			'vendor'       => $vendor,
			'sku'          => $sku,
			'price'        => $price,
			'stock_status' => $stock_status,
			'stock_qty'    => $stock_qty,
			'photos'       => $photos,
			'category'     => $category,
			'features'     => $features,
		];
	}

	/**
	 * Transform all raw Shopify products.
	 * Skips products with no ID and logs zero-price warnings.
	 *
	 * @param  array        $shopify_products  Flat array of raw Shopify products.
	 * @return array                           Internal products keyed by product ID string.
	 */
	public function transform_all( array $shopify_products ): array {
		$logger  = new SEV_Logger();
		$results = [];
		$errors  = 0;

		foreach ( $shopify_products as $raw ) {
			try {
				$id = (string) ( $raw['id'] ?? '' );
				if ( $id === '' ) {
					continue;
				}

				$product = $this->transform( $raw );

				if ( $product['price'] === 0.0 ) {
					$logger->warning( 'Zero price product', [
						'id'   => $id,
						'name' => mb_substr( $product['name'], 0, 60, 'UTF-8' ),
					] );
				}

				$results[ $id ] = $product;

			} catch ( Exception $e ) {
				$errors++;
				$logger->error( 'Failed to transform product', [
					'id'    => $raw['id'] ?? '?',
					'error' => $e->getMessage(),
				] );
			}
		}

		$logger->info( 'Transform complete', [
			'total'  => count( $results ),
			'errors' => $errors,
		] );

		return $results;
	}

	// ── Category mapping ───────────────────────────────────────────────────────

	/**
	 * Map a Shopify product_type string to a WooCommerce category path.
	 *
	 * Lookup order:
	 *  1. Direct case-sensitive match
	 *  2. Case-insensitive match
	 *  3. Fallback: return the raw product_type as-is (creates a top-level category)
	 *
	 * @param  string $product_type  Shopify product_type value.
	 * @return string                WC category path, e.g. "Stromerzeuger - Portable Generators".
	 */
	public function get_category( string $product_type ): string {
		if ( $product_type === '' ) {
			return 'Uncategorized';
		}

		// Direct match
		if ( isset( self::CATEGORY_MAP[ $product_type ] ) ) {
			return self::CATEGORY_MAP[ $product_type ];
		}

		// Case-insensitive fallback
		$lower = mb_strtolower( $product_type, 'UTF-8' );
		foreach ( self::CATEGORY_MAP as $key => $value ) {
			if ( mb_strtolower( $key, 'UTF-8' ) === $lower ) {
				return $value;
			}
		}

		// Unknown type — use as-is (plugin will create it as a top-level category)
		return $product_type;
	}

	// ── Category map (47 entries — port of category_map.py) ───────────────────

	/**
	 * Maps Shopify product_type → WooCommerce category path.
	 * Separator " - " is used by SEV_Product_Mapper to build hierarchy.
	 */
	private const CATEGORY_MAP = [

		// ── Generators (Stromerzeuger) ─────────────────────────────────────────
		'Stromerzeuger'              => 'Stromerzeuger',
		'Benzin Generator'           => 'Stromerzeuger - Portable Generators',
		'Benzingenerator'            => 'Stromerzeuger - Portable Generators',
		'Tragbarer Stromerzeuger'    => 'Stromerzeuger - Portable Generators',
		'Diesel Generator'           => 'Stromerzeuger - Portable Generators',
		'Dieselaggregat'             => 'Stromerzeuger - Portable Generators',
		'Dieselgenerator'            => 'Stromerzeuger - Portable Generators',
		'Inverter Generator'         => 'Stromerzeuger - Inverter Generators',
		'Invertergenerator'          => 'Stromerzeuger - Inverter Generators',
		'Inverter'                   => 'Stromerzeuger - Inverter Generators',
		'Notstromaggregat'           => 'Stromerzeuger - Emergency Power',
		'Notstromerzeuger'           => 'Stromerzeuger - Emergency Power',
		'Zapfwellenaggregat'         => 'Stromerzeuger - PTO Generators',
		'Zapfwellengenerator'        => 'Stromerzeuger - PTO Generators',
		'Lichtmast'                  => 'Stromerzeuger - Light Towers',
		'Lichtturm'                  => 'Stromerzeuger - Light Towers',
		'Baustrahler'                => 'Stromerzeuger - Construction Lights',
		'Baustellenstrahler'         => 'Stromerzeuger - Construction Lights',
		'Schweißgenerator'           => 'Stromerzeuger - Welding Generators',
		'Schweissgenerator'          => 'Stromerzeuger - Welding Generators',
		'Schweißaggregat'            => 'Stromerzeuger - Welding Generators',
		'Gas Generator'              => 'Stromerzeuger - Gas Generators',
		'Gasgenerator'               => 'Stromerzeuger - Gas Generators',
		'Solar Generator'            => 'Stromerzeuger - Gas Generators',
		'Hybrid Generator'           => 'Stromerzeuger - Hybrid Generators',
		'Hybridgenerator'            => 'Stromerzeuger - Hybrid Generators',
		'Motorpumpe'                 => 'Stromerzeuger - Motor Pumps',
		'Motor Pumpe'                => 'Stromerzeuger - Motor Pumps',
		'Pumpe'                      => 'Stromerzeuger - Motor Pumps',
		'Hochdruckreiniger'          => 'Stromerzeuger - Motor Pumps',

		// ── Energy Storage (Energiespeicher) ───────────────────────────────────
		'Energiespeicher'            => 'Energy Storage',
		'Gewerbespeicher'            => 'Energy Storage - Commercial Storage',
		'Solarspeicher'              => 'Energy Storage - Solar Storage',
		'Solarstromspeicher'         => 'Energy Storage - Solar Storage',
		'Wechselrichter'             => 'Energy Storage - Solar Storage',
		'Powerstation'               => 'Energy Storage - Portable Power Stations',
		'Powerstations'              => 'Energy Storage - Portable Power Stations',
		'Portable Powerstation'      => 'Energy Storage - Portable Power Stations',
		'Balkonkraftwerk'            => 'Energy Storage - Balcony Solar',
		'Balkon Kraftwerk'           => 'Energy Storage - Balcony Solar',
		'Solaranlage'                => 'Energy Storage - Balcony Solar',
		'Solarpaneele'               => 'Energy Storage - Balcony Solar',
		'Wallbox'                    => 'Energy Storage - Wallbox & EV Charging',

		// ── Accessories (Zubehör) ──────────────────────────────────────────────
		'Zubehör'                    => 'Accessories',
		'Zubehoer'                   => 'Accessories',
		'Tank'                       => 'Accessories - Tanks & Canisters',
		'Tanks'                      => 'Accessories - Tanks & Canisters',
		'Kanister'                   => 'Accessories - Tanks & Canisters',
		'Trichter'                   => 'Accessories - Tanks & Canisters',
		'Öl'                         => 'Accessories - Oils & Additives',
		'Motoröl'                    => 'Accessories - Oils & Additives',
		'Ölfilter'                   => 'Accessories - Oils & Additives',
		'Luftfilter'                 => 'Accessories - Oils & Additives',
		'Kraftstofffilter'           => 'Accessories - Oils & Additives',
		'Additiv'                    => 'Accessories - Oils & Additives',
		'Spezial Benzin'             => 'Accessories - Oils & Additives',
		'Spezial Diesel'             => 'Accessories - Oils & Additives',
		'Spezial Zweitaktkraftstoff' => 'Accessories - Oils & Additives',
		'Wartungssatz'               => 'Accessories - Oils & Additives',
		'Kabel'                      => 'Accessories - Cables',
		'Verlängerungskabel'         => 'Accessories - Cables',
		'Stromverteiler'             => 'Accessories - Power Distributors',
		'Verteiler'                  => 'Accessories - Power Distributors',
		'Netztrennschalter'          => 'Accessories - Power Distributors',
		'Transferschalter'           => 'Accessories - Power Distributors',
		'Notstromautomatik'          => 'Accessories - Power Distributors',
		'Batterieladegerät'          => 'Accessories - Battery Chargers',
		'Ladegerät'                  => 'Accessories - Battery Chargers',
		'Solarpanel'                 => 'Accessories - Solar Panels',
		'Solarmodul'                 => 'Accessories - Solar Panels',
		'Sicherheitsausrüstung'      => 'Accessories - Safety Equipment',
		'Radsatz'                    => 'Accessories - Spare Parts',
		'Erdspieß'                   => 'Accessories - Spare Parts',
		'Hebeöse'                    => 'Accessories - Spare Parts',
		'Lastwiderstand'             => 'Accessories - Spare Parts',
		'Parallel Kit'               => 'Accessories - Spare Parts',
		'Kommunikationsmodul'        => 'Accessories - Spare Parts',
		'Fernbedienung'              => 'Accessories - Spare Parts',
		'Fernstartmodul'             => 'Accessories - Spare Parts',
		'Etiketten'                  => 'Accessories - Spare Parts',
	];
}
