<?php
/**
 * Creates Stripe Checkout Sessions for premium feature unlocks.
 *
 * Calls the Stripe API directly using the WordPress HTTP API.
 * The Stripe secret key is never exposed to JavaScript or DNS.
 *
 * Payment model: one-time, mode=payment.
 * Access is granted ONLY after webhook confirmation, never on redirect alone.
 */

declare( strict_types=1 );

namespace WP_CSP\Modules;

use WP_CSP\Modules\Audit_Log;
use WP_CSP\Modules\Config_Resolver;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Checkout_Service {

	private const STRIPE_API_BASE = 'https://api.stripe.com/v1';

	private Config_Resolver $config;
	private Audit_Log       $audit;

	public function __construct( Config_Resolver $config, Audit_Log $audit ) {
		$this->config = $config;
		$this->audit  = $audit;
	}

	// ── Public API ────────────────────────────────────────────────────────────

	/**
	 * Creates a Stripe Checkout Session and returns the hosted URL.
	 *
	 * @param string $product_key  Product identifier (must exist in DNS config).
	 * @return string|\WP_Error    Checkout URL on success, WP_Error on failure.
	 */
	public function create_session( string $product_key ): string|WP_Error {
		$secret_key = $this->get_secret_key();
		if ( empty( $secret_key ) ) {
			return new WP_Error( 'no_stripe_key', __( 'Stripe secret key is not configured.', 'wp-csp-automation' ) );
		}

		$price_id = $this->config->get_price_id( $product_key );
		if ( empty( $price_id ) ) {
			return new WP_Error( 'no_price_id', __( 'No Stripe price ID found for this product in the remote config.', 'wp-csp-automation' ) );
		}

		$site_identity = $this->get_site_identity();
		$success_url   = add_query_arg(
			[
				'page'       => 'wp-csp-entitlement',
				'csp_result' => 'success',
				'session_id' => '{CHECKOUT_SESSION_ID}', // Stripe replaces this literal.
			],
			admin_url( 'admin.php' )
		);
		$cancel_url    = add_query_arg(
			[
				'page'       => 'wp-csp-entitlement',
				'csp_result' => 'cancelled',
			],
			admin_url( 'admin.php' )
		);

		$checkout_config = $this->config->get()['checkout_policy'] ?? [];

		$body = [
			'mode'                   => 'payment',
			'line_items[0][price]'   => $price_id,
			'line_items[0][quantity]' => '1',
			'success_url'            => $success_url,
			'cancel_url'             => $cancel_url,
			'metadata[site_identity]'  => $site_identity,
			'metadata[product_key]'    => $product_key,
			'metadata[plugin_version]' => WP_CSP_VERSION,
		];

		if ( ! empty( $checkout_config['allow_promotion_codes'] ) ) {
			$body['allow_promotion_codes'] = 'true';
		}
		if ( ! empty( $checkout_config['billing_address_collection'] ) ) {
			$body['billing_address_collection'] = $checkout_config['billing_address_collection'];
		}

		$response = wp_remote_post(
			self::STRIPE_API_BASE . '/checkout/sessions',
			[
				'timeout'     => 15,
				'sslverify'   => true,
				'headers'     => [
					'Authorization' => 'Bearer ' . $secret_key,
					'Content-Type'  => 'application/x-www-form-urlencoded',
					'Stripe-Version' => '2024-06-20',
				],
				'body'        => $body,
			]
		);

		if ( is_wp_error( $response ) ) {
			$this->audit->log( 'checkout', 'api_error', $response->get_error_message() );
			return new WP_Error( 'stripe_request_failed', $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code !== 200 || empty( $data['url'] ) ) {
			$error_msg = $data['error']['message'] ?? "HTTP {$code}";
			$this->audit->log( 'checkout', 'session_create_failed', $error_msg );
			return new WP_Error( 'stripe_session_failed', $error_msg );
		}

		$this->audit->log( 'checkout', 'session_created', "Session {$data['id']} for product {$product_key}" );

		return esc_url_raw( $data['url'] );
	}

	/**
	 * Retrieves a completed Checkout Session from Stripe for post-redirect
	 * status display. Does NOT grant access – that happens via webhook.
	 */
	public function retrieve_session( string $session_id ): array|WP_Error {
		// Basic format guard to avoid arbitrary string injection into URL path.
		if ( ! preg_match( '/^cs_[a-zA-Z0-9_]+$/', $session_id ) ) {
			return new WP_Error( 'invalid_session_id', __( 'Invalid session ID format.', 'wp-csp-automation' ) );
		}

		$secret_key = $this->get_secret_key();
		if ( empty( $secret_key ) ) {
			return new WP_Error( 'no_stripe_key', __( 'Stripe secret key is not configured.', 'wp-csp-automation' ) );
		}

		$response = wp_remote_get(
			self::STRIPE_API_BASE . '/checkout/sessions/' . rawurlencode( $session_id ),
			[
				'timeout'   => 10,
				'sslverify' => true,
				'headers'   => [
					'Authorization'  => 'Bearer ' . $secret_key,
					'Stripe-Version' => '2024-06-20',
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $data ) ? $data : new WP_Error( 'parse_failed', 'Could not decode Stripe response.' );
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Returns the site identity token used to bind entitlements to this installation.
	 * Stable hash of the site URL; not personally identifiable.
	 */
	public function get_site_identity(): string {
		return substr( hash( 'sha256', get_site_url() ), 0, 48 );
	}

	private function get_secret_key(): string {
		return (string) get_option( 'wp_csp_stripe_secret_key', '' );
	}
}
