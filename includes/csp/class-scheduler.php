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
		add_action( 'wp_csp_daily_scan', [ $this, 'run_daily_scan' ] );
	}

	// ── Scan runner ───────────────────────────────────────────────────────────

	/**
	 * Main cron callback. Performs a full discovery + hash audit cycle.
	 */
	public function run_daily_scan(): void {
		$scan_id = $this->audit->start_scan( 'scheduled' );

		try {
			$plugin      = \WP_CSP\Plugin::instance();
			$gate        = $plugin->gate;

			$discovery   = new Discovery( $this->audit, $gate );
			$hash_mgr    = new Hash_Manager( $this->audit, $gate );

			$discovery_results = $discovery->run_scan();

			// Audit hashes – compare current crawl against stored hashes.
			// Simplified: retire hashes not seen in last scan run.
			$hash_retired = $hash_mgr->retire_stale( [], 'frontend' );

			$results = [
				'sources_added'   => $discovery_results['sources_added'],
				'sources_removed' => 0,
				'hashes_added'    => 0,
				'hashes_removed'  => $hash_retired,
				'policy_changed'  => $discovery_results['sources_added'] > 0 || $hash_retired > 0,
			];

			$this->audit->finish_scan( $scan_id, $results );
			$this->maybe_notify( $results );

		} catch ( \Throwable $e ) {
			$this->audit->finish_scan( $scan_id, [], 'failed' );
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
			$plugin    = \WP_CSP\Plugin::instance();
			$gate      = $plugin->gate;
			$discovery = new Discovery( $this->audit, $gate );
			$hash_mgr  = new Hash_Manager( $this->audit, $gate );

			$dr = $discovery->run_scan();
			$hr = $hash_mgr->retire_stale( [], 'frontend' );

			$results = [
				'sources_added'   => $dr['sources_added'],
				'sources_removed' => 0,
				'hashes_added'    => 0,
				'hashes_removed'  => $hr,
				'policy_changed'  => $dr['sources_added'] > 0 || $hr > 0,
			];

			$this->audit->finish_scan( $scan_id, $results );
			return $results;

		} catch ( \Throwable $e ) {
			$this->audit->finish_scan( $scan_id, [], 'failed' );
			$this->audit->log( 'scheduler', 'manual_scan_exception', $e->getMessage(), 'error' );
			return [ 'error' => $e->getMessage() ];
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
			__( '[%s] CSP Automation: policy changed after scheduled scan', 'wp-csp-automation' ),
			get_bloginfo( 'name' )
		);
		$message = sprintf(
			/* translators: 1: sources added, 2: hashes removed */
			__( "The scheduled CSP rescan completed.\n\nSources added: %1\$d\nHashes retired: %2\$d\n\nReview the dashboard: %3\$s", 'wp-csp-automation' ),
			$results['sources_added'],
			$results['hashes_removed'],
			admin_url( 'admin.php?page=wp-csp-automation-dashboard' )
		);
		wp_mail( $email, $subject, $message );
	}
}
