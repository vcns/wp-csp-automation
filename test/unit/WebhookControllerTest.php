<?php
/**
 * Unit tests for WP_CSP\Modules\Webhook_Controller.
 *
 * Tests HMAC-SHA256 signature verification, timestamp replay window,
 * idempotency guard, and entitlement grant dispatch.
 *
 * WP_REST_Request and WP_REST_Response are stubbed below so no WordPress
 * install is required.
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use WP_CSP\Modules\Audit_Log;
use WP_CSP\Modules\Checkout_Service;
use WP_CSP\Modules\Entitlement_Store;
use WP_CSP\Modules\Webhook_Controller;

// ── Minimal REST stubs ────────────────────────────────────────────────────────
if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		public function __construct( public string $method = 'POST', public string $route = '' ) {}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		public function __construct( public mixed $data = null, public int $status = 200 ) {}
		public function get_status(): int { return $this->status; }
		public function get_data(): mixed { return $this->data; }
	}
}

class WebhookControllerTest extends TestCase {

	private Audit_Log         $audit;
	private Entitlement_Store $entitlements;
	private Checkout_Service  $checkout;
	private string            $webhook_secret = 'whsec_testsecret';

	protected function setUp(): void {
		wp_test_reset_globals();
		update_option( 'wp_csp_webhook_secret', $this->webhook_secret );

		if (
			! class_exists( Entitlement_Store::class )
			|| ! class_exists( Checkout_Service::class )
			|| ! class_exists( Webhook_Controller::class )
		) {
			$this->markTestSkipped( 'Webhook offline modules are not available.' );
		}

		$this->audit        = $this->createMock( Audit_Log::class );
		$this->entitlements = $this->createMock( Entitlement_Store::class );
		$this->checkout     = $this->createMock( Checkout_Service::class );
	}

	// ── Signature verification ────────────────────────────────────────────────

	public function test_valid_signature_is_accepted(): void {
		$controller = $this->make_controller();
		$payload    = $this->make_completed_session_payload();
		$sig        = $this->sign( $payload );

		$response = $controller->call_handle( $payload, $sig );

		$this->assertNotSame( 401, $response->get_status() );
	}

	public function test_invalid_signature_returns_401(): void {
		$controller = $this->make_controller();
		$payload    = $this->make_completed_session_payload();
		$sig        = $this->sign( $payload, secret: 'wrong_secret' );

		$response = $controller->call_handle( $payload, $sig );

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_missing_signature_returns_400(): void {
		$controller = $this->make_controller();

		$response = $controller->call_handle( '{"id":"evt_1"}', '' );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_empty_body_returns_400(): void {
		$controller = $this->make_controller();
		$sig        = $this->sign( '' );

		$response = $controller->call_handle( '', $sig );

		$this->assertSame( 400, $response->get_status() );
	}

	// ── Timestamp replay window ───────────────────────────────────────────────

	public function test_timestamp_outside_tolerance_returns_401(): void {
		$controller = $this->make_controller();
		$payload    = $this->make_completed_session_payload();

		// Build a signature with a timestamp 10 minutes in the past (beyond 5-minute window).
		$stale_timestamp = time() - 600;
		$sig             = $this->sign( $payload, timestamp: $stale_timestamp );

		$response = $controller->call_handle( $payload, $sig );

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_timestamp_within_tolerance_is_accepted(): void {
		$controller = $this->make_controller();
		$payload    = $this->make_completed_session_payload();

		// Timestamp 4 minutes in the past (within 5-minute window).
		$recent_timestamp = time() - 240;
		$sig              = $this->sign( $payload, timestamp: $recent_timestamp );

		$response = $controller->call_handle( $payload, $sig );

		$this->assertNotSame( 401, $response->get_status() );
	}

	// ── Idempotency ───────────────────────────────────────────────────────────

	public function test_duplicate_event_returns_already_processed(): void {
		$controller = $this->make_controller( already_processed: true );
		$payload    = $this->make_completed_session_payload();
		$sig        = $this->sign( $payload );

		$response = $controller->call_handle( $payload, $sig );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'already_processed', $response->get_data()['status'] );
	}

	// ── Entitlement dispatch ──────────────────────────────────────────────────

	public function test_completed_session_with_paid_status_grants_entitlement(): void {
		$this->entitlements
			->expects( $this->once() )
			->method( 'grant' );

		$controller = $this->make_controller();
		$payload    = $this->make_completed_session_payload();
		$sig        = $this->sign( $payload );

		$response = $controller->call_handle( $payload, $sig );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'fulfilled', $response->get_data()['status'] );
	}

	public function test_completed_session_with_unpaid_status_skips_grant(): void {
		$this->entitlements
			->expects( $this->never() )
			->method( 'grant' );

		$controller = $this->make_controller();
		$payload    = $this->make_completed_session_payload( payment_status: 'unpaid' );
		$sig        = $this->sign( $payload );

		$response = $controller->call_handle( $payload, $sig );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'skipped_not_paid', $response->get_data()['status'] );
	}

	public function test_completed_session_missing_metadata_skips_grant(): void {
		$this->entitlements
			->expects( $this->never() )
			->method( 'grant' );

		$controller = $this->make_controller();
		$payload    = json_encode( [
			'id'   => 'evt_missing_meta',
			'type' => 'checkout.session.completed',
			'data' => [
				'object' => [
					'id'             => 'cs_test_missing',
					'payment_status' => 'paid',
					'metadata'       => [],  // no product_key or site_identity
				],
			],
		] );
		$sig = $this->sign( $payload );

		$response = $controller->call_handle( $payload, $sig );

		$this->assertSame( 'bad_metadata', $response->get_data()['status'] );
	}

	public function test_unhandled_event_type_returns_ignored(): void {
		$controller = $this->make_controller();
		$payload    = json_encode( [
			'id'   => 'evt_unknown',
			'type' => 'customer.created',
			'data' => [ 'object' => [] ],
		] );
		$sig = $this->sign( $payload );

		$response = $controller->call_handle( $payload, $sig );

		$this->assertSame( 'ignored', $response->get_data()['status'] );
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	private function make_controller( bool $already_processed = false ): object {
		$entitlements = $this->entitlements;
		$audit        = $this->audit;
		$checkout     = $this->checkout;

		return new class( $entitlements, $audit, $checkout, $already_processed ) extends Webhook_Controller {

			public function __construct(
				Entitlement_Store $entitlements,
				Audit_Log $audit,
				Checkout_Service $checkout,
				private bool $already_processed
			) {
				parent::__construct( $entitlements, $audit, $checkout );
			}

			/**
			 * Exposes the internal dispatch flow for testing without needing
			 * php://input or real HTTP headers.
			 */
			public function call_handle( string $raw_body, string $sig_header ): WP_REST_Response {
				if ( empty( $raw_body ) || empty( $sig_header ) ) {
					return new WP_REST_Response( [ 'error' => 'bad_request' ], 400 );
				}

				$secret = (string) get_option( 'wp_csp_webhook_secret', '' );

				$verify = new ReflectionMethod( $this, 'verify_signature' );
				$verify->setAccessible( true );
				if ( ! $verify->invoke( $this, $raw_body, $sig_header, $secret ) ) {
					return new WP_REST_Response( [ 'error' => 'unauthorized' ], 401 );
				}

				$event = json_decode( $raw_body, true );
				if ( ! is_array( $event ) || empty( $event['id'] ) || empty( $event['type'] ) ) {
					return new WP_REST_Response( [ 'error' => 'malformed_event' ], 400 );
				}

				// Stub idempotency check.
				if ( $this->already_processed ) {
					return new WP_REST_Response( [ 'status' => 'already_processed' ], 200 );
				}

				$dispatch = new ReflectionMethod( $this, 'dispatch' );
				$dispatch->setAccessible( true );
				return $dispatch->invoke( $this, $event );
			}

			protected function is_already_processed( string $event_id ): bool {
				return $this->already_processed;
			}

			protected function record_event(
				string $event_id,
				?string $session_id,
				string $event_type,
				string $outcome,
				string $detail
			): void {
				// No-op: skip DB write in unit tests.
			}
		};
	}

	private function make_completed_session_payload( string $payment_status = 'paid' ): string {
		$site_identity = substr( hash( 'sha256', get_site_url() ), 0, 48 );

		return json_encode( [
			'id'   => 'evt_test_completed',
			'type' => 'checkout.session.completed',
			'data' => [
				'object' => [
					'id'             => 'cs_test_abc123',
					'payment_status' => $payment_status,
					'customer'       => 'cus_test',
					'payment_intent' => 'pi_test',
					'metadata'       => [
						'product_key'   => 'wp-csp-automation',
						'site_identity' => $site_identity,
					],
				],
			],
		] );
	}

	/**
	 * Generates a valid Stripe-Signature header value for the given payload.
	 */
	private function sign(
		string $payload,
		string $secret = '',
		int $timestamp = 0
	): string {
		$secret    = $secret ?: $this->webhook_secret;
		$timestamp = $timestamp ?: time();
		$signed    = $timestamp . '.' . $payload;
		$v1        = hash_hmac( 'sha256', $signed, $secret );
		return "t={$timestamp},v1={$v1}";
	}
}
