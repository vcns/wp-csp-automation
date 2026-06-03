<?php
/**
 * Computes and manages SHA-256 hashes for inline script and style blocks.
 *
 * Implements §4.5 of the directive:
 *   - Hooks into wp_head / admin_head output buffering to capture inline blocks.
 *   - Computes sha256 hash, base64-encodes it, stores in csp_hash_inventory.
 *   - Retires hashes whose content has changed (fingerprint mismatch).
 *   - Output buffering is used conservatively and flushed before headers are sent.
 *
 * Note: hash-based inline approval is a premium alternative to nonces when
 * the inline content is truly static. For dynamic inline blocks, nonces remain
 * the preferred path.
 */

declare( strict_types=1 );

namespace WP_CSP\CSP;

use WP_CSP\Modules\Audit_Log;
use WP_CSP\Modules\Feature_Gate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hash_Manager {

	private Audit_Log    $audit;
	private Feature_Gate $gate;

	public function __construct( Audit_Log $audit, Feature_Gate $gate ) {
		$this->audit = $audit;
		$this->gate  = $gate;
	}

	// ── Public API ────────────────────────────────────────────────────────────

	/**
	 * Computes an inline hash and stores it in the DB inventory.
	 *
	 * @param string $content    Raw inline script or style content (without tags).
	 * @param string $directive  'script-src' or 'style-src'.
	 * @param string $surface    'frontend' | 'admin' | 'login' | 'api'.
	 * @param string $source_file  Optional: file where the inline block originates.
	 * @return string            'sha256-{base64}' value for direct use in CSP header.
	 */
	public function record_hash(
		string $content,
		string $directive,
		string $surface,
		string $source_file = ''
	): string {
		$hash_raw    = hash( 'sha256', $content, true );
		$hash_b64    = base64_encode( $hash_raw );
		$fingerprint = hash( 'sha256', $content );

		$this->upsert( $hash_b64, $fingerprint, $directive, $surface, $source_file );

		return "sha256-{$hash_b64}";
	}

	/**
	 * Runs a hash audit: retires any stored hashes for inline blocks that no
	 * longer exist or whose content fingerprint has changed.
	 *
	 * Called from Scheduler::run_daily_scan().
	 *
	 * @param  array  $current_hashes  Array of [ hash_value => fingerprint ] from current crawl.
	 * @param  string $surface
	 * @return int    Number of hashes retired.
	 */
	public function retire_stale( array $current_hashes, string $surface ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'csp_hash_inventory';
		$now   = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$all = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, hash_value, content_fingerprint FROM {$table} WHERE surface = %s AND status = 'active'",
				$surface
			),
			ARRAY_A
		) ?: [];

		$retired = 0;
		foreach ( $all as $row ) {
			$hv = $row['hash_value'];
			if ( ! isset( $current_hashes[ $hv ] ) || $current_hashes[ $hv ] !== $row['content_fingerprint'] ) {
				$wpdb->update(
					$table,
					[ 'status' => 'retired', 'retired_at' => $now ],
					[ 'id' => (int) $row['id'] ],
					[ '%s', '%s' ],
					[ '%d' ]
				);
				++$retired;
			}
		}

		return $retired;
	}

	/**
	 * Returns all active hashes for a surface as 'sha256-{b64}' strings.
	 */
	public function get_active_hashes( string $surface, string $directive ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'csp_hash_inventory';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT hash_algo, hash_value FROM {$table} WHERE surface = %s AND directive = %s AND status = 'active'",
				$surface,
				$directive
			),
			ARRAY_A
		) ?: [];

		return array_map(
			static fn( $r ) => "{$r['hash_algo']}-{$r['hash_value']}",
			$rows
		);
	}

	// ── Internal ──────────────────────────────────────────────────────────────

	private function upsert(
		string $hash_b64,
		string $fingerprint,
		string $directive,
		string $surface,
		string $source_file
	): void {
		global $wpdb;
		$table = $wpdb->prefix . 'csp_hash_inventory';
		$now   = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE directive = %s AND hash_value = %s LIMIT 1",
				$directive,
				$hash_b64
			)
		);

		if ( $existing ) {
			$wpdb->update(
				$table,
				[ 'last_seen_at' => $now, 'status' => 'active' ],
				[ 'id' => (int) $existing ],
				[ '%s', '%s' ],
				[ '%d' ]
			);
		} else {
			$wpdb->insert(
				$table,
				[
					'surface'             => $surface,
					'directive'           => $directive,
					'hash_algo'           => 'sha256',
					'hash_value'          => $hash_b64,
					'content_fingerprint' => $fingerprint,
					'source_file'         => sanitize_text_field( $source_file ),
					'status'              => 'active',
					'first_seen_at'       => $now,
					'last_seen_at'        => $now,
				],
				[ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
			);
		}
	}
}
