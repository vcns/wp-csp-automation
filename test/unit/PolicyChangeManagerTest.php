<?php
/**
 * Unit tests for WP_CSP\CSP\Policy_Change_Manager.
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use WP_CSP\CSP\Policy_Change_Manager;
use WP_CSP\Modules\Audit_Log;

class PolicyChangeManagerTest extends TestCase {

	private Audit_Log $audit;
	private Policy_Change_Manager $manager;

	protected function setUp(): void {
		wp_test_reset_globals();
		$this->audit   = $this->createMock( Audit_Log::class );
		$this->manager = new Policy_Change_Manager( $this->audit );
	}

	public function test_high_risk_script_source_is_inserted_as_pending_proposal(): void {
		$GLOBALS['_wpdb_get_row_queue'] = array( null, null );

		$result = $this->manager->propose_source(
			'frontend',
			array(
				'directive' => 'script-src',
				'uri'       => 'https://cdn.vendor.example/app.js',
				'scheme'    => 'https',
				'host'      => 'cdn.vendor.example',
			),
			'discovery',
			'crawl',
			'Learned during scan.'
		);

		$this->assertSame( 'added', $result['status'] );
		$this->assertSame( 'high', $result['risk_level'] );
		$this->assertCount( 1, $GLOBALS['_wpdb_inserted_rows'] );

		$insert = $GLOBALS['_wpdb_inserted_rows'][0];
		$this->assertSame( 'wp_csp_source_inventory', $insert['table'] );
		$this->assertSame( 'pending', $insert['data']['approval_state'] );
		$this->assertSame( 'high', $insert['data']['risk_level'] );
		$this->assertSame( 'discovery', $insert['data']['owner_component'] );
		$this->assertSame( 1, $insert['data']['evidence_count'] );
		$this->assertNotEmpty( $insert['data']['decision_fingerprint'] );
	}

	public function test_latest_rejection_suppresses_matching_future_candidate(): void {
		$GLOBALS['_wpdb_get_row'] = array(
			'action'             => 'rejected',
			'suppression_active' => 1,
		);

		$this->assertTrue( $this->manager->is_suppressed( 'frontend', 'script-src', 'cdn.vendor.example' ) );
	}

	public function test_latest_approval_clears_prior_suppression(): void {
		$GLOBALS['_wpdb_get_row'] = array(
			'action'             => 'approved',
			'suppression_active' => 0,
		);

		$this->assertFalse( $this->manager->is_suppressed( 'frontend', 'script-src', 'cdn.vendor.example' ) );
	}

	public function test_revert_marks_source_denied_and_records_suppression_decision(): void {
		$GLOBALS['_wpdb_get_row'] = array(
			'id'                   => 7,
			'surface'              => 'frontend',
			'directive'            => 'connect-src',
			'source_host'          => 'api.vendor.example',
			'source_uri'           => 'https://api.vendor.example/v1',
			'decision_fingerprint' => Policy_Change_Manager::fingerprint( 'frontend', 'connect-src', 'api.vendor.example' ),
			'risk_level'           => 'high',
			'risk_reason'          => 'connect-src can materially change connection behavior',
		);

		$this->assertTrue( $this->manager->revert_source( 7, 'No longer required.' ) );

		$this->assertCount( 1, $GLOBALS['_wpdb_updated_rows'] );
		$this->assertSame( 'wp_csp_source_inventory', $GLOBALS['_wpdb_updated_rows'][0]['table'] );
		$this->assertSame( 'denied', $GLOBALS['_wpdb_updated_rows'][0]['data']['approval_state'] );
		$this->assertSame( 'reverted', $GLOBALS['_wpdb_updated_rows'][0]['data']['last_decision'] );

		$this->assertCount( 1, $GLOBALS['_wpdb_inserted_rows'] );
		$decision = $GLOBALS['_wpdb_inserted_rows'][0]['data'];
		$this->assertSame( 'reverted', $decision['action'] );
		$this->assertSame( 1, $decision['suppression_active'] );
		$this->assertSame( 'No longer required.', $decision['reason'] );
	}
}
