<?php
/**
 * Unit tests for WP_CSP\Modules\Feature_Gate.
 *
 * Exercises free-tier short-circuit, premium delegation via config stub,
 * tier resolution, is_pro(), and the in-memory cache guard.
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use WP_CSP\Modules\Feature_Gate;

class FeatureGateTest extends TestCase {

	protected function setUp(): void {
		wp_test_reset_globals();
	}

	// ── Free features ─────────────────────────────────────────────────────────

	public function test_free_features_are_always_allowed(): void {
		$gate = new Feature_Gate();

		foreach ( array( 'csp_report_only', 'basic_scan', 'basic_dashboard', 'violation_endpoint' ) as $feature ) {
			$this->assertTrue( $gate->is_allowed( $feature ), "Expected '{$feature}' to be a free feature." );
		}
	}

	// ── Premium without config ────────────────────────────────────────────────

	public function test_strict_dynamic_denied_when_no_config(): void {
		$gate = new Feature_Gate();

		$this->assertFalse( $gate->is_allowed( 'strict_dynamic' ) );
	}

	public function test_trusted_types_denied_when_no_config(): void {
		$gate = new Feature_Gate();

		$this->assertFalse( $gate->is_allowed( 'trusted_types' ) );
	}

	public function test_multi_surface_scan_denied_when_no_config(): void {
		$gate = new Feature_Gate();

		$this->assertFalse( $gate->is_allowed( 'multi_surface_scan' ) );
	}

	// ── Premium with config ───────────────────────────────────────────────────

	public function test_premium_feature_allowed_when_config_grants_it(): void {
		$entitlements = $this->make_entitlement_stub( 'pro' );
		$config       = $this->make_config_stub( array( 'strict_dynamic' ) );

		$gate = new Feature_Gate( $entitlements, $config );

		$this->assertTrue( $gate->is_allowed( 'strict_dynamic' ) );
	}

	public function test_premium_feature_denied_when_config_does_not_include_it(): void {
		$entitlements = $this->make_entitlement_stub( 'pro' );
		$config       = $this->make_config_stub( array() );

		$gate = new Feature_Gate( $entitlements, $config );

		$this->assertFalse( $gate->is_allowed( 'strict_dynamic' ) );
	}

	public function test_multiple_premium_features_resolved_independently(): void {
		$entitlements = $this->make_entitlement_stub( 'pro' );
		$config       = $this->make_config_stub( array( 'strict_dynamic', 'trusted_types' ) );

		$gate = new Feature_Gate( $entitlements, $config );

		$this->assertTrue( $gate->is_allowed( 'strict_dynamic' ) );
		$this->assertTrue( $gate->is_allowed( 'trusted_types' ) );
		$this->assertFalse( $gate->is_allowed( 'multi_surface_scan' ) );
	}

	// ── Tier resolution ───────────────────────────────────────────────────────

	public function test_current_tier_returns_free_with_no_entitlement(): void {
		$gate = new Feature_Gate();

		$this->assertSame( 'free', $gate->current_tier() );
	}

	public function test_current_tier_returns_tier_from_entitlement_row(): void {
		$entitlements = $this->make_entitlement_stub( 'pro' );

		$gate = new Feature_Gate( $entitlements );

		$this->assertSame( 'pro', $gate->current_tier() );
	}

	// ── is_pro() ─────────────────────────────────────────────────────────────

	public function test_is_pro_false_for_free_tier(): void {
		$gate = new Feature_Gate();

		$this->assertFalse( $gate->is_pro() );
	}

	public function test_is_pro_true_when_tier_is_pro(): void {
		$entitlements = $this->make_entitlement_stub( 'pro' );

		$gate = new Feature_Gate( $entitlements );

		$this->assertTrue( $gate->is_pro() );
	}

	// ── get_entitlement() ────────────────────────────────────────────────────

	public function test_get_entitlement_returns_null_for_free_tier(): void {
		$gate = new Feature_Gate();

		$this->assertNull( $gate->get_entitlement() );
	}

	public function test_get_entitlement_returns_array_when_entitled(): void {
		$entitlements = $this->make_entitlement_stub( 'pro' );

		$gate   = new Feature_Gate( $entitlements );
		$result = $gate->get_entitlement();

		$this->assertIsArray( $result );
		$this->assertSame( 'pro', $result['tier'] );
	}

	// ── In-memory cache ───────────────────────────────────────────────────────

	public function test_entitlement_store_queried_only_once_per_request(): void {
		$call_count   = 0;
		$entitlements = new class( $call_count ) {
			public function __construct( private int &$calls ) {}

			public function get_for_site( string $product_key ): ?array {
				++$this->calls;
				return array( 'tier' => 'pro', 'status' => 'active' );
			}
		};

		$gate = new Feature_Gate( $entitlements, $this->make_config_stub( array( 'strict_dynamic' ) ) );

		$gate->is_allowed( 'strict_dynamic' );
		$gate->is_allowed( 'trusted_types' );
		$gate->current_tier();
		$gate->get_entitlement();

		$this->assertSame( 1, $call_count );
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	private function make_entitlement_stub( string $tier ): object {
		return new class( $tier ) {
			public function __construct( private string $tier ) {}

			public function get_for_site( string $product_key ): ?array {
				return array( 'tier' => $this->tier, 'status' => 'active' );
			}
		};
	}

	private function make_config_stub( array $allowed_features ): object {
		return new class( $allowed_features ) {
			public function __construct( private array $features ) {}

			public function tier_has_feature( string $tier, string $feature ): bool {
				return in_array( $feature, $this->features, true );
			}
		};
	}
}
