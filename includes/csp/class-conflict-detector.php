<?php
/**
 * Detects competing Content-Security-Policy headers.
 *
 * Implements §4.9 of the directive:
 *   - Detects if any other plugin or server config is emitting a CSP header
 *     that would conflict with or override this plugin's output.
 *   - Checks wp_headers filter for pre-existing CSP values.
 *   - Probes the site home URL and checks the response headers.
 *   - Logs conflicts via Audit_Log and surfaces them as admin notices.
 *
 * Multi-surface conflict detection.
 */

declare( strict_types=1 );

namespace WP_CSP\CSP;

use WP_CSP\Modules\Audit_Log;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Conflict_Detector {

	private Audit_Log $audit;

	public function __construct( Audit_Log $audit ) {
		$this->audit = $audit;
	}

	// ── Bootstrap ─────────────────────────────────────────────────────────────

	public function register(): void {
		// Hook late into wp_headers to detect competing CSP values already queued.
		add_filter( 'wp_headers', array( $this, 'check_headers_filter' ), PHP_INT_MAX );

		// Run a background probe once per day via a transient gate.
		add_action( 'admin_init', array( $this, 'maybe_run_probe' ) );
	}

	// ── Header filter hook ────────────────────────────────────────────────────

	/**
	 * Fired on the wp_headers filter. If a CSP header is already in the array
	 * (set by another plugin), record the conflict.
	 *
	 * @param array $headers  Associative array of header name → value.
	 * @return array          Unchanged – we only detect, never remove.
	 */
	public function check_headers_filter( array $headers ): array {
		$conflict_keys = array(
			'Content-Security-Policy',
			'Content-Security-Policy-Report-Only',
			'X-Content-Security-Policy', // Legacy IE header.
		);

		foreach ( $conflict_keys as $key ) {
			if ( isset( $headers[ $key ] ) ) {
				$this->record_conflict(
					'header_filter',
					$key,
					substr( $headers[ $key ], 0, 256 )
				);
			}
		}

		return $headers;
	}

	// ── Active probe ──────────────────────────────────────────────────────────

	/**
	 * Performs an HTTP HEAD probe to check response headers on the live site.
	 * Throttled via a 24-hour transient to avoid hammering.
	 */
	public function maybe_run_probe(): void {
		$transient_key = 'wp_csp_conflict_probe_ran';
		if ( get_transient( $transient_key ) ) {
			return;
		}
		set_transient( $transient_key, 1, DAY_IN_SECONDS );

		$this->run_probe( get_home_url() );
	}

	/**
	 * Probes a URL and checks for duplicate CSP headers.
	 *
	 * @param string $url  URL to probe.
	 * @return array       List of conflicting header names found.
	 */
	public function run_probe( string $url ): array {
		$response = wp_remote_head(
			$url,
			array(
				'timeout'   => 10,
				'sslverify' => true,
				'headers'   => array( 'X-WP-CSP-Probe' => '1' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array();
		}

		$headers = wp_remote_retrieve_headers( $response );
		$found   = array();

		foreach ( array( 'content-security-policy', 'content-security-policy-report-only' ) as $hdr ) {
			$val = $headers->offsetGet( $hdr );
			if ( $val ) {
				// Check if we see multiple values (duplicate header).
				if ( is_array( $val ) && count( $val ) > 1 ) {
					$this->record_conflict( 'probe_duplicate', $hdr, implode( ' | ', array_slice( $val, 0, 2 ) ) );
					$found[] = $hdr;
				}
			}
		}

		return $found;
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	private function record_conflict( string $source, string $header, string $value ): void {
		$this->audit->log(
			'conflict_detector',
			'csp_conflict',
			"Competing '{$header}' detected via {$source}. Value prefix: {$value}",
			'warning'
		);
	}
}
