<?php
/**
 * Unit tests for WP_CSP\Modules\Feature_Gate.
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use WP_CSP\Modules\Feature_Gate;

class FeatureGateTest extends TestCase {

	protected function setUp(): void {
		wp_test_reset_globals();
	}

	public function test_shipped_features_are_available_locally(): void {
		$gate = new Feature_Gate();

		foreach (
			array(
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
			) as $feature
		) {
			$this->assertTrue( $gate->is_allowed( $feature ), "Expected '{$feature}' to be locally available." );
		}
	}

	public function test_unknown_feature_is_denied(): void {
		$gate = new Feature_Gate();

		$this->assertFalse( $gate->is_allowed( 'unknown_feature' ) );
	}

	public function test_current_tier_returns_free_with_no_entitlement(): void {
		$gate = new Feature_Gate();

		$this->assertSame( 'free', $gate->current_tier() );
	}

	public function test_current_tier_returns_tier_from_entitlement_row_for_backward_compatibility(): void {
		$entitlements = $this->make_entitlement_stub( 'pro' );

		$gate = new Feature_Gate( $entitlements );

		$this->assertSame( 'pro', $gate->current_tier() );
	}

	public function test_is_pro_false_for_free_tier(): void {
		$gate = new Feature_Gate();

		$this->assertFalse( $gate->is_pro() );
	}

	public function test_is_pro_true_when_tier_is_pro(): void {
		$entitlements = $this->make_entitlement_stub( 'pro' );

		$gate = new Feature_Gate( $entitlements );

		$this->assertTrue( $gate->is_pro() );
	}

	public function test_get_entitlement_returns_null_for_free_tier(): void {
		$gate = new Feature_Gate();

		$this->assertNull( $gate->get_entitlement() );
	}

	public function test_get_entitlement_returns_array_when_entitled_for_backward_compatibility(): void {
		$entitlements = $this->make_entitlement_stub( 'pro' );

		$gate   = new Feature_Gate( $entitlements );
		$result = $gate->get_entitlement();

		$this->assertIsArray( $result );
		$this->assertSame( 'pro', $result['tier'] );
	}

	public function test_entitlement_store_queried_only_when_tier_or_entitlement_is_requested(): void {
		$call_count   = 0;
		$entitlements = new class( $call_count ) {
			public function __construct( private int &$calls ) {}

			public function get_for_site( string $product_key ): ?array {
				++$this->calls;
				return array( 'tier' => 'pro', 'status' => 'active' );
			}
		};

		$gate = new Feature_Gate( $entitlements );

		$gate->is_allowed( 'strict_dynamic' );
		$gate->is_allowed( 'trusted_types' );
		$this->assertSame( 0, $call_count );

		$gate->current_tier();
		$gate->get_entitlement();

		$this->assertSame( 1, $call_count );
	}

	private function make_entitlement_stub( string $tier ): object {
		return new class( $tier ) {
			public function __construct( private string $tier ) {}

			public function get_for_site( string $product_key ): ?array {
				return array( 'tier' => $this->tier, 'status' => 'active' );
			}
		};
	}
}
