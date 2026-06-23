<?php
/**
 * WP Cron integration for scheduled policy rescans.
 *
 * Implements §4.10 of the directive:
 *   - Registers the wp_csp_daily_scan hook.
 *   - Runs Discovery scan, Hash_Manager audit, and Policy_Builder diff.
 *   - Writes results to the scan log via Audit_Log.
 *   - Sends admin email notification on policy changes (optional).
 */

declare( strict_types=1 );

namespace WP_CSP\CSP;

use WP_CSP\Modules\Audit_Log;
use WP_CSP\Modules\Feature_Gate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Scheduler {

	private Audit_Log $audit;

	public function __construct( Audit_Log $audit ) {
		$this->audit = $audit;
	}

	// ── Bootstrap ─────────────────────────────────────────────────────────────

	public function register(): void {
		add_action( 'wp_csp_daily_scan', array( $this, 'run_daily_scan' ) );
	}

	// ── Scan runner ───────────────────────────────────────────────────────────

	/**
	 * Main cron callback. Performs a full discovery + hash audit cycle.
	 */
	public function run_daily_scan(): void {
		$scan_id = $this->audit->start_scan( 'scheduled' );

		try {
			$plugin   = \WP_CSP\Plugin::instance();
			$gate     = $plugin->gate;
			$hash_mgr = $plugin->hash_manager;

			$discovery = new Discovery( $this->audit, $gate );

			$discovery_results = $discovery->run_scan();

			// Retrieve hashes observed during this request (may be empty on CLI
			// cron runs where no page was rendered and no buffer was flushed).
			// Hash_Manager::retire_stale() is a no-op when the map is empty,
			// which is safe: hashes are retired only when we have positive
			// evidence that the content changed, not on absence of evidence.
			$current_hashes = $hash_mgr->get_captured_hashes();
			$hash_retired   = $hash_mgr->retire_stale( $current_hashes, 'frontend' );

			$results = array(
				'sources_added'   => $discovery_results['sources_added'],
				'sources_removed' => 0,
				'hashes_added'    => 0,
				'hashes_removed'  => $hash_retired,
				'policy_changed'  => $discovery_results['sources_added'] > 0 || $hash_retired > 0,
			);

			$this->audit->finish_scan( $scan_id, $results );
			$this->maybe_notify( $results );
			$this->purge_old_violations();

		} catch ( \Throwable $e ) {
			$this->audit->finish_scan( $scan_id, array(), 'failed' );
			$this->audit->log( 'scheduler', 'scan_exception', $e->getMessage(), 'error' );
		}
	}

	/**
	 * Triggers an immediate on-demand scan (§4.11 Manual Rescan).
	 * Called from Admin_UI AJAX handler.
	 *
	 * @return array  Scan result summary.
	 */
	public function run_manual_scan(): array {
		$scan_id = $this->audit->start_scan( 'manual' );

		try {
			$plugin   = \WP_CSP\Plugin::instance();
			$gate     = $plugin->gate;
			$hash_mgr = $plugin->hash_manager;

			$discovery = new Discovery( $this->audit, $gate );

			$dr = $discovery->run_scan();

			// Same rationale as run_daily_scan(): pass the real capture map.
			// If the admin triggered a manual scan from the dashboard, the
			// buffer hooks will have fired during the admin page render and
			// get_captured_hashes() will contain the admin surface's inline blocks.
			$current_hashes = $hash_mgr->get_captured_hashes();
			$hr             = $hash_mgr->retire_stale( $current_hashes, 'frontend' );

			$results = array(
				'sources_added'   => $dr['sources_added'],
				'sources_removed' => 0,
				'hashes_added'    => 0,
				'hashes_removed'  => $hr,
				'policy_changed'  => $dr['sources_added'] > 0 || $hr > 0,
			);

			$this->audit->finish_scan( $scan_id, $results );
			return $results;

		} catch ( \Throwable $e ) {
			$this->audit->finish_scan( $scan_id, array(), 'failed' );
			$this->audit->log( 'scheduler', 'manual_scan_exception', $e->getMessage(), 'error' );
			return array( 'error' => $e->getMessage() );
		}
	}

	// ── Data retention ────────────────────────────────────────────────────────

	/**
	 * Purges violation reports older than wp_csp_violation_retention_days (default 90).
	 * A value of 0 means keep forever. Runs after every daily cron scan (R10).
	 */
	private function purge_old_violations(): void {
		$days = (int) get_option( 'wp_csp_violation_retention_days', 90 );
		if ( $days <= 0 ) {
			return;
		}

		global $wpdb;
		$table   = $wpdb->prefix . 'csp_violation_reports';
		$deleted = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"DELETE FROM {$table} WHERE reported_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)",
				$days
			)
		);

		if ( $deleted > 0 ) {
			$this->audit->log(
				'scheduler',
				'violations_purged',
				sprintf( 'Purged %d violation report(s) older than %d days.', $deleted, $days ),
				'info'
			);
		}
	}

	// ── Notification ──────────────────────────────────────────────────────────

	private function maybe_notify( array $results ): void {
		if ( empty( $results['policy_changed'] ) ) {
			return;
		}
		$email = (string) get_option( 'wp_csp_notify_email', get_option( 'admin_email' ) );
		if ( empty( $email ) || ! is_email( $email ) ) {
			return;
		}
		$subject = sprintf(
			/* translators: %s: site name */
			__( '[%s] VCNS CSP Manager: policy changed after scheduled scan', 'vcns-csp-manager' ),
			get_bloginfo( 'name' )
		);
		$message = sprintf(
			/* translators: 1: sources added, 2: hashes removed */
			__( "The scheduled CSP rescan completed.\n\nSources added: %1\$d\nHashes retired: %2\$d\n\nReview the dashboard: %3\$s", 'vcns-csp-manager' ),
			$results['sources_added'],
			$results['hashes_removed'],
			admin_url( 'admin.php?page=wp-csp-automation-dashboard' )
		);
		wp_mail( $email, $subject, $message );
	}
}
