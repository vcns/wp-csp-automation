<?php
/**
 * Central feature access control point.
 *
 * All premium feature checks in the plugin MUST go through this class.
 * Checks the local entitlement DB first; uses the remote config feature
 * matrix to determine what the site's current tier unlocks.
 *
 * Free features are always allowed regardless of entitlement.
 */

declare( strict_types=1 );

namespace WP_CSP\Modules;

use WP_CSP\Modules\Config_Resolver;
use WP_CSP\Modules\Entitlement_Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Feature_Gate {

	// Features available on the free tier without payment.
	private const FREE_FEATURES = array(
		'csp_report_only',
		'basic_scan',
		'basic_dashboard',
		'violation_endpoint',
	);

	// Premium feature keys (checked against the remote config feature matrix).
	// trusted_types: Trusted Types directives (require-trusted-types-for, trusted-types).
	// Always deployed in report-only mode first — Chromium-strong; Baseline ~2028 (R5).
	// strict_dynamic: adds 'strict-dynamic' to script-src; suppresses host allowlists (R4).
	// multi_surface_scan: crawl admin, login, api surfaces (frontend is always free).

	// Product key the free tier links to in the entitlement store.
	private const PRODUCT_KEY = 'wp-csp-automation';

	private Entitlement_Store $entitlements;
	private Config_Resolver $config;

	/** In-memory cache to avoid repeated DB + transient reads per request. */
	private ?array $entitlement_cache = null;
	private bool $cache_loaded        = false;

	public function __construct( Entitlement_Store $entitlements, Config_Resolver $config ) {
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

		$tier = $this->current_tier();
		return $this->config->tier_has_feature( $tier, $feature );
	}

	/**
	 * Returns the current active tier ('free' or 'pro' etc.).
	 */
	public function current_tier(): string {
		$row = $this->load_entitlement();
		return $row['tier'] ?? 'free';
	}

	/**
	 * Returns whether the site has an active paid entitlement.
	 */
	public function is_pro(): bool {
		return 'pro' === $this->current_tier();
	}

	/**
	 * Returns the entitlement row, or null for free tier.
	 */
	public function get_entitlement(): ?array {
		return $this->load_entitlement();
	}

	// ── Internal ──────────────────────────────────────────────────────────────

	private function load_entitlement(): ?array {
		if ( ! $this->cache_loaded ) {
			$this->entitlement_cache = $this->entitlements->get_for_site( self::PRODUCT_KEY );
			$this->cache_loaded      = true;
		}
		return $this->entitlement_cache;
	}
}
