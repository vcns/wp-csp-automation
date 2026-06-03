<?php
/**
 * Reads and writes entitlement records in the csp_entitlements table.
 *
 * An entitlement binds a product tier to a site identity (stable hash of
 * the site URL). Access is never granted here directly – only via the
 * Webhook_Controller after a verified Stripe event.
 */

declare( strict_types=1 );

namespace WP_CSP\Modules;

use WP_CSP\Modules\Audit_Log;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Entitlement_Store {

	private const STATUS_ACTIVE  = 'active';
	private const STATUS_REVOKED = 'revoked';
	private const STATUS_EXPIRED = 'expired';
	private const STATUS_GRACE   = 'grace';

	private Audit_Log $audit;

	public function __construct( Audit_Log $audit ) {
		$this->audit = $audit;
	}

	// ── Write operations ──────────────────────────────────────────────────────

	/**
	 * Persists a new entitlement following a successful Stripe payment.
	 * Called exclusively from Webhook_Controller after signature verification.
	 */
	public function grant(
		string $site_identity,
		string $product_key,
		string $stripe_session_id,
		string $stripe_customer_id,
		string $stripe_payment_intent_id
	): void {
		global $wpdb;

		$now   = current_time( 'mysql', true );
		$table = $wpdb->prefix . 'csp_entitlements';

		// Idempotent: if this session was already fulfilled, do nothing.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE stripe_session_id = %s LIMIT 1", $stripe_session_id ) );
		if ( $existing ) {
			return;
		}

		$wpdb->insert(
			$table,
			[
				'site_identity'             => $site_identity,
				'product_key'               => $product_key,
				'tier'                      => $this->resolve_tier( $product_key ),
				'status'                    => self::STATUS_ACTIVE,
				'stripe_customer_id'        => $stripe_customer_id,
				'stripe_session_id'         => $stripe_session_id,
				'stripe_payment_intent_id'  => $stripe_payment_intent_id,
				'granted_at'                => $now,
				'last_validated_at'         => $now,
				'created_at'                => $now,
				'updated_at'                => $now,
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);

		$this->audit->log( 'entitlement', 'granted', "Granted '{$product_key}' for site identity {$site_identity}." );
	}

	/**
	 * Revokes an entitlement for a site+product pair.
	 */
	public function revoke( string $site_identity, string $product_key, string $reason ): bool {
		global $wpdb;
		$now = current_time( 'mysql', true );

		$rows = $wpdb->update(
			$wpdb->prefix . 'csp_entitlements',
			[
				'status'            => self::STATUS_REVOKED,
				'revoked_at'        => $now,
				'revocation_reason' => sanitize_text_field( $reason ),
				'updated_at'        => $now,
			],
			[
				'site_identity' => $site_identity,
				'product_key'   => $product_key,
				'status'        => self::STATUS_ACTIVE,
			],
			[ '%s', '%s', '%s', '%s' ],
			[ '%s', '%s', '%s' ]
		);

		return (bool) $rows;
	}

	/**
	 * Records the last validation timestamp, extending the grace window.
	 */
	public function touch( int $entitlement_id ): void {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'csp_entitlements',
			[
				'last_validated_at' => current_time( 'mysql', true ),
				'updated_at'        => current_time( 'mysql', true ),
			],
			[ 'id' => $entitlement_id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);
	}

	// ── Read operations ───────────────────────────────────────────────────────

	/**
	 * Returns the current site's entitlement row for the given product, or null.
	 * Considers both ACTIVE and GRACE states as granting access.
	 */
	public function get_for_site( string $product_key ): ?array {
		global $wpdb;
		$table         = $wpdb->prefix . 'csp_entitlements';
		$site_identity = $this->get_site_identity();
		$grace_hours   = (int) get_option( 'wp_csp_entitlement_grace_hours', 72 );
		$grace_cutoff  = gmdate( 'Y-m-d H:i:s', time() - ( $grace_hours * HOUR_IN_SECONDS ) );

		// Active or within grace window.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				WHERE site_identity = %s
				  AND product_key = %s
				  AND (
				      status = 'active'
				      OR ( status = 'grace' AND last_validated_at > %s )
				  )
				ORDER BY granted_at DESC
				LIMIT 1",
				$site_identity,
				$product_key,
				$grace_cutoff
			),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Returns all entitlement rows for admin display.
	 */
	public function get_all(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'csp_entitlements';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC", ARRAY_A ) ?: [];
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	public function get_site_identity(): string {
		return substr( hash( 'sha256', get_site_url() ), 0, 48 );
	}

	/**
	 * Derives the tier name from a product key.
	 * Extend as product catalog grows.
	 */
	private function resolve_tier( string $product_key ): string {
		$map = [
			'wp-csp-pro' => 'pro',
		];
		return $map[ $product_key ] ?? 'pro';
	}
}
