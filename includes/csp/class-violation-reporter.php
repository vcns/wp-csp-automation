<?php
/**
 * CSP violation report ingestion endpoint.
 *
 * Implements §4.13 of the directive:
 *   - Handles both CSP Level 3 (application/csp-report) and legacy formats.
 *   - Deduplicates by a stable fingerprint: hash(surface + blocked_uri + violated_directive).
 *   - Increments occurrence_count on duplicate reports.
 *   - Rate-limits storage: drops reports after 500 per hour per surface (soft cap).
 *   - Returns 204 No Content for valid reports (browser expects no body).
 *   - Premium feature: violation analytics export, advanced dedup.
 */

declare( strict_types=1 );

namespace WP_CSP\CSP;

use WP_CSP\Modules\Audit_Log;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Violation_Reporter {

	private const MAX_PER_HOUR_PER_SURFACE = 500;
	private const RATE_LIMIT_WINDOW        = HOUR_IN_SECONDS;
	private const LEARNABLE_DIRECTIVES     = array(
		'script-src',
		'script-src-elem',
		'style-src',
		'style-src-elem',
		'img-src',
		'font-src',
		'connect-src',
		'frame-src',
		'media-src',
		'manifest-src',
		'worker-src',
		'child-src',
		'form-action',
	);

	private Audit_Log $audit;
	private ?Learning_Window $learning_window;
	private Policy_Change_Manager $policy_changes;

	public function __construct( Audit_Log $audit, ?Learning_Window $learning_window = null, ?Policy_Change_Manager $policy_changes = null ) {
		$this->audit           = $audit;
		$this->learning_window = $learning_window;
		$this->policy_changes  = null !== $policy_changes ? $policy_changes : new Policy_Change_Manager( $audit );
	}

	// ── REST handler ──────────────────────────────────────────────────────────

	/**
	 * Handles POST /csp-manager/v1/report
	 * Accepts application/csp-report (legacy) and application/reports+json (Reporting API).
	 * Rejects any other Content-Type — browsers must send one of these two (R10).
	 */
	public function handle( WP_REST_Request $request ): WP_REST_Response {
		// Validate Content-Type to reduce spoofing surface. application/json is accepted
		// as a legacy fallback — some older Chromium versions used it before the spec settled.
		$ct      = $request->get_content_type();
		$ct_val  = is_array( $ct ) ? ( $ct['value'] ?? '' ) : '';
		$allowed = array( 'application/csp-report', 'application/reports+json', 'application/json' );
		if ( '' !== $ct_val && ! in_array( $ct_val, $allowed, true ) ) {
			return new WP_REST_Response( null, 400 );
		}

		$raw = $request->get_body();
		if ( empty( $raw ) ) {
			return new WP_REST_Response( null, 204 );
		}

		$body = json_decode( $raw, true );
		if ( ! is_array( $body ) ) {
			return new WP_REST_Response( null, 204 );
		}

		$reports = $this->normalise_body( $body );

		foreach ( $reports as $report ) {
			$this->store_report( $report );
		}

		return new WP_REST_Response( null, 204 );
	}

	// ── Normalisation ─────────────────────────────────────────────────────────

	/**
	 * Normalises both CSP Level 3 and legacy report formats into a flat array.
	 */
	private function normalise_body( array $body ): array {
		// CSP Level 3: { "csp-report": { … } }
		if ( isset( $body['csp-report'] ) && is_array( $body['csp-report'] ) ) {
			return array( $this->map_csp_report( $body['csp-report'] ) );
		}

		// Reporting API (array of report objects): [ { "type": "csp-violation", "body": { … } } ]
		if ( isset( $body[0]['type'] ) ) {
			$out = array();
			foreach ( $body as $item ) {
				if ( in_array( isset( $item['type'] ) ? $item['type'] : '', array( 'csp-violation', 'content-security-policy' ), true )
					&& is_array( isset( $item['body'] ) ? $item['body'] : null )
				) {
					$out[] = $this->map_reporting_api( $item['body'] );
				}
			}
			return $out;
		}

		return array();
	}

	private function map_csp_report( array $r ): array {
		// Legacy field names use hyphens (application/csp-report format).
		// script-sample is only present when 'report-sample' is in the policy (R7).
		return array(
			'blocked_uri'         => isset( $r['blocked-uri'] ) ? $r['blocked-uri'] : '',
			'document_uri'        => isset( $r['document-uri'] ) ? $r['document-uri'] : '',
			'violated_directive'  => isset( $r['violated-directive'] ) ? $r['violated-directive'] : '',
			'effective_directive' => isset( $r['effective-directive'] ) ? $r['effective-directive'] : ( isset( $r['violated-directive'] ) ? $r['violated-directive'] : '' ),
			'original_policy'     => isset( $r['original-policy'] ) ? $r['original-policy'] : '',
			'source_file'         => isset( $r['source-file'] ) ? $r['source-file'] : '',
			'line_number'         => isset( $r['line-number'] ) ? (int) $r['line-number'] : null,
			'column_number'       => isset( $r['column-number'] ) ? (int) $r['column-number'] : null,
			'status_code'         => isset( $r['status-code'] ) ? (int) $r['status-code'] : null,
			'disposition'         => isset( $r['disposition'] ) ? $r['disposition'] : 'report',
			'referrer'            => isset( $r['referrer'] ) ? $r['referrer'] : '',
			'sample'              => isset( $r['script-sample'] ) ? $r['script-sample'] : '',
		);
	}

	private function map_reporting_api( array $b ): array {
		// Reporting API field names use camelCase (application/reports+json format).
		// sample is only present when 'report-sample' is in the policy (R7).
		return array(
			'blocked_uri'         => isset( $b['blockedURL'] ) ? $b['blockedURL'] : '',
			'document_uri'        => isset( $b['documentURL'] ) ? $b['documentURL'] : '',
			'violated_directive'  => isset( $b['violatedDirective'] ) ? $b['violatedDirective'] : '',
			'effective_directive' => isset( $b['effectiveDirective'] ) ? $b['effectiveDirective'] : ( isset( $b['violatedDirective'] ) ? $b['violatedDirective'] : '' ),
			'original_policy'     => isset( $b['originalPolicy'] ) ? $b['originalPolicy'] : '',
			'source_file'         => isset( $b['sourceFile'] ) ? $b['sourceFile'] : '',
			'line_number'         => isset( $b['lineNumber'] ) ? (int) $b['lineNumber'] : null,
			'column_number'       => isset( $b['columnNumber'] ) ? (int) $b['columnNumber'] : null,
			'status_code'         => isset( $b['statusCode'] ) ? (int) $b['statusCode'] : null,
			'disposition'         => isset( $b['disposition'] ) ? $b['disposition'] : 'report',
			'referrer'            => isset( $b['referrer'] ) ? $b['referrer'] : '',
			'sample'              => isset( $b['sample'] ) ? $b['sample'] : '',
		);
	}

	// ── Storage ───────────────────────────────────────────────────────────────

	private function store_report( array $r ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'csp_violation_reports';

		$blocked_uri        = sanitize_text_field( substr( $r['blocked_uri'], 0, 2048 ) );
		$violated_directive = sanitize_text_field( substr( $r['violated_directive'], 0, 128 ) );
		$document_uri       = isset( $r['document_uri'] ) ? $r['document_uri'] : '';
		$surface            = $this->surface_from_document_uri( $document_uri );

		if ( empty( $violated_directive ) ) {
			return;
		}

		// Reject spoofed cross-origin reports: document-uri must be on this site's host.
		// CSP violation reports are client-generated and therefore spoofable (research.md).
		if ( ! empty( $document_uri ) ) {
			$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
			$doc_host  = wp_parse_url( $document_uri, PHP_URL_HOST );
			if ( ! empty( $doc_host ) && $doc_host !== $site_host ) {
				return; // silently discard; do not reveal rejection to the sender
			}
		}

		// Rate-limit check.
		$rate_key = 'wp_csp_viol_rate_' . $surface;
		$count    = (int) get_transient( $rate_key );
		if ( $count >= self::MAX_PER_HOUR_PER_SURFACE ) {
			return;
		}
		set_transient( $rate_key, $count + 1, self::RATE_LIMIT_WINDOW );

		$fingerprint = hash( 'sha256', $surface . '|' . $blocked_uri . '|' . $violated_directive );
		$now         = current_time( 'mysql', true );

		$existing = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared	
				"SELECT id FROM {$table} WHERE fingerprint = %s LIMIT 1",
				$fingerprint
			)
		);

		if ( $existing ) {
			$wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"UPDATE {$table} SET occurrence_count = occurrence_count + 1, reported_at = %s WHERE id = %d",
					$now,
					(int) $existing
				)
			);
		} else {
			$wpdb->insert(
				$table,
				array(
					'profile_surface'     => $surface,
					'blocked_uri'         => $blocked_uri,
					'document_uri'        => sanitize_text_field( substr( $document_uri, 0, 2048 ) ),
					'violated_directive'  => $violated_directive,
					'effective_directive' => sanitize_text_field( substr( isset( $r['effective_directive'] ) ? $r['effective_directive'] : '', 0, 128 ) ),
					'original_policy'     => sanitize_textarea_field( isset( $r['original_policy'] ) ? $r['original_policy'] : '' ),
					'source_file'         => sanitize_text_field( substr( isset( $r['source_file'] ) ? $r['source_file'] : '', 0, 512 ) ),
					'line_number'         => $r['line_number'],
					'column_number'       => $r['column_number'],
					'status_code'         => $r['status_code'],
					'disposition'         => in_array( isset( $r['disposition'] ) ? $r['disposition'] : '', array( 'enforce', 'report' ), true ) ? $r['disposition'] : 'report',
					'referrer'            => sanitize_text_field( substr( isset( $r['referrer'] ) ? $r['referrer'] : '', 0, 2048 ) ),
					'user_agent'          => sanitize_text_field( substr( isset( $_SERVER['HTTP_USER_AGENT'] ) ? $_SERVER['HTTP_USER_AGENT'] : '', 0, 512 ) ),
					// sample: first ~40 chars of the offending inline block; only present
					// when 'report-sample' is in the policy (browsers truncate at 40 chars).
					'sample'              => sanitize_text_field( substr( isset( $r['sample'] ) ? $r['sample'] : '', 0, 256 ) ),
					'reported_at'         => $now,
					'fingerprint'         => $fingerprint,
					'occurrence_count'    => 1,
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
			);
		}

		$this->learn_source_from_report( $r, $surface, $blocked_uri, $now );
	}

	private function learn_source_from_report( array $r, string $surface, string $blocked_uri, string $now ): void {
		if ( null === $this->learning_window || ! $this->learning_window->is_open() ) {
			return;
		}

		$candidate = $this->source_candidate_from_report( $r, $blocked_uri );
		if ( null === $candidate ) {
			return;
		}

		$document_uri = sanitize_text_field( substr( isset( $r['document_uri'] ) ? $r['document_uri'] : '', 0, 512 ) );
		$notes        = sprintf( 'Learned from CSP report endpoint. Document: %s', $document_uri );

		$this->policy_changes->propose_source(
			$surface,
			$candidate,
			'report-endpoint',
			'runtime-report',
			$notes
		);
	}

	private function source_candidate_from_report( array $r, string $blocked_uri ): ?array {
		$directive = $this->normalise_directive(
			isset( $r['effective_directive'] ) && '' !== $r['effective_directive']
				? $r['effective_directive']
				: ( $r['violated_directive'] ?? '' )
		);

		if ( ! in_array( $directive, self::LEARNABLE_DIRECTIVES, true ) ) {
			return null;
		}

		$blocked_uri = trim( $blocked_uri );
		if ( '' === $blocked_uri || $this->is_non_host_blocked_uri( $blocked_uri ) ) {
			return null;
		}

		if ( str_starts_with( $blocked_uri, '//' ) ) {
			$blocked_uri = 'https:' . $blocked_uri;
		}

		$parsed = wp_parse_url( $blocked_uri );
		if ( empty( $parsed['host'] ) ) {
			return null;
		}

		$scheme = strtolower( isset( $parsed['scheme'] ) ? $parsed['scheme'] : 'https' );
		if ( ! in_array( $scheme, array( 'http', 'https', 'ws', 'wss' ), true ) ) {
			return null;
		}

		$host      = strtolower( $parsed['host'] );
		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( ! empty( $site_host ) && strtolower( (string) $site_host ) === $host ) {
			return null;
		}

		return array(
			'directive' => $directive,
			'uri'       => esc_url_raw( $blocked_uri ),
			'scheme'    => $scheme,
			'host'      => sanitize_text_field( substr( $host, 0, 255 ) ),
		);
	}

	private function normalise_directive( string $directive ): string {
		$directive = strtolower( trim( $directive ) );
		if ( str_contains( $directive, ' ' ) ) {
			$directive = strtok( $directive, ' ' );
		}

		$normalised = preg_replace( '/[^a-z0-9-]/', '', (string) $directive );
		return is_string( $normalised ) ? $normalised : '';
	}

	private function is_non_host_blocked_uri( string $blocked_uri ): bool {
		$value = strtolower( $blocked_uri );
		if ( in_array( $value, array( 'inline', 'eval', 'wasm-eval', 'data', 'blob', 'about' ), true ) ) {
			return true;
		}

		return str_starts_with( $value, 'data:' )
			|| str_starts_with( $value, 'blob:' )
			|| str_starts_with( $value, 'about:' );
	}

	private function surface_from_document_uri( string $uri ): string {
		if ( empty( $uri ) ) {
			return 'frontend';
		}
		$path = wp_parse_url( $uri, PHP_URL_PATH );
		$path = ! empty( $path ) ? $path : '';
		if ( str_contains( $path, '/wp-admin' ) ) {
			return 'admin';
		}
		if ( str_contains( $path, '/wp-login.php' ) ) {
			return 'login';
		}
		if ( str_contains( $path, '/wp-json' ) || str_contains( $path, '?rest_route' ) ) {
			return 'api';
		}
		return 'frontend';
	}
}
