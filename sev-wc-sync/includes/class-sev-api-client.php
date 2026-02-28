<?php
/**
 * API Client — fetches all products from the Shopify public JSON API.
 *
 * Endpoint: GET /collections/all/products.json?limit=250&page=N
 * Paginates until the API returns an empty products array.
 * No authentication required (public Shopify store API).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SEV_Api_Client {

	/** @var SEV_Logger */
	private $logger;

	/** @var string Base URL of the source Shopify store */
	private $base_url;

	public function __construct() {
		$this->logger   = new SEV_Logger();
		$this->base_url = rtrim( get_option( 'sev_source_url', 'https://sev-stromerzeuger.com' ), '/' );
	}

	// ── Public API ─────────────────────────────────────────────────────────────

	/**
	 * Fetch ALL products from the Shopify store (all pages).
	 *
	 * @return array Flat array of raw Shopify product objects.
	 *               Returns an empty array on total failure.
	 */
	public function fetch_all(): array {
		$all_products = [];
		$page         = 1;

		$this->logger->info( 'Starting Shopify API fetch', [ 'base_url' => $this->base_url ] );

		while ( true ) {
			$result = $this->fetch_page( $page );

			if ( is_wp_error( $result ) ) {
				// Log and stop — use whatever we fetched so far.
				$this->logger->error( 'Stopping pagination due to error', [
					'page'    => $page,
					'fetched' => count( $all_products ),
					'error'   => $result->get_error_message(),
				] );
				break;
			}

			$count        = count( $result );
			$all_products = array_merge( $all_products, $result );

			$this->logger->info( "Fetched page $page", [
				'count'         => $count,
				'running_total' => count( $all_products ),
			] );

			// Stop if this page had fewer products than the limit (last page)
			// or if the page was completely empty.
			if ( $count < 250 ) {
				break;
			}

			$page++;
		}

		$this->logger->info( 'API fetch complete', [ 'total' => count( $all_products ) ] );

		return $all_products;
	}

	// ── Internal ───────────────────────────────────────────────────────────────

	/**
	 * Fetch a single page from the API.
	 *
	 * @param  int          $page  Page number (1-based).
	 * @return array|WP_Error      Array of products or WP_Error.
	 */
	private function fetch_page( int $page ) {
		$url = $this->base_url
			. '/collections/all/products.json'
			. '?limit=250&page=' . $page;

		$response = wp_remote_get( $url, [
			'timeout' => 30,
			'headers' => [
				'Accept'     => 'application/json',
				'User-Agent' => 'Mozilla/5.0 (compatible; WC-SEV-Sync/' . SEV_VERSION . ')',
			],
		] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );

		if ( $status !== 200 ) {
			return new WP_Error(
				'api_error',
				"Shopify API returned HTTP $status on page $page"
			);
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new WP_Error(
				'json_error',
				'Failed to decode Shopify API response: ' . json_last_error_msg()
			);
		}

		return $data['products'] ?? [];
	}
}
