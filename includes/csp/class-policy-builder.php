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
 *   - 'strict-dynamic' added to script-src when profile enables it (pro feature).
 */

declare( strict_types=1 );

namespace WP_CSP\CSP;

use WP_CSP\Modules\Feature_Gate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Policy_Builder {

	private Feature_Gate $gate;

	public function __construct( Feature_Gate $gate ) {
		$this->gate = $gate;
	}

	// ── Bootstrap ─────────────────────────────────────────────────────────────

	public function register(): void {
		// send_headers fires before any output, ideal for emitting CSP.
		add_action( 'send_headers', [ $this, 'emit_header' ] );
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

		header( $header_name . ': ' . $policy );
	}

	// ── Policy assembly ───────────────────────────────────────────────────────

	public function build_policy_string( array $profile, string $surface ): string {
		$nonce      = Plugin_Nonce_Manager::get_instance_nonce();
		$directives = json_decode( $profile['directives'], true );
		$overrides  = json_decode( $profile['overrides'],  true );

		if ( ! is_array( $directives ) ) {
			return '';
		}

		// Merge admin overrides on top of base directives.
		if ( is_array( $overrides ) ) {
			foreach ( $overrides as $dir => $sources ) {
				$directives[ $dir ] = $sources;
			}
		}

		// Inject nonce into script-src and style-src.
		if ( ! empty( $nonce ) ) {
			foreach ( [ 'script-src', 'script-src-elem', 'style-src', 'style-src-elem' ] as $dir ) {
				if ( isset( $directives[ $dir ] ) ) {
					$directives[ $dir ][] = "'nonce-{$nonce}'";
				}
			}
		}

		// Append approved hashes from inventory.
		$hashes = $this->load_approved_hashes( $surface );
		foreach ( $hashes as $hash ) {
			$dir = $hash['directive'];
			if ( isset( $directives[ $dir ] ) ) {
				$directives[ $dir ][] = "'{$hash['hash_algo']}-{$hash['hash_value']}'";
			}
		}

		// Append approved source hosts from inventory.
		$sources = $this->load_approved_sources( $surface );
		foreach ( $sources as $src ) {
			$dir = $src['directive'];
			if ( isset( $directives[ $dir ] ) ) {
				$directives[ $dir ][] = esc_attr( $src['source_host'] );
			}
		}

		// Add strict-dynamic to script-src if profile enables it (pro feature).
		if ( ! empty( $profile['strict_dynamic'] ) && $this->gate->is_allowed( 'strict_dynamic' ) ) {
			if ( isset( $directives['script-src'] ) && ! in_array( "'strict-dynamic'", $directives['script-src'], true ) ) {
				$directives['script-src'][] = "'strict-dynamic'";
			}
		}

		// Append reporting directive.
		$report_uri = rest_url( 'csp-manager/v1/report' );
		$directives['report-uri'] = [ $report_uri ];
		$directives['report-to']  = [ 'csp-endpoint' ];

		// Serialise: each directive becomes "name src1 src2 src3".
		$parts = [];
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

	private function load_profile( string $surface ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'csp_policy_profiles';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE surface = %s LIMIT 1", $surface ), ARRAY_A );
		return $row ?: null;
	}

	private function load_approved_hashes( string $surface ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'csp_hash_inventory';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT directive, hash_algo, hash_value FROM {$table} WHERE surface = %s AND status = 'active'",
				$surface
			),
			ARRAY_A
		) ?: [];
	}

	private function load_approved_sources( string $surface ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'csp_source_inventory';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT directive, source_host FROM {$table} WHERE surface = %s AND approval_state = 'approved'",
				$surface
			),
			ARRAY_A
		) ?: [];
	}
}

/**
 * Thin static bridge so Policy_Builder can read the nonce without
 * requiring a direct reference to the Nonce_Manager singleton.
 */
final class Plugin_Nonce_Manager {
	public static function get_instance_nonce(): string {
		static $nonce = null;
		if ( null === $nonce ) {
			$plugin = \WP_CSP\Plugin::instance();
			$nonce  = isset( $plugin->nonce_manager ) ? $plugin->nonce_manager->get_nonce() : '';
		}
		return $nonce;
	}
}
