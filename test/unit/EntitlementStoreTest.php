<?php
/**
 * Unit tests for WP_CSP\Modules\Entitlement_Store.
 *
 * DB interactions are exercised through the wpdb_stub configured in bootstrap.
 * HTTP calls in sync_from_worker() are exercised through the wp_remote_get stub.
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use WP_CSP\Modules\Entitlement_Store;
use WP_CSP\Modules\Audit_Log;

class EntitlementStoreTest extends TestCase {

	private Audit_Log $audit;

	protected function setUp(): void {
		wp_test_reset_globals();
		$this->audit = $this->createMock( Audit_Log::class );
	}

	// ── get_site_identity() ───────────────────────────────────────────────────

	public function test_get_site_identity_returns_48_char_hex_string(): void {
		$store    = new Entitlement_Store( $this->audit );
		$identity = $store->get_site_identity();

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{48}$/', $identity );
	}

	public function test_get_site_identity_is_deterministic(): void {
		$store1 = new Entitlement_Store( $this->audit );
		$store2 = new Entitlement_Store( $this->audit );

		$this->assertSame( $store1->get_site_identity(), $store2->get_site_identity() );
	}

	public function test_get_site_identity_derived_from_site_url(): void {
		$store    = new Entitlement_Store( $this->audit );
		$identity = $store->get_site_identity();
		$expected = substr( hash( 'sha256', get_site_url() ), 0, 48 );

		$this->assertSame( $expected, $identity );
	}

	// ── get_for_site() — active local row ────────────────────────────────────

	public function test_get_for_site_returns_active_row_from_db(): void {
		$row = array(
			'id'            => 1,
			'tier'          => 'pro',
			'status'        => 'active',
			'site_identity' => substr( hash( 'sha256', 'https://example.com' ), 0, 48 ),
			'product_key'   => 'wp-csp-automation',
		);
		$GLOBALS['_wpdb_get_row'] = $row;

		$store  = new Entitlement_Store( $this->audit );
		$result = $store->get_for_site( 'wp-csp-automation' );

		$this->assertIsArray( $result );
		$this->assertSame( 'pro', $result['tier'] );
	}

	// ── get_for_site() — no local row, worker fails gracefully ───────────────

	public function test_get_for_site_returns_null_when_db_empty_and_worker_fails(): void {
		$GLOBALS['_wpdb_get_row']           = null;
		$GLOBALS['_wp_remote_get_response'] = array(
			'response' => array( 'code' => 404 ),
			'body'     => '',
		);

		$store  = new Entitlement_Store( $this->audit );
		$result = $store->get_for_site( 'wp-csp-automation' );

		$this->assertNull( $result );
	}

	public function test_get_for_site_returns_null_when_worker_returns_wp_error(): void {
		$GLOBALS['_wpdb_get_row']           = null;
		$GLOBALS['_wp_remote_get_response'] = new WP_Error( 'http_request_failed', 'cURL error' );

		$store  = new Entitlement_Store( $this->audit );
		$result = $store->get_for_site( 'wp-csp-automation' );

		$this->assertNull( $result );
	}

	// ── revoke() ─────────────────────────────────────────────────────────────

	public function test_revoke_returns_true_when_db_updates_a_row(): void {
		$GLOBALS['_wpdb_update_result'] = 1;

		$store  = new Entitlement_Store( $this->audit );
		$result = $store->revoke( 'site-id', 'wp-csp-automation', 'test reason' );

		$this->assertTrue( $result );
	}

	public function test_revoke_returns_false_when_no_row_updated(): void {
		$GLOBALS['_wpdb_update_result'] = 0;

		$store  = new Entitlement_Store( $this->audit );
		$result = $store->revoke( 'site-id', 'wp-csp-automation', 'test reason' );

		$this->assertFalse( $result );
	}

	// ── grant() idempotency ───────────────────────────────────────────────────

	public function test_grant_is_noop_when_session_already_recorded(): void {
		// Simulate the idempotency check: get_var returns an existing ID.
		$GLOBALS['_wpdb_get_var'] = '42';

		// audit->log() must NOT be called if the session already exists.
		$this->audit->expects( $this->never() )->method( 'log' );

		$store = new Entitlement_Store( $this->audit );
		$store->grant( 'site-id', 'wp-csp-automation', 'cs_test_123', 'cus_xxx', 'pi_xxx' );
	}

	public function test_grant_logs_audit_event_for_new_session(): void {
		// Idempotency check: no existing session.
		$GLOBALS['_wpdb_get_var'] = null;

		$this->audit->expects( $this->once() )
			->method( 'log' )
			->with( 'entitlement', 'granted', $this->anything() );

		$store = new Entitlement_Store( $this->audit );
		$store->grant( 'site-id', 'wp-csp-automation', 'cs_test_new', 'cus_yyy', 'pi_yyy' );
	}
}
