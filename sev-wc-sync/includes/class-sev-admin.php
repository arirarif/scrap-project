<?php
/**
 * Admin — registers the WooCommerce → SEV Product Sync admin page.
 *
 * Three tabs:
 *   Sync      — manual "Sync Now" button, live progress bar, last sync stats.
 *   Settings  — Enable, Interval, Delete Behavior, Source URL.
 *   Logs      — view today's log file content.
 *
 * Asset handle: sev-admin (JS + CSS, only enqueued on our page).
 * JS global:    window.sevSync = { ajaxUrl, nonce }
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SEV_Admin {

	/** @var self|null */
	private static $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu',             [ $this, 'add_menu_page' ] );
		add_action( 'admin_enqueue_scripts',  [ $this, 'enqueue_assets' ] );
		add_action( 'admin_init',             [ $this, 'handle_settings_save' ] );
	}

	// ── Menu registration ──────────────────────────────────────────────────────

	public function add_menu_page(): void {
		add_submenu_page(
			'woocommerce',
			'SEV Product Sync',
			'SEV Product Sync',
			'manage_woocommerce',
			'sev-wc-sync',
			[ $this, 'render_page' ]
		);
	}

	// ── Asset enqueue ─────────────────────────────────────────────────────────

	public function enqueue_assets( string $hook ): void {
		// Only load on our admin page.
		if ( $hook !== 'woocommerce_page_sev-wc-sync' ) {
			return;
		}

		wp_enqueue_style(
			'sev-admin',
			SEV_PLUGIN_URL . 'assets/css/admin.css',
			[],
			SEV_VERSION
		);

		wp_enqueue_script(
			'sev-admin',
			SEV_PLUGIN_URL . 'assets/js/admin.js',
			[ 'jquery' ],
			SEV_VERSION,
			true // load in footer
		);

		wp_localize_script( 'sev-admin', 'sevSync', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'sev_admin_nonce' ),
		] );
	}

	// ── Settings save ─────────────────────────────────────────────────────────

	/**
	 * Handle the settings form POST (PRG pattern — redirect after save).
	 */
	public function handle_settings_save(): void {
		if ( ! isset( $_POST['sev_save_settings'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'sev-wc-sync' ) );
		}

		check_admin_referer( 'sev_settings_save' );

		$enabled  = isset( $_POST['sev_enabled'] ) ? 'yes' : 'no';
		$interval = sanitize_text_field( $_POST['sev_sync_interval'] ?? '8' );
		$delete   = sanitize_text_field( $_POST['sev_delete_behavior'] ?? 'trash' );

		// Validate interval (only allow known values).
		if ( ! in_array( $interval, [ '6', '8', '12', '24' ], true ) ) {
			$interval = '8';
		}
		// Validate delete behaviour.
		if ( ! in_array( $delete, [ 'trash', 'delete' ], true ) ) {
			$delete = 'trash';
		}

		update_option( 'sev_enabled',         $enabled );
		update_option( 'sev_sync_interval',   $interval );
		update_option( 'sev_delete_behavior', $delete );
		// sev_source_url is not exposed in the UI — change it directly in the database.

		// Clear the scheduled cron event so maybe_schedule() can re-create it
		// with the new interval on the next request's init hook.
		wp_clear_scheduled_hook( 'sev_sync_cron' );

		wp_safe_redirect(
			add_query_arg( [ 'page' => 'sev-wc-sync', 'tab' => 'settings', 'saved' => '1' ], admin_url( 'admin.php' ) )
		);
		exit;
	}

	// ── Page render ───────────────────────────────────────────────────────────

	public function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$active_tab = sanitize_key( $_GET['tab'] ?? 'sync' );
		if ( ! in_array( $active_tab, [ 'sync', 'settings', 'logs' ], true ) ) {
			$active_tab = 'sync';
		}

		?>
		<div class="wrap sev-admin-wrap">

			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<nav class="nav-tab-wrapper">
				<?php
				$tabs = [
					'sync'     => 'Sync',
					'settings' => 'Settings',
					'logs'     => 'Logs',
				];
				foreach ( $tabs as $slug => $label ) {
					$url   = add_query_arg( [ 'page' => 'sev-wc-sync', 'tab' => $slug ], admin_url( 'admin.php' ) );
					$class = $active_tab === $slug ? 'nav-tab nav-tab-active' : 'nav-tab';
					printf(
						'<a href="%s" class="%s">%s</a>',
						esc_url( $url ),
						esc_attr( $class ),
						esc_html( $label )
					);
				}
				?>
			</nav>

			<div class="sev-tab-content">
				<?php
				switch ( $active_tab ) {
					case 'settings':
						$this->render_settings_tab();
						break;
					case 'logs':
						$this->render_logs_tab();
						break;
					default:
						$this->render_sync_tab();
						break;
				}
				?>
			</div>

		</div>
		<?php
	}

	// ── Sync tab ──────────────────────────────────────────────────────────────

	private function render_sync_tab(): void {
		$last_sync  = get_option( 'sev_last_sync', '' );
		$last_stats = get_option( 'sev_last_sync_stats', [] );
		$enabled    = get_option( 'sev_enabled', 'no' ) === 'yes';
		?>

		<div class="sev-last-sync-info">
			<p>
				<strong>Last sync:</strong>
				<?php echo $last_sync ? esc_html( $last_sync ) : '<em>Never run</em>'; ?>
			</p>

			<?php if ( ! empty( $last_stats ) ) : ?>
			<p>
				Created: <strong><?php echo (int) ( $last_stats['created'] ?? 0 ); ?></strong> &nbsp;
				Updated: <strong><?php echo (int) ( $last_stats['updated'] ?? 0 ); ?></strong> &nbsp;
				Skipped: <strong><?php echo (int) ( $last_stats['skipped'] ?? 0 ); ?></strong> &nbsp;
				Deleted: <strong><?php echo (int) ( $last_stats['deleted'] ?? 0 ); ?></strong> &nbsp;
				Errors: <strong><?php echo (int) ( $last_stats['errors'] ?? 0 ); ?></strong>
				<?php if ( ! empty( $last_stats['duration'] ) ) : ?>
				&nbsp; Duration: <strong><?php echo esc_html( $last_stats['duration'] ); ?>s</strong>
				<?php endif; ?>
			</p>
			<?php endif; ?>

			<p class="sev-cron-info">
				<?php if ( $enabled ) : ?>
					Next auto-sync: <strong><?php echo esc_html( SEV_Cron::get_next_run_text() ); ?></strong>
				<?php else : ?>
					<span class="sev-notice-disabled">Auto-sync is disabled — enable it in <a href="<?php echo esc_url( add_query_arg( [ 'page' => 'sev-wc-sync', 'tab' => 'settings' ], admin_url( 'admin.php' ) ) ); ?>">Settings</a>.</span>
				<?php endif; ?>
			</p>
		</div>

		<div class="sev-sync-row">
			<button id="sev-sync-now" type="button" class="button button-primary button-large">Sync Now</button>
			<button id="sev-reset-cache" type="button" class="button button-secondary">Reset Sync Cache</button>
		</div>

		<div id="sev-progress-section" style="display:none;">
			<div class="sev-progress-bar-outer">
				<div id="sev-progress-bar"
				     class="sev-progress-bar-inner"
				     style="width:0%"
				     role="progressbar"
				     aria-valuenow="0"
				     aria-valuemin="0"
				     aria-valuemax="100">
				</div>
			</div>
			<div id="sev-progress-text">0%</div>
			<div id="sev-status-message"></div>

			<div class="sev-stats-grid">
				<div class="sev-stat-box">
					<span class="sev-stat-value" id="sev-stat-created">0</span>
					<span class="sev-stat-label">Created</span>
				</div>
				<div class="sev-stat-box">
					<span class="sev-stat-value" id="sev-stat-updated">0</span>
					<span class="sev-stat-label">Updated</span>
				</div>
				<div class="sev-stat-box">
					<span class="sev-stat-value" id="sev-stat-skipped">0</span>
					<span class="sev-stat-label">Skipped</span>
				</div>
				<div class="sev-stat-box">
					<span class="sev-stat-value" id="sev-stat-deleted">0</span>
					<span class="sev-stat-label">Deleted</span>
				</div>
				<div class="sev-stat-box sev-stat-errors">
					<span class="sev-stat-value" id="sev-stat-errors">0</span>
					<span class="sev-stat-label">Errors</span>
				</div>
			</div>
		</div>

		<p class="sev-help-text">
			<strong>Reset Sync Cache</strong> clears all stored product hashes — the next sync will
			re-import every product from scratch instead of skipping unchanged ones.
		</p>

		<hr class="sev-danger-divider" />

		<div class="sev-danger-zone">
			<p class="sev-danger-label">Danger Zone</p>
			<div class="sev-danger-buttons">
				<button id="sev-trash-all" type="button" class="button sev-btn-danger">
					Move All Products to Trash
				</button>
				<button id="sev-empty-trash" type="button" class="button sev-btn-danger-solid">
					Permanently Delete Trashed Products
				</button>
			</div>
			<p class="sev-help-text">
				<strong>Move to Trash</strong> — soft-deletes every synced product (can be restored from WooCommerce trash).<br>
				<strong>Permanently Delete</strong> — irreversibly removes all products already in the trash. Cannot be undone.<br>
				Run <strong>Sync Now</strong> afterwards to re-import everything fresh.
			</p>
		</div>

		<?php
	}

	// ── Settings tab ──────────────────────────────────────────────────────────

	private function render_settings_tab(): void {
		if ( isset( $_GET['saved'] ) && $_GET['saved'] === '1' ) {
			echo '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>';
		}

		$enabled  = get_option( 'sev_enabled',         'no' );
		$interval = get_option( 'sev_sync_interval',   '8' );
		$delete   = get_option( 'sev_delete_behavior', 'trash' );
		?>

		<form method="post" action="">
			<?php wp_nonce_field( 'sev_settings_save' ); ?>

			<table class="sev-settings-table form-table">
				<tbody>

					<tr>
						<th scope="row"><label for="sev_enabled">Enable Auto-Sync</label></th>
						<td>
							<label>
								<input type="checkbox"
								       id="sev_enabled"
								       name="sev_enabled"
								       value="yes"
								       <?php checked( $enabled, 'yes' ); ?> />
								Enable automatic WP Cron sync
							</label>
							<p class="description">When enabled, products are synced automatically at the interval below.</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="sev_sync_interval">Sync Interval</label></th>
						<td>
							<select id="sev_sync_interval" name="sev_sync_interval">
								<option value="6"  <?php selected( $interval, '6' );  ?>>Every 6 hours</option>
								<option value="8"  <?php selected( $interval, '8' );  ?>>Every 8 hours (default)</option>
								<option value="12" <?php selected( $interval, '12' ); ?>>Every 12 hours</option>
								<option value="24" <?php selected( $interval, '24' ); ?>>Every 24 hours</option>
							</select>
							<p class="description">How often the plugin fetches fresh data from Shopify.</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="sev_delete_behavior">Delete Behavior</label></th>
						<td>
							<select id="sev_delete_behavior" name="sev_delete_behavior">
								<option value="trash"  <?php selected( $delete, 'trash' );  ?>>Move to Trash</option>
								<option value="delete" <?php selected( $delete, 'delete' ); ?>>Permanently Delete</option>
							</select>
							<p class="description">What to do with WC products that no longer exist in the Shopify store.</p>
						</td>
					</tr>

				</tbody>
			</table>

			<p class="submit">
				<input type="submit" name="sev_save_settings" class="button button-primary" value="Save Settings" />
			</p>
		</form>

		<?php
	}

	// ── Logs tab ──────────────────────────────────────────────────────────────

	private function render_logs_tab(): void {
		$log_dir = SEV_PLUGIN_DIR . 'logs/';
		$files   = glob( $log_dir . 'sev-sync-*.log' ) ?: [];

		// Sort newest first.
		rsort( $files );

		if ( empty( $files ) ) {
			echo '<p>No log files found. Logs are created when the first sync runs.</p>';
			return;
		}

		// Show selector if multiple log files exist.
		$selected_file = $files[0]; // default: most recent
		if ( isset( $_GET['logfile'] ) ) {
			$candidate = $log_dir . 'sev-sync-' . sanitize_file_name( $_GET['logfile'] ) . '.log';
			if ( in_array( $candidate, $files, true ) ) {
				$selected_file = $candidate;
			}
		}

		$log_content = file_get_contents( $selected_file );
		if ( $log_content === false ) {
			$log_content = '(could not read log file)';
		}

		?>
		<p class="sev-log-info">
			Showing: <strong><?php echo esc_html( basename( $selected_file ) ); ?></strong>
			(<?php echo esc_html( number_format( strlen( $log_content ) / 1024, 1 ) ); ?> KB)
		</p>

		<?php if ( count( $files ) > 1 ) : ?>
		<p class="sev-log-info">
			Other logs:
			<?php foreach ( array_slice( $files, 1 ) as $f ) : ?>
				<?php
				$basename = basename( $f, '.log' );
				$date     = str_replace( 'sev-sync-', '', $basename );
				$url      = add_query_arg( [ 'page' => 'sev-wc-sync', 'tab' => 'logs', 'logfile' => $date ], admin_url( 'admin.php' ) );
				?>
				<a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $date ); ?></a> &nbsp;
			<?php endforeach; ?>
		</p>
		<?php endif; ?>

		<textarea id="sev-log-content" readonly><?php echo esc_textarea( $log_content ); ?></textarea>

		<?php
	}
}
