<?php
/**
 * Write-only structured audit log.
 *
 * Writes lifecycle events to three destinations:
 *   1. csp_audit_log table — append-only, immutable record (R10). Never UPDATEd or DELETEd.
 *   2. wp_options FIFO queue (max 20) — for transient admin notice display only.
 *   3. PHP error_log — for warnings and errors.
 *
 * Scan lifecycle records go to csp_scan_logs via start_scan() / finish_scan().
 * Keeps a bounded in-memory buffer for request-scoped debugging / test inspection.
 *
 * Severity levels: info | warning | error
 */

declare( strict_types=1 );

namespace WP_CSP\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Audit_Log {

	private array $buffer = array();

	// ── Public API ────────────────────────────────────────────────────────────

	/**
	 * Logs an event.
	 *
	 * @param string $component  Originating component (e.g. 'webhook', 'config_resolver').
	 * @param string $event      Machine-readable event type (e.g. 'signature_failed').
	 * @param string $detail     Human-readable detail message.
	 * @param string $severity   'info' | 'warning' | 'error'
	 */
	public function log(
		string $component,
		string $event,
		string $detail,
		string $severity = 'info'
	): void {
		$entry = array(
			'ts'        => current_time( 'mysql', true ),
			'component' => $component,
			'event'     => $event,
			'detail'    => $detail,
			'severity'  => $severity,
		);

		$this->buffer[] = $entry;

		// Write to the append-only DB audit log (immutable record; never UPDATE/DELETE).
		$this->write_to_db( $component, $event, $detail, $severity );

		// Persist in wp_options as a small FIFO queue for the admin notices panel.
		$this->push_admin_notice( $entry );

		// Always write warnings and errors to PHP error log.
		if ( in_array( $severity, array( 'warning', 'error' ), true ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log(
				sprintf(
					'[WP-CSP][%s][%s] %s: %s',
					strtoupper( $severity ),
					$component,
					$event,
					$detail
				)
			);
		}
	}

	/**
	 * Opens a scan log record and returns its ID.
	 */
	public function start_scan( string $trigger_type ): int {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'csp_scan_logs',
			array(
				'trigger_type' => $trigger_type,
				'status'       => 'running',
				'started_at'   => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Closes a scan log record with results.
	 */
	public function finish_scan( int $scan_id, array $results, string $status = 'completed' ): void {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'csp_scan_logs',
			array(
				'status'          => $status,
				'sources_added'   => $results['sources_added'] ?? 0,
				'sources_removed' => $results['sources_removed'] ?? 0,
				'hashes_added'    => $results['hashes_added'] ?? 0,
				'hashes_removed'  => $results['hashes_removed'] ?? 0,
				'policy_changed'  => (int) ( $results['policy_changed'] ?? false ),
				'diff_summary'    => isset( $results['diff'] ) ? wp_json_encode( $results['diff'] ) : null,
				'warnings'        => isset( $results['warnings'] ) ? wp_json_encode( $results['warnings'] ) : null,
				'completed_at'    => current_time( 'mysql', true ),
			),
			array( 'id' => $scan_id ),
			array( '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Returns the in-memory log buffer (useful for request-scoped debugging).
	 */
	public function get_buffer(): array {
		return $this->buffer;
	}

	// ── Immutable DB record ───────────────────────────────────────────────────

	/**
	 * Appends an event to the csp_audit_log table.
	 * This table is never UPDATE-d or DELETE-d — it is the permanent audit trail (R10).
	 * Failures are silently swallowed so a DB hiccup never kills the request.
	 */
	private function write_to_db(
		string $component,
		string $event,
		string $detail,
		string $severity
	): void {
		global $wpdb;
		$table = $wpdb->prefix . 'csp_audit_log';

		// Guard: table may not exist yet on first activation before dbDelta runs.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			return;
		}

		$wpdb->insert(
			$table,
			array(
				'component'  => substr( $component, 0, 64 ),
				'event'      => substr( $event, 0, 128 ),
				'detail'     => $detail,
				'severity'   => $severity,
				'user_id'    => is_user_logged_in() ? get_current_user_id() : null,
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s' )
		);
	}

	// ── Admin notices ─────────────────────────────────────────────────────────

	private function push_admin_notice( array $entry ): void {
		if ( 'info' === $entry['severity'] ) {
			return; // Only surface warnings and errors.
		}

		$notices = get_option( 'wp_csp_admin_notices', array() );
		if ( ! is_array( $notices ) ) {
			$notices = array();
		}

		$notices[] = $entry;

		// Cap at 20 notices to prevent unbounded option growth.
		if ( count( $notices ) > 20 ) {
			$notices = array_slice( $notices, -20 );
		}

		update_option( 'wp_csp_admin_notices', $notices, false );
	}
}
