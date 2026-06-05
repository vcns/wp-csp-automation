<?php
/**
 * WordPress Admin UI: menus, settings API, AJAX handlers.
 *
 * Registers three admin pages:
 *   1. wp-csp-dashboard  – CSP surface profiles, source inventory, violations, scan history
 *   2. wp-csp-settings   – Stripe keys, DNS config domain, cron schedule, notify email
 *   3. wp-csp-entitlement – Purchase status and Buy Pro flow
 *
 * All form submissions are protected by check_admin_referer() and
 * current_user_can('manage_options').
 */

declare( strict_types=1 );

namespace WP_CSP\Admin;

use WP_CSP\Plugin;
use WP_CSP\CSP\Scheduler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin_UI {

	private Plugin $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	// ── Bootstrap ─────────────────────────────────────────────────────────────

	public function register(): void {
		add_action( 'admin_menu',            [ $this, 'add_menu_pages' ] );
		add_action( 'admin_init',            [ $this, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'admin_notices',         [ $this, 'display_admin_notices' ] );

		// AJAX handlers.
		add_action( 'wp_ajax_wp_csp_create_checkout',  [ $this, 'ajax_create_checkout' ] );
		add_action( 'wp_ajax_wp_csp_manual_scan',      [ $this, 'ajax_manual_scan' ] );
		add_action( 'wp_ajax_wp_csp_refresh_config',   [ $this, 'ajax_refresh_config' ] );
		add_action( 'wp_ajax_wp_csp_approve_source',   [ $this, 'ajax_approve_source' ] );
		add_action( 'wp_ajax_wp_csp_deny_source',      [ $this, 'ajax_deny_source' ] );
		add_action( 'wp_ajax_wp_csp_toggle_mode',      [ $this, 'ajax_toggle_mode' ] );
	}

	// ── Menu registration ─────────────────────────────────────────────────────

	public function add_menu_pages(): void {
		add_menu_page(
			__( 'CSP Manager', 'wp-csp-automation' ),
			__( 'CSP Manager', 'wp-csp-automation' ),
			'manage_options',
			'wp-csp-dashboard',
			[ $this, 'render_dashboard' ],
			'dashicons-shield',
			80
		);

		add_submenu_page(
			'wp-csp-dashboard',
			__( 'CSP Dashboard', 'wp-csp-automation' ),
			__( 'Dashboard', 'wp-csp-automation' ),
			'manage_options',
			'wp-csp-dashboard',
			[ $this, 'render_dashboard' ]
		);

		add_submenu_page(
			'wp-csp-dashboard',
			__( 'Settings', 'wp-csp-automation' ),
			__( 'Settings', 'wp-csp-automation' ),
			'manage_options',
			'wp-csp-settings',
			[ $this, 'render_settings' ]
		);

		add_submenu_page(
			'wp-csp-dashboard',
			__( 'Premium', 'wp-csp-automation' ),
			__( 'Premium', 'wp-csp-automation' ),
			'manage_options',
			'wp-csp-entitlement',
			[ $this, 'render_entitlement' ]
		);
	}

	// ── Settings API ──────────────────────────────────────────────────────────

	public function register_settings(): void {
		$settings = [
			'wp_csp_stripe_mode'                   => 'sanitize_text_field',
			'wp_csp_stripe_publishable_key'        => 'sanitize_text_field',
			'wp_csp_stripe_secret_key'             => 'sanitize_text_field',
			'wp_csp_webhook_secret'                => 'sanitize_text_field',
			'wp_csp_config_dns_domain'             => 'sanitize_text_field',
			'wp_csp_config_cache_ttl'              => 'absint',
			'wp_csp_config_grace_ttl'              => 'absint',
			'wp_csp_entitlement_grace_hours'       => 'absint',
			'wp_csp_cron_hour'                     => 'absint',
			'wp_csp_notify_email'                  => 'sanitize_email',
			'wp_csp_enforce_gate_violation_window' => 'absint',
		];

		foreach ( $settings as $option => $callback ) {
			register_setting( 'wp_csp_settings_group', $option, [ 'sanitize_callback' => $callback ] );
		}
	}

	// ── Asset enqueue ─────────────────────────────────────────────────────────

	public function enqueue_assets( string $hook_suffix ): void {
		$csp_pages = [
			'toplevel_page_wp-csp-dashboard',
			'csp-manager_page_wp-csp-settings',
			'csp-manager_page_wp-csp-entitlement',
		];
		if ( ! in_array( $hook_suffix, $csp_pages, true ) ) {
			return;
		}

		wp_enqueue_style(
			'wp-csp-admin',
			WP_CSP_URL . 'assets/css/admin.css',
			[],
			WP_CSP_VERSION
		);

		wp_enqueue_script(
			'wp-csp-admin',
			WP_CSP_URL . 'assets/js/admin.js',
			[ 'jquery' ],
			WP_CSP_VERSION,
			true
		);

		wp_localize_script(
			'wp-csp-admin',
			'wpCspAdmin',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wp_csp_admin_nonce' ),
				'i18n'    => [
					'scanning'  => __( 'Scanning…', 'wp-csp-automation' ),
					'scanDone'  => __( 'Scan complete.', 'wp-csp-automation' ),
					'scanError' => __( 'Scan failed. Check error log.', 'wp-csp-automation' ),
				],
			]
		);
	}

	// ── Page renderers ────────────────────────────────────────────────────────

	public function render_dashboard(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'wp-csp-automation' ) );
		}
		require WP_CSP_DIR . 'includes/admin/views/page-csp-dashboard.php';
	}

	public function render_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'wp-csp-automation' ) );
		}
		require WP_CSP_DIR . 'includes/admin/views/page-settings.php';
	}

	public function render_entitlement(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'wp-csp-automation' ) );
		}
		require WP_CSP_DIR . 'includes/admin/views/page-entitlement.php';
	}

	// ── Admin notices ─────────────────────────────────────────────────────────

	public function display_admin_notices(): void {
		$notices = get_option( 'wp_csp_admin_notices', [] );
		if ( ! is_array( $notices ) || empty( $notices ) ) {
			return;
		}
		foreach ( $notices as $notice ) {
			$type = 'error' === $notice['severity'] ? 'error' : 'warning';
			printf(
				'<div class="notice notice-%1$s is-dismissible"><p><strong>%2$s</strong> [%3$s] %4$s</p></div>',
				esc_attr( $type ),
				esc_html__( 'WP CSP Automation:', 'wp-csp-automation' ),
				esc_html( $notice['component'] . '/' . $notice['event'] ),
				esc_html( $notice['detail'] )
			);
		}
		delete_option( 'wp_csp_admin_notices' );
	}

	// ── AJAX: create Stripe checkout ──────────────────────────────────────────

	public function ajax_create_checkout(): void {
		check_ajax_referer( 'wp_csp_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'wp-csp-automation' ) ], 403 );
		}

		$product_key = sanitize_text_field( wp_unslash( $_POST['product_key'] ?? 'wp-csp-pro' ) );
		$result      = $this->plugin->config ?
			( new \WP_CSP\Modules\Checkout_Service( $this->plugin->config, $this->plugin->audit ) )->create_session( $product_key ) :
			new \WP_Error( 'no_config', __( 'Plugin not fully initialised.', 'wp-csp-automation' ) );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		} else {
			wp_send_json_success( [ 'checkout_url' => esc_url_raw( $result ) ] );
		}
	}

	// ── AJAX: manual scan ─────────────────────────────────────────────────────

	public function ajax_manual_scan(): void {
		check_ajax_referer( 'wp_csp_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'wp-csp-automation' ) ], 403 );
		}

		$scheduler = new Scheduler( $this->plugin->audit );
		$results   = $scheduler->run_manual_scan();

		if ( isset( $results['error'] ) ) {
			wp_send_json_error( [ 'message' => $results['error'] ] );
		} else {
			wp_send_json_success( $results );
		}
	}

	// ── AJAX: refresh remote config ───────────────────────────────────────────

	public function ajax_refresh_config(): void {
		check_ajax_referer( 'wp_csp_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'wp-csp-automation' ) ], 403 );
		}

		$ok = $this->plugin->config->refresh();
		if ( $ok ) {
			wp_send_json_success( [ 'version' => get_option( 'wp_csp_config_version', 'unknown' ) ] );
		} else {
			wp_send_json_error( [ 'message' => __( 'Config refresh failed. Check audit log.', 'wp-csp-automation' ) ] );
		}
	}

	// ── AJAX: approve/deny source ─────────────────────────────────────────────

	public function ajax_approve_source(): void {
		check_ajax_referer( 'wp_csp_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}
		$this->set_source_state( (int) ( $_POST['source_id'] ?? 0 ), 'approved' );
	}

	public function ajax_deny_source(): void {
		check_ajax_referer( 'wp_csp_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}
		$this->set_source_state( (int) ( $_POST['source_id'] ?? 0 ), 'denied' );
	}

	private function set_source_state( int $id, string $state ): void {
		if ( $id <= 0 ) {
			wp_send_json_error( [ 'message' => 'Invalid ID.' ] );
		}
		global $wpdb;
		$data = [ 'approval_state' => $state ];
		if ( 'approved' === $state ) {
			$data['approved_at'] = current_time( 'mysql', true );
		}
		$wpdb->update( $wpdb->prefix . 'csp_source_inventory', $data, [ 'id' => $id ], [ '%s', '%s' ], [ '%d' ] );
		wp_send_json_success();
	}

	// ── AJAX: toggle surface mode ─────────────────────────────────────────────

	public function ajax_toggle_mode(): void {
		check_ajax_referer( 'wp_csp_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}

		$surface = sanitize_text_field( wp_unslash( $_POST['surface'] ?? '' ) );
		$mode    = sanitize_text_field( wp_unslash( $_POST['mode']    ?? '' ) );

		if ( ! in_array( $surface, [ 'frontend', 'admin', 'login', 'api' ], true ) ) {
			wp_send_json_error( [ 'message' => 'Invalid surface.' ] );
		}
		if ( ! in_array( $mode, [ 'report-only', 'enforce', 'disabled' ], true ) ) {
			wp_send_json_error( [ 'message' => 'Invalid mode.' ] );
		}

		// Full promotion gate: enforce requires passing all configured checks.
		if ( 'enforce' === $mode ) {
			$gate_result = $this->gate_allows_enforce( $surface );
			if ( true !== $gate_result ) {
				wp_send_json_error( [ 'message' => $gate_result ] );
			}
		}

		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'csp_policy_profiles',
			[
				'mode'       => $mode,
				'updated_at' => current_time( 'mysql', true ),
			],
			[ 'surface' => $surface ],
			[ '%s', '%s' ],
			[ '%s' ]
		);
		wp_send_json_success();
	}

	// ── Promotion gate ────────────────────────────────────────────────────────

	/**
	 * Checks all configured gates before allowing enforce mode promotion.
	 *
	 * Implements §4.12:
	 *   Gate 1 -- At least one approved source or hash must exist for the surface.
	 *   Gate 2 -- No violations recorded within the configured time window.
	 *   Gate 3 -- No active temporary override that has not yet expired.
	 *
	 * @param  string       $surface  CSP surface identifier.
	 * @return true|string  true if all gates pass; a human-readable failure reason string otherwise.
	 */
	private function gate_allows_enforce( string $surface ): true|string {
		global $wpdb;

		// ── Gate 1: approved source or hash inventory ─────────────────────────
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$src_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}csp_source_inventory WHERE surface = %s AND approval_state = 'approved'",
				$surface
			)
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$hash_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}csp_hash_inventory WHERE surface = %s AND status = 'active'",
				$surface
			)
		);

		if ( ( $src_count + $hash_count ) === 0 ) {
			return __( 'Cannot promote to enforce: no approved sources or hashes found for this surface. Run a scan and approve at least one source first.', 'wp-csp-automation' );
		}

		// ── Gate 2: no violations within the configured time window ───────────
		$window_hours = max( 1, (int) get_option( 'wp_csp_enforce_gate_violation_window', 24 ) );
		$since        = gmdate( 'Y-m-d H:i:s', time() - ( $window_hours * HOUR_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$recent_violations = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}csp_violation_reports
				WHERE profile_surface = %s
				AND reported_at >= %s",
				$surface,
				$since
			)
		);

		if ( $recent_violations > 0 ) {
			return sprintf(
				/* translators: 1: violation count, 2: hours */
				__( 'Cannot promote to enforce: %1$d violation(s) recorded for this surface in the last %2$d hour(s). Resolve violations in report-only mode first, or extend the violation window in Settings.', 'wp-csp-automation' ),
				$recent_violations,
				$window_hours
			);
		}

		// ── Gate 3: no active unresolved temporary override ───────────────────
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$profile = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT override_expires_at, override_owner FROM {$wpdb->prefix}csp_policy_profiles WHERE surface = %s LIMIT 1",
				$surface
			),
			ARRAY_A
		);

		if ( $profile ) {
			$expires_at = $profile['override_expires_at'] ?? null;
			$owner      = $profile['override_owner']      ?? null;

			if ( ! empty( $expires_at ) && ! empty( $owner ) ) {
				$expires_ts = strtotime( $expires_at );
				if ( false !== $expires_ts && $expires_ts > time() ) {
					return sprintf(
						/* translators: 1: override owner, 2: expiry datetime */
						__( 'Cannot promote to enforce: a temporary override set by "%1$s" is active until %2$s. Wait for it to expire or remove it before enabling enforce mode.', 'wp-csp-automation' ),
						esc_html( $owner ),
						esc_html( $expires_at )
					);
				}
			}
		}

		return true;
	}
}