<?php
/**
 * Plugin Name: SEV WC Product Sync
 * Description: Automatically scrapes sev-stromerzeuger.com and syncs products to WooCommerce. No JSON files, no external servers.
 * Version:     1.0.0
 * License:     GPL v2 or later
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 9.0
 * Text Domain: sev-wc-sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SEV_VERSION',         '1.0.0' );
define( 'SEV_PLUGIN_DIR',      plugin_dir_path( __FILE__ ) );
define( 'SEV_PLUGIN_URL',      plugin_dir_url( __FILE__ ) );
define( 'SEV_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Main plugin class (singleton).
 */
final class SEV_WC_Sync {

	/** @var self|null */
	private static $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->includes();
		$this->init_hooks();
	}

	// ── Class loading ──────────────────────────────────────────────────────────

	private function includes(): void {
		require_once SEV_PLUGIN_DIR . 'includes/class-sev-logger.php';
		require_once SEV_PLUGIN_DIR . 'includes/class-sev-api-client.php';
		require_once SEV_PLUGIN_DIR . 'includes/class-sev-html-parser.php';
		require_once SEV_PLUGIN_DIR . 'includes/class-sev-transformer.php';
		require_once SEV_PLUGIN_DIR . 'includes/class-sev-state-manager.php';
		require_once SEV_PLUGIN_DIR . 'includes/class-sev-product-mapper.php';
		require_once SEV_PLUGIN_DIR . 'includes/class-sev-sync-handler.php';
		require_once SEV_PLUGIN_DIR . 'includes/class-sev-cron.php';
		require_once SEV_PLUGIN_DIR . 'includes/class-sev-admin.php';
	}

	// ── Hook registration ──────────────────────────────────────────────────────

	private function init_hooks(): void {
		register_activation_hook( __FILE__,   [ $this, 'activate' ] );
		register_deactivation_hook( __FILE__,  [ $this, 'deactivate' ] );

		add_action( 'plugins_loaded', [ $this, 'init_components' ] );

		// AJAX — logged-in admins only (no nopriv needed)
		add_action( 'wp_ajax_sev_init_batch',    [ $this, 'ajax_init_batch' ] );
		add_action( 'wp_ajax_sev_process_batch', [ $this, 'ajax_process_batch' ] );
		add_action( 'wp_ajax_sev_reset_cache',   [ $this, 'ajax_reset_cache' ] );
		add_action( 'wp_ajax_sev_get_status',    [ $this, 'ajax_get_status' ] );
		add_action( 'wp_ajax_sev_trash_all',     [ $this, 'ajax_trash_all' ] );
		add_action( 'wp_ajax_sev_empty_trash',   [ $this, 'ajax_empty_trash' ] );
	}

	public function init_components(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', static function () {
				echo '<div class="error"><p><strong>SEV WC Product Sync</strong> requires WooCommerce to be installed and active.</p></div>';
			} );
			return;
		}

		SEV_Admin::get_instance();
		SEV_Cron::get_instance();
	}

	// ── Lifecycle ──────────────────────────────────────────────────────────────

	public function activate(): void {
		// Create protected log and cache directories.
		foreach ( [ 'logs', 'cache' ] as $dir ) {
			$path = SEV_PLUGIN_DIR . $dir;
			if ( ! file_exists( $path ) ) {
				wp_mkdir_p( $path );
				file_put_contents( $path . '/.htaccess', 'deny from all' );
				file_put_contents( $path . '/index.php', '<?php // Silence is golden' );
			}
		}

		// Set default options (only if not already set).
		$defaults = [
			'sev_enabled'         => 'no',
			'sev_sync_interval'   => '8',
			'sev_delete_behavior' => 'trash',
			'sev_source_url'      => 'https://sev-stromerzeuger.com',
		];
		foreach ( $defaults as $key => $value ) {
			if ( get_option( $key ) === false ) {
				update_option( $key, $value );
			}
		}

		flush_rewrite_rules();
	}

	public function deactivate(): void {
		wp_clear_scheduled_hook( 'sev_sync_cron' );
		flush_rewrite_rules();
	}

	// ── AJAX handlers ──────────────────────────────────────────────────────────

	/**
	 * Init batch: fetch all Shopify pages and store in transient.
	 * Called once when user clicks "Sync Now".
	 */
	public function ajax_init_batch(): void {
		check_ajax_referer( 'sev_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => 'Permission denied.' ] );
		}

		$handler = new SEV_Sync_Handler();
		$result  = $handler->init_batch();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Process batch: transform + import the next N products.
	 * Called repeatedly until status === 'complete'.
	 */
	public function ajax_process_batch(): void {
		check_ajax_referer( 'sev_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => 'Permission denied.' ] );
		}

		$handler = new SEV_Sync_Handler();
		$result  = $handler->process_batch();

		wp_send_json_success( $result );
	}

	/**
	 * Reset cache: delete all stored product hashes.
	 * Forces a full re-import on the next sync.
	 */
	public function ajax_reset_cache(): void {
		check_ajax_referer( 'sev_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => 'Permission denied.' ] );
		}

		SEV_Sync_Handler::reset_batch();

		// Remove all stored hashes so every product is re-imported.
		global $wpdb;
		$wpdb->delete( $wpdb->postmeta, [ 'meta_key' => '_sev_hash' ] );

		wp_send_json_success( [ 'message' => 'Sync cache cleared. Next sync will re-import all products.' ] );
	}

	/**
	 * Trash all: move every plugin-managed WC product to the trash.
	 * Also clears stored hashes so the next sync re-imports everything fresh.
	 */
	public function ajax_trash_all(): void {
		check_ajax_referer( 'sev_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => 'Permission denied.' ] );
		}

		global $wpdb;

		// Query products managed by this plugin (_sev_external_id) AND products
		// that were imported by the previous plugin version (_external_id, _wcps_synced).
		// This ensures the button works even on a fresh install where no sync has run yet.
		$product_ids = $wpdb->get_col(
			"SELECT DISTINCT post_id
			   FROM {$wpdb->postmeta}
			  WHERE meta_key IN ('_sev_external_id', '_external_id', '_wcps_synced')"
		);

		$trashed = 0;
		foreach ( $product_ids as $pid ) {
			$pid  = (int) $pid;
			$post = get_post( $pid );
			// Skip posts that are already trashed or not a product.
			if ( ! $post || $post->post_status === 'trash' || $post->post_type !== 'product' ) {
				continue;
			}
			wp_trash_post( $pid );
			$trashed++;
		}

		// Clear hashes and any in-progress batch state.
		$wpdb->delete( $wpdb->postmeta, [ 'meta_key' => '_sev_hash' ] );
		SEV_Sync_Handler::reset_batch();

		// Wipe sync stats so the dashboard doesn't show stale numbers.
		delete_option( 'sev_last_sync' );
		delete_option( 'sev_last_sync_stats' );

		$logger = new SEV_Logger();
		$logger->info( 'Trash all executed', [ 'trashed' => $trashed ] );

		wp_send_json_success( [
			'message' => sprintf( 'Moved %d products to trash. Run Sync Now to re-import everything.', $trashed ),
			'trashed' => $trashed,
		] );
	}

	/**
	 * Empty trash: permanently delete all trashed plugin-managed products.
	 * Only targets products already in the trash (post_status = 'trash').
	 * Products from both old and new plugin versions are covered.
	 */
	public function ajax_empty_trash(): void {
		check_ajax_referer( 'sev_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => 'Permission denied.' ] );
		}

		global $wpdb;

		// Only permanently delete products that are already in the trash
		// AND were managed by a sync plugin (recognised meta keys).
		$product_ids = $wpdb->get_col(
			"SELECT DISTINCT p.ID
			   FROM {$wpdb->posts} p
			   INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
			  WHERE p.post_status = 'trash'
			    AND p.post_type   = 'product'
			    AND pm.meta_key IN ('_sev_external_id', '_external_id', '_wcps_synced')"
		);

		if ( empty( $product_ids ) ) {
			wp_send_json_success( [
				'message' => 'No trashed synced products found.',
				'deleted' => 0,
			] );
		}

		$deleted = 0;
		$errors  = 0;
		foreach ( $product_ids as $pid ) {
			$result = wp_delete_post( (int) $pid, true ); // true = force permanent delete
			if ( $result ) {
				$deleted++;
			} else {
				$errors++;
			}
		}

		$logger = new SEV_Logger();
		$logger->info( 'Empty trash executed', [ 'deleted' => $deleted, 'errors' => $errors ] );

		wp_send_json_success( [
			'message' => sprintf( 'Permanently deleted %d products.%s', $deleted, $errors ? " ($errors errors)" : '' ),
			'deleted' => $deleted,
			'errors'  => $errors,
		] );
	}

	/**
	 * Get status: return last sync stats and current batch state.
	 */
	public function ajax_get_status(): void {
		check_ajax_referer( 'sev_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => 'Permission denied.' ] );
		}

		wp_send_json_success( [
			'last_sync'   => get_option( 'sev_last_sync', '' ),
			'last_stats'  => get_option( 'sev_last_sync_stats', [] ),
			'batch_state' => SEV_Sync_Handler::get_batch_state(),
		] );
	}
}

// Boot.
add_action( 'plugins_loaded', static function () {
	SEV_WC_Sync::get_instance();
}, 0 );
