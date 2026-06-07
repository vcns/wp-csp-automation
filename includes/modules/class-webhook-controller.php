<?php
/**
 * Handles incoming Stripe webhook events.
 *
 * Security model:
 *   - REST endpoint is publicly reachable (permission_callback = __return_true).
 *   - Raw request body is read before WordPress touches it.
 *   - Stripe-Signature header is verified with HMAC-SHA256 before any action.
 *   - Events are deduplicated via the csp_processed_events idempotency table.
 *   - Returns HTTP 200 quickly; fulfillment happens synchronously before response
 *     but is kept lightweight (a single DB write).
 */

declare( strict_types=1 );

namespace WP_CSP\Modules;

use WP_CSP\Modules\Audit_Log;
use WP_CSP\Modules\Checkout_Service;
use WP_CSP\Modules\Entitlement_Store;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Webhook_Controller {

	private const TIMESTAMP_TOLERANCE = 300; // seconds

	private Entitlement_Store $entitlements;
	private Audit_Log $audit;
	private Checkout_Service $checkout;

	public function __construct(
		Entitlement_Store $entitlements,
		Audit_Log $audit,
		Checkout_Service $checkout
	) {
		$this->entitlements = $entitlements;
		$this->audit        = $audit;
		$this->checkout     = $checkout;
	}

	// ── REST handler ──────────────────────────────────────────────────────────

	/**
	 * Entry point registered on POST /csp-manager/v1/stripe-webhook.
	 * WP_REST_Server has already parsed the body by the time this runs,
	 * so we read the raw body from php://input directly.
	 */
	public function handle( WP_REST_Request $request ): WP_REST_Response {
		// Read raw body before WP can alter it.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$raw_body   = file_get_contents( 'php://input' );
		$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

		if ( empty( $raw_body ) || empty( $sig_header ) ) {
			$this->audit->log( 'webhook', 'missing_payload', 'Empty body or missing Stripe-Signature header.' );
			return new WP_REST_Response( array( 'error' => 'bad_request' ), 400 );
		}

		$secret = (string) get_option( 'wp_csp_webhook_secret', '' );
		if ( empty( $secret ) ) {
			$this->audit->log( 'webhook', 'no_secret', 'Webhook endpoint secret is not configured.' );
			return new WP_REST_Response( array( 'error' => 'configuration_error' ), 500 );
		}

		if ( ! $this->verify_signature( $raw_body, $sig_header, $secret ) ) {
			$this->audit->log( 'webhook', 'signature_failed', 'Stripe-Signature verification failed.' );
			return new WP_REST_Response( array( 'error' => 'unauthorized' ), 401 );
		}

		$event = json_decode( $raw_body, true );
		if ( ! is_array( $event ) || empty( $event['id'] ) || empty( $event['type'] ) ) {
			return new WP_REST_Response( array( 'error' => 'malformed_event' ), 400 );
		}

		return $this->dispatch( $event );
	}

	// ── Event dispatch ────────────────────────────────────────────────────────

	private function dispatch( array $event ): WP_REST_Response {
		$event_id   = sanitize_text_field( $event['id'] );
		$event_type = sanitize_text_field( $event['type'] );

		// Idempotency guard.
		if ( $this->is_already_processed( $event_id ) ) {
			$this->audit->log( 'webhook', 'duplicate_event', "Event {$event_id} already processed." );
			return new WP_REST_Response( array( 'status' => 'already_processed' ), 200 );
		}

		switch ( $event_type ) {
			case 'checkout.session.completed':
			case 'checkout.session.async_payment_succeeded':
				return $this->handle_checkout_completed( $event );

			case 'checkout.session.async_payment_failed':
				return $this->handle_payment_failed( $event );

			default:
				// Accept but ignore unhandled event types so Stripe doesn't retry.
				$this->record_event( $event_id, null, $event_type, 'skipped', 'Unhandled event type.' );
				return new WP_REST_Response( array( 'status' => 'ignored' ), 200 );
		}
	}

	// ── Handlers ──────────────────────────────────────────────────────────────

	private function handle_checkout_completed( array $event ): WP_REST_Response {
		$event_id = sanitize_text_field( $event['id'] );
		$session  = $event['data']['object'] ?? array();

		if ( empty( $session['id'] ) || ( $session['payment_status'] ?? '' ) !== 'paid' ) {
			$this->record_event( $event_id, $session['id'] ?? null, $event['type'], 'skipped', 'Payment not marked paid.' );
			return new WP_REST_Response( array( 'status' => 'skipped_not_paid' ), 200 );
		}

		$session_id    = sanitize_text_field( $session['id'] );
		$metadata      = $session['metadata'] ?? array();
		$product_key   = sanitize_text_field( $metadata['product_key'] ?? '' );
		$site_identity = sanitize_text_field( $metadata['site_identity'] ?? '' );

		if ( empty( $product_key ) || empty( $site_identity ) ) {
			$this->record_event( $event_id, $session_id, $event['type'], 'failed', 'Missing metadata fields.' );
			return new WP_REST_Response( array( 'status' => 'bad_metadata' ), 200 );
		}

		$this->entitlements->grant(
			$site_identity,
			$product_key,
			$session_id,
			sanitize_text_field( $session['customer'] ?? '' ),
			sanitize_text_field( $session['payment_intent'] ?? '' )
		);

		$this->record_event( $event_id, $session_id, $event['type'], 'fulfilled', "Entitlement granted for {$product_key}." );
		$this->audit->log( 'webhook', 'entitlement_granted', "Product '{$product_key}' unlocked for site identity {$site_identity}." );

		return new WP_REST_Response( array( 'status' => 'fulfilled' ), 200 );
	}

	private function handle_payment_failed( array $event ): WP_REST_Response {
		$event_id   = sanitize_text_field( $event['id'] );
		$session    = $event['data']['object'] ?? array();
		$session_id = sanitize_text_field( $session['id'] ?? '' );

		$this->audit->log( 'webhook', 'payment_failed', "Session {$session_id} async payment failed." );
		$this->record_event( $event_id, $session_id, $event['type'], 'skipped', 'Async payment failed.' );

		return new WP_REST_Response( array( 'status' => 'acknowledged' ), 200 );
	}

	// ── Stripe signature verification ─────────────────────────────────────────

	/**
	 * Verifies the Stripe-Signature header per the Stripe webhook docs.
	 *
	 * Algorithm:
	 *   signed_payload = timestamp + "." + raw_body
	 *   expected       = HMAC-SHA256( signed_payload, endpoint_secret )
	 *   compare timing-safe with provided v1 signature
	 */
	private function verify_signature( string $raw_body, string $sig_header, string $secret ): bool {
		$pairs = array();
		foreach ( explode( ',', $sig_header ) as $part ) {
			$kv = explode( '=', trim( $part ), 2 );
			if ( 2 === count( $kv ) ) {
				$pairs[ $kv[0] ] = $kv[1];
			}
		}

		if ( ! isset( $pairs['t'], $pairs['v1'] ) ) {
			return false;
		}

		$timestamp = (int) $pairs['t'];
		if ( abs( time() - $timestamp ) > self::TIMESTAMP_TOLERANCE ) {
			return false;
		}

		$signed   = $timestamp . '.' . $raw_body;
		$expected = hash_hmac( 'sha256', $signed, $secret );

		return hash_equals( $expected, $pairs['v1'] );
	}

	// ── Idempotency helpers ───────────────────────────────────────────────────

	private function is_already_processed( string $event_id ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'csp_processed_events';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE stripe_event_id = %s LIMIT 1", $event_id ) );
	}

	private function record_event(
		string $event_id,
		?string $session_id,
		string $event_type,
		string $outcome,
		string $detail
	): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'csp_processed_events',
			array(
				'stripe_event_id'   => $event_id,
				'stripe_session_id' => $session_id,
				'event_type'        => $event_type,
				'processed_at'      => current_time( 'mysql', true ),
				'outcome'           => $outcome,
				'detail'            => $detail,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}
}
