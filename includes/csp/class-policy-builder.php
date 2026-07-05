<?php
/**
 * Builds and emits the Content-Security-Policy (or CSP-Report-Only) header.
 *
 * Implements §4.3, §4.4, §4.8 of the directive:
 *   - Per-surface profiles loaded from DB (frontend, admin, login, api).
 *   - All 18 directives emitted; empty directives still included to close
 *     implicit fallback to default-src.
 *   - Nonce injected from Nonce_Manager at request time.
 *   - Approved hashes from csp_hash_inventory appended to script-src / style-src.
 *   - Approved hosts from csp_source_inventory appended per directive.
 *   - report-to and report-uri appended automatically.
 *   - Reporting-Endpoints (RFC 9651 Structured Fields Dictionary) emitted so that
 *     the report-to directive is resolved by the browser. Also emits the deprecated
 *     Report-To JSON header as a legacy fallback for pre-Reporting-API browsers.
 *   - 'strict-dynamic' added to script-src when profile enables it (pro feature).
 *     When active, approved host sources are suppressed from script-src — browsers
 *     silently ignore host allowlists when strict-dynamic is present (CSP3 §8.2),
 *     so including them is misleading noise.
 *   - FORBIDDEN_DIRECTIVES (deprecated/removed by W3C) are stripped from any
 *     admin override before serialisation; a warning is written to the audit log.
 *
 * FIX: source host values are sanitised with sanitize_text_field() rather than
 * esc_attr(). esc_attr() HTML-encodes characters such as & which are invalid
 * in HTTP header values and would produce a malformed CSP directive.
 */

declare( strict_types=1 );

namespace WP_CSP\CSP;

use WP_CSP\Modules\Feature_Gate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Policy_Builder {

	/**
	 * Directives removed or deprecated by W3C that must never be emitted.
	 * References: CSP3 WD-20260505; MDN; research.md R4.
	 */
	private const FORBIDDEN_DIRECTIVES = array(
		'plugin-types',           // removed; plugins are gone from the web platform
		'block-all-mixed-content', // obsolete; superseded by default browser auto-upgrade
		'navigate-to',            // removed from CSP3 spec (was at-risk)
		'prefetch-src',           // deprecated/non-standard; Chromium intent-to-remove
	);

	private Feature_Gate $gate;

	/** @var callable|null */
	private $hash_loader;

	/** @var callable|null */
	private $source_loader;

	public function __construct(
		Feature_Gate $gate,
		?callable $hash_loader = null,
		?callable $source_loader = null
	) {
		$this->gate          = $gate;
		$this->hash_loader   = $hash_loader;
		$this->source_loader = $source_loader;
	}

	// ── Bootstrap ─────────────────────────────────────────────────────────────

	public function register(): void {
		// send_headers fires before any output, ideal for emitting CSP.
		add_action( 'send_headers', array( $this, 'emit_header' ) );
	}

	// ── Header emission ───────────────────────────────────────────────────────

	public function emit_header(): void {
		// Skip if headers already sent (e.g. a plugin flushed output early).
		if ( headers_sent() ) {
			return;
		}

		$surface = $this->detect_surface();
		$profile = $this->load_profile( $surface );

		if ( null === $profile || 'disabled' === $profile['mode'] ) {
			return;
		}

		$policy = $this->build_policy_string( $profile, $surface );
		if ( empty( $policy ) ) {
			return;
		}

		$is_report_only = ( 'report-only' === $profile['mode'] );
		$header_name    = $is_report_only
			? 'Content-Security-Policy-Report-Only'
			: 'Content-Security-Policy';

		// Declare the reporting endpoint so browsers can resolve the report-to directive.
		// Reporting-Endpoints is a Structured Fields Dictionary per RFC 9651 (obsoletes 8941).
		// Report-To (JSON) is deprecated but kept as a legacy fallback for older browsers.
		$report_uri = rest_url( 'csp-manager/v1/report' );
		header( 'Reporting-Endpoints: csp-endpoint="' . esc_url_raw( $report_uri ) . '"' );
		header( 'Report-To: {"group":"csp-endpoint","max_age":86400,"endpoints":[{"url":"' . esc_url_raw( $report_uri ) . '"}]}' );

		header( $header_name . ': ' . $policy );
	}

	// ── Policy assembly ───────────────────────────────────────────────────────

	public function build_policy_string( array $profile, string $surface ): string {
		$nonce      = Plugin_Nonce_Manager::get_instance_nonce();
		$directives = json_decode( $profile['directives'], true );
		$overrides  = json_decode( $profile['overrides'], true );

		if ( ! is_array( $directives ) ) {
			return '';
		}

		// Merge admin overrides on top of base directives.
		if ( is_array( $overrides ) ) {
			foreach ( $overrides as $dir => $sources ) {
				$directives[ $dir ] = $sources;
			}
		}

		// Strip deprecated/removed directives that must never be emitted (R4).
		// These may have been stored in overrides; removing them here prevents
		// any upgrade path from accidentally re-enabling them.
		$forbidden_found = array_intersect_key( $directives, array_flip( self::FORBIDDEN_DIRECTIVES ) );
		if ( ! empty( $forbidden_found ) ) {
			$directives = array_diff_key( $directives, array_flip( self::FORBIDDEN_DIRECTIVES ) );
			// Surface a warning so admins know the override was silently blocked.
			do_action(
				'wp_csp_forbidden_directive_stripped',
				array_keys( $forbidden_found ),
				$surface
			);
		}

		// Inject nonce into script-src and style-src.
		if ( ! empty( $nonce ) ) {
			foreach ( array( 'script-src', 'script-src-elem', 'style-src', 'style-src-elem' ) as $dir ) {
				if ( isset( $directives[ $dir ] ) && is_array( $directives[ $dir ] ) ) {
					$directives[ $dir ][] = "'nonce-{$nonce}'";
				}
			}
		}

		// Append approved hashes from inventory.
		$hashes = $this->load_approved_hashes( $surface );
		foreach ( $hashes as $hash ) {
			$dir = $hash['directive'];
			if ( isset( $directives[ $dir ] ) && is_array( $directives[ $dir ] ) ) {
				$directives[ $dir ][] = "'{$hash['hash_algo']}-{$hash['hash_value']}'";
			}
		}

		// When strict-dynamic is active, host-based allowlists in script-src are silently
		// ignored by browsers (CSP3 §8.2). Suppress them to avoid misleading noise.
		$skip_host_sources_for = array();
		if ( ! empty( $profile['strict_dynamic'] ) && $this->gate->is_allowed( 'strict_dynamic' ) ) {
			if ( isset( $directives['script-src'] ) && ! in_array( "'strict-dynamic'", $directives['script-src'], true ) ) {
				$directives['script-src'][] = "'strict-dynamic'";
			}
			$skip_host_sources_for[] = 'script-src';
		}

		// Append approved source hosts from inventory.
		// FIX: use sanitize_text_field() not esc_attr() -- esc_attr() encodes
		// characters such as & that are invalid in HTTP header values.
		$sources = $this->load_approved_sources( $surface );
		foreach ( $sources as $src ) {
			$dir = $src['directive'];
			if ( in_array( $dir, $skip_host_sources_for, true ) ) {
				continue; // host allowlists ignored when strict-dynamic is present
			}
			if ( isset( $directives[ $dir ] ) && is_array( $directives[ $dir ] ) ) {
				$directives[ $dir ][] = sanitize_text_field( $src['source_host'] );
			}
		}

		// sandbox is a document directive that browsers ignore in CSP-Report-Only and in
		// <meta http-equiv>. Only emit it in enforce mode. A null value means disabled.
		$is_report_only = ( 'report-only' === $profile['mode'] );
		if ( $is_report_only || ! isset( $directives['sandbox'] ) || null === $directives['sandbox'] ) {
			unset( $directives['sandbox'] );
		}

		// Trusted Types directives (require-trusted-types-for, trusted-types) are disabled
		// when their value list is empty. When enabled they are always emitted as report-only
		// regardless of surface mode (Chromium-strong; Baseline widely available ~2028).
		$trusted_types_enabled = ! empty( $directives['require-trusted-types-for'] )
			&& is_array( $directives['require-trusted-types-for'] );
		if ( ! $trusted_types_enabled ) {
			unset( $directives['require-trusted-types-for'], $directives['trusted-types'] );
		}

		// Append reporting directives. The endpoint name 'csp-endpoint' must match
		// the Reporting-Endpoints header value emitted in emit_header().
		$directives['report-uri'] = array( rest_url( 'csp-manager/v1/report' ) );
		$directives['report-to']  = array( 'csp-endpoint' );

		// Serialise: each directive becomes "name src1 src2 src3".
		// An empty source list (e.g. upgrade-insecure-requests) serialises to just
		// the directive name, which is the correct form for boolean directives.
		$parts = array();
		foreach ( $directives as $directive => $sources_list ) {
			if ( ! is_array( $sources_list ) ) {
				continue;
			}
			$sources_list = array_unique( array_filter( $sources_list ) );
			$parts[]      = trim( $directive . ' ' . implode( ' ', $sources_list ) );
		}

		return implode( '; ', $parts );
	}

	// ── Surface detection ─────────────────────────────────────────────────────

	private function detect_surface(): string {
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return 'api';
		}
		if ( isset( $GLOBALS['pagenow'] ) && 'wp-login.php' === $GLOBALS['pagenow'] ) {
			return 'login';
		}
		if ( is_admin() ) {
			return 'admin';
		}
		return 'frontend';
	}

	// ── DB reads ──────────────────────────────────────────────────────────────

	protected function load_profile( string $surface ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'csp_policy_profiles';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE surface = %s LIMIT 1", $surface ), ARRAY_A );
		return ! empty( $row ) ? $row : null;
	}

	protected function load_approved_hashes( string $surface ): array {
		if ( null !== $this->hash_loader ) {
			return ( $this->hash_loader )( $surface );
		}
		global $wpdb;
		$table = $wpdb->prefix . 'csp_hash_inventory';
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT directive, hash_algo, hash_value FROM {$table} WHERE surface = %s AND status = 'active'",
				$surface
			),
			ARRAY_A
		);
		return ! empty( $rows ) ? $rows : array();
	}

	protected function load_approved_sources( string $surface ): array {
		if ( null !== $this->source_loader ) {
			return ( $this->source_loader )( $surface );
		}
		global $wpdb;
		$table = $wpdb->prefix . 'csp_source_inventory';
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT directive, source_host FROM {$table} WHERE surface = %s AND approval_state = 'approved'",
				$surface
			),
			ARRAY_A
		);
		return ! empty( $rows ) ? $rows : array();
	}
}
