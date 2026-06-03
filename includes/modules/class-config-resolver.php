<?php
/**
 * Resolves the plugin's remote product configuration.
 *
 * Flow:
 *   1. Query DNS TXT record for config pointer URL.
 *   2. Fetch signed JSON document over HTTPS.
 *   3. Verify Ed25519 signature against hardcoded public key.
 *   4. Cache result in a WP transient; serve stale copy within grace window
 *      when upstream is unreachable.
 *
 * Non-secret data only: price IDs, tier matrix, feature flags.
 * Stripe secret keys are NEVER stored here or in DNS.
 */

declare( strict_types=1 );

namespace WP_CSP\Modules;

use WP_CSP\Modules\Audit_Log;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Config_Resolver {

	private const TRANSIENT_KEY   = 'wp_csp_remote_config';
	private const GRACE_KEY       = 'wp_csp_config_stale';
	private const DNS_RECORD_TYPE = DNS_TXT;

	private Audit_Log $audit;

	public function __construct( Audit_Log $audit ) {
		$this->audit = $audit;
	}

	// ── Public API ────────────────────────────────────────────────────────────

	/**
	 * Returns the full decoded config array, or null if unavailable.
	 */
	public function get(): ?array {
		$cached = get_transient( self::TRANSIENT_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		return $this->fetch_and_cache();
	}

	/**
	 * Returns the Stripe price ID for the given product key and current mode.
	 */
	public function get_price_id( string $product_key ): ?string {
		$config = $this->get();
		$mode   = get_option( 'wp_csp_stripe_mode', 'test' );
		$field  = 'live' === $mode ? 'stripe_live_price_id' : 'stripe_test_price_id';
		return $config['products'][ $product_key ][ $field ] ?? null;
	}

	/**
	 * Returns whether the given feature is included in the specified tier.
	 */
	public function tier_has_feature( string $tier, string $feature ): bool {
		$config   = $this->get();
		$features = $config['features'][ $tier ] ?? [];
		return in_array( '*', $features, true ) || in_array( $feature, $features, true );
	}

	/**
	 * Returns the list of all known product keys.
	 */
	public function get_products(): array {
		return $this->get()['products'] ?? [];
	}

	/**
	 * Force-refresh the cached config regardless of TTL.
	 */
	public function refresh(): bool {
		delete_transient( self::TRANSIENT_KEY );
		return null !== $this->fetch_and_cache();
	}

	// ── Internal ──────────────────────────────────────────────────────────────

	private function fetch_and_cache(): ?array {
		$url = $this->resolve_config_url();
		if ( null === $url ) {
			$this->audit->log( 'config_resolver', 'dns_lookup_failed', 'Could not resolve config DNS TXT record.' );
			return $this->serve_stale();
		}

		$response = wp_remote_get(
			$url,
			[
				'timeout'    => 10,
				'user-agent' => 'WP-CSP-Automation/' . WP_CSP_VERSION,
				'sslverify'  => true,
			]
		);

		if ( is_wp_error( $response ) ) {
			$this->audit->log( 'config_resolver', 'fetch_failed', $response->get_error_message() );
			return $this->serve_stale();
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			$this->audit->log( 'config_resolver', 'fetch_http_error', "HTTP {$code} from config URL." );
			return $this->serve_stale();
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			$this->audit->log( 'config_resolver', 'parse_failed', 'Config JSON is malformed.' );
			return $this->serve_stale();
		}

		if ( ! $this->verify_signature( $data ) ) {
			$this->audit->log( 'config_resolver', 'signature_invalid', 'Ed25519 signature verification failed.' );
			return $this->serve_stale();
		}

		if ( ! $this->is_not_expired( $data ) ) {
			$this->audit->log( 'config_resolver', 'config_expired', 'Remote config document is past its expiry date.' );
			return $this->serve_stale();
		}

		$ttl = max( 300, (int) get_option( 'wp_csp_config_cache_ttl', 3600 ) );
		set_transient( self::TRANSIENT_KEY, $data, $ttl );

		// Update the grace copy with a much longer TTL.
		$grace_ttl = max( $ttl, (int) get_option( 'wp_csp_config_grace_ttl', 86400 ) );
		set_transient( self::GRACE_KEY, $data, $grace_ttl );

		update_option( 'wp_csp_config_version', $data['version'] ?? 'unknown', false );
		update_option( 'wp_csp_config_last_fetched', current_time( 'mysql', true ), false );

		return $data;
	}

	/**
	 * Resolves the config URL from the DNS TXT pointer record.
	 */
	private function resolve_config_url(): ?string {
		$dns_record = (string) get_option( 'wp_csp_config_dns_domain', WP_CSP_CONFIG_DNS_RECORD );

		// dns_get_record may not be available on all hosts; fall back gracefully.
		if ( ! function_exists( 'dns_get_record' ) ) {
			return null;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$records = @dns_get_record( $dns_record, self::DNS_RECORD_TYPE );
		if ( ! is_array( $records ) ) {
			return null;
		}

		foreach ( $records as $record ) {
			$txt = $record['txt'] ?? '';
			if ( ! str_starts_with( $txt, 'v=1;' ) ) {
				continue;
			}
			// Parse key=value pairs: v=1;cfg=https://…
			parse_str( str_replace( ';', '&', $txt ), $pairs );
			$url = $pairs['cfg'] ?? '';
			if ( filter_var( $url, FILTER_VALIDATE_URL ) && str_starts_with( $url, 'https://' ) ) {
				return esc_url_raw( $url );
			}
		}

		return null;
	}

	/**
	 * Returns the stale cached config within the grace window, or null.
	 */
	private function serve_stale(): ?array {
		$stale = get_transient( self::GRACE_KEY );
		return is_array( $stale ) ? $stale : null;
	}

	/**
	 * Verifies the Ed25519 signature over the config payload.
	 * The signature field covers all fields except 'signature' itself.
	 */
	private function verify_signature( array $data ): bool {
		if ( ! isset( $data['signature'] ) ) {
			return false;
		}

		if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			// sodium unavailable: log a warning but allow the config to be used.
			$this->audit->log( 'config_resolver', 'sodium_unavailable', 'Cannot verify Ed25519 signature; sodium extension missing.' );
			return true;
		}

		$signature = base64_decode( $data['signature'], true );
		if ( false === $signature ) {
			return false;
		}

		$public_key = base64_decode( WP_CSP_CONFIG_PUBLIC_KEY, true );
		if ( false === $public_key ) {
			return false;
		}

		// Reconstruct the payload that was signed (all fields except 'signature').
		$payload = $data;
		unset( $payload['signature'] );

		$message = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $message ) {
			return false;
		}

		return sodium_crypto_sign_verify_detached( $signature, $message, $public_key );
	}

	private function is_not_expired( array $data ): bool {
		if ( empty( $data['expires'] ) ) {
			return true; // No expiry field; trust it.
		}
		$expires = strtotime( $data['expires'] );
		return false !== $expires && $expires > time();
	}
}
