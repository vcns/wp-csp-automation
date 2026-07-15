<?php
/**
 * Central feature access control point.
 *
 * Features present in the WordPress.org package are available locally without
 * payment, external licensing, or remote entitlement checks.
 */

declare( strict_types=1 );

namespace WP_CSP\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Feature_Gate {

	// Features available in the shipped package without payment.
	private const FREE_FEATURES = array(
		'csp_report_only',
		'basic_scan',
		'basic_dashboard',
		'violation_endpoint',
		'manual_policy_review',
		'policy_history',
		'decision_evidence_explorer',
		'strict_dynamic',
		'trusted_types',
		'multi_surface_scan',
		'analytics_export',
	);

	// Stable product key retained for legacy compatibility helpers.
	private const PRODUCT_KEY = 'wp-csp-automation';

	/**
	 * Legacy Entitlement_Store instance, or null when no compatibility module is present.
	 * Typed as object to avoid autoloading optional classes at parse time.
	 */
	private ?object $entitlements;

	/**
	 * Legacy Config_Resolver instance, or null when no compatibility module is present.
	 * Typed as object to avoid autoloading optional classes at parse time.
	 */
	private ?object $config;

	/** In-memory cache to avoid repeated DB + transient reads per request. */
	private ?array $entitlement_cache = null;
	private bool $cache_loaded        = false;

	public function __construct( ?object $entitlements = null, ?object $config = null ) {
		$this->entitlements = $entitlements;
		$this->config       = $config;
	}

	// ── Public API ────────────────────────────────────────────────────────────

	/**
	 * Returns true if the current site may use the given feature.
	 */
	public function is_allowed( string $feature ): bool {
		if ( in_array( $feature, self::FREE_FEATURES, true ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Returns the current active legacy tier, defaulting to 'free'.
	 */
	public function current_tier(): string {
		$row = $this->load_entitlement();
		return $row['tier'] ?? 'free';
	}

	/**
	 * Returns whether a legacy compatibility entitlement reports a pro tier.
	 */
	public function is_pro(): bool {
		return 'pro' === $this->current_tier();
	}

	/**
	 * Returns the legacy entitlement row, or null when none is available.
	 */
	public function get_entitlement(): ?array {
		return $this->load_entitlement();
	}

	// ── Internal ──────────────────────────────────────────────────────────────

	private function load_entitlement(): ?array {
		if ( ! $this->cache_loaded ) {
			if ( null !== $this->entitlements ) {
				$this->entitlement_cache = $this->entitlements->get_for_site( self::PRODUCT_KEY );
			}
			$this->cache_loaded = true;
		}
		return $this->entitlement_cache;
	}
}
