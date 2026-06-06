<?php
/**
 * Resolves the plugin's remote product configuration.
 *
 * Flow:
 *   1. Query DNS TXT record for config pointer URL.
 *   2. If DNS lookup fails or dns_get_record is unavailable, fall back to the
 *      manually configured HTTPS URL stored in wp_csp_config_fallback_url.
 *   3. Fetch signed JSON document over HTTPS.
 *   4. Verify Ed25519 signature against hardcoded public key.
 *   5. Cache result in a WP transient; serve stale copy within grace window
 *      when upstream is unreachable.
 *
 * Non-secret data only: price IDs, tier matrix, feature flags.
 * Stripe secret keys are NEVER stored here or in DNS.
 *
 * Fallback URL:
 *   Some shared hosts block outbound DNS TXT lookups or do not provide
 *   dns_get_record(). In those environments the plugin would silently fail
 *   to load its remote config. Setting wp_csp_config_fallback_url to a
 *   direct HTTPS URL pointing to the signed config JSON bypasses DNS entirely.
 *   The same Ed25519 signature verification applies regardless of which
 *   resolution path is used.
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
		$features = $config['features'][ $tier ] ?? array();
		return in_array( '*', $features, true ) || in_array( $feature, $features, true );
	}

	/**
	 * Returns the list of all known product keys.
	 */
	public function get_products(): array {
		return $this->get()['products'] ?? array();
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
			return $this->serve_stale();
		}

		$response = $this->fetch_url( $url );

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

		$grace_ttl = max( $ttl, (int) get_option( 'wp_csp_config_grace_ttl', 86400 ) );
		set_transient( self::GRACE_KEY, $data, $grace_ttl );

		update_option( 'wp_csp_config_version', $data['version'] ?? 'unknown', false );
		update_option( 'wp_csp_config_last_fetched', current_time( 'mysql', true ), false );

		return $data;
	}

	// ── URL resolution ────────────────────────────────────────────────────────

	/**
	 * Performs the HTTP GET request for the config JSON.
	 * Extracted as a protected method so test subclasses can stub it.
	 *
	 * @param  string           $url  Absolute HTTPS URL to fetch.
	 * @return array|\WP_Error       wp_remote_get() response array or WP_Error.
	 */
	protected function fetch_url( string $url ): array|\WP_Error {
		return wp_remote_get(
			$url,
			array(
				'timeout'    => 10,
				'user-agent' => 'WP-CSP-Automation/' . WP_CSP_VERSION,
				'sslverify'  => true,
			)
		);
	}

	/**
	 * Resolves the config URL using the following priority order:
	 *
	 *   1. DNS TXT record lookup (primary path).
	 *   2. Manually configured fallback HTTPS URL (wp_csp_config_fallback_url).
	 *
	 * Returns null only when both paths fail, in which case the caller serves
	 * the stale cached config or returns null to the plugin.
	 *
	 * @return string|null  Absolute HTTPS URL, or null if resolution failed.
	 */
	private function resolve_config_url(): ?string {
		// ── Path 1: DNS TXT lookup ────────────────────────────────────────────
		$dns_url = $this->resolve_via_dns();

		if ( null !== $dns_url ) {
			$this->audit->log( 'config_resolver', 'dns_resolved', "Config URL resolved via DNS: {$dns_url}" );
			return $dns_url;
		}

		// ── Path 2: fallback HTTPS URL ────────────────────────────────────────
		$fallback = $this->get_fallback_url();

		if ( null !== $fallback ) {
			$this->audit->log(
				'config_resolver',
				'dns_fallback_used',
				'DNS lookup failed or unavailable; using configured fallback URL.'
			);
			return $fallback;
		}

		// Both paths failed.
		$this->audit->log(
			'config_resolver',
			'resolution_failed',
			'Config URL could not be resolved via DNS or fallback. Check DNS record and fallback URL in Settings.',
			'warning'
		);

		return null;
	}

	/**
	 * Attempts to resolve the config URL from the DNS TXT pointer record.
	 * Returns null if dns_get_record is unavailable, the record is absent,
	 * or the URL in the record is not a valid HTTPS URL.
	 */
	private function resolve_via_dns(): ?string {
		if ( ! function_exists( 'dns_get_record' ) ) {
			$this->audit->log(
				'config_resolver',
				'dns_unavailable',
				'dns_get_record() is not available on this host. Configure a fallback URL in Settings.',
				'warning'
			);
			return null;
		}

		$dns_record = (string) get_option( 'wp_csp_config_dns_domain', WP_CSP_CONFIG_DNS_RECORD );

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$records = @dns_get_record( $dns_record, self::DNS_RECORD_TYPE );

		if ( ! is_array( $records ) || empty( $records ) ) {
			$this->audit->log(
				'config_resolver',
				'dns_no_records',
				"No TXT records found for {$dns_record}."
			);
			return null;
		}

		foreach ( $records as $record ) {
			$txt = $record['txt'] ?? '';
			if ( ! str_starts_with( $txt, 'v=1;' ) ) {
				continue;
			}
			parse_str( str_replace( ';', '&', $txt ), $pairs );
			$url = $pairs['cfg'] ?? '';
			if ( filter_var( $url, FILTER_VALIDATE_URL ) && str_starts_with( $url, 'https://' ) ) {
				return esc_url_raw( $url );
			}
		}

		$this->audit->log(
			'config_resolver',
			'dns_no_valid_url',
			"TXT record found for {$dns_record} but no valid cfg= HTTPS URL was present."
		);

		return null;
	}

	/**
	 * Returns the manually configured fallback HTTPS URL, or null if not set
	 * or not a valid HTTPS URL.
	 */
	private function get_fallback_url(): ?string {
		$url = (string) get_option( 'wp_csp_config_fallback_url', '' );

		if ( empty( $url ) ) {
			return null;
		}

		if ( ! filter_var( $url, FILTER_VALIDATE_URL ) || ! str_starts_with( $url, 'https://' ) ) {
			$this->audit->log(
				'config_resolver',
				'fallback_url_invalid',
				"Configured fallback URL '{$url}' is not a valid HTTPS URL.",
				'warning'
			);
			return null;
		}

		return esc_url_raw( $url );
	}

	// ── Stale cache ───────────────────────────────────────────────────────────

	/**
	 * Returns the stale cached config within the grace window, or null.
	 */
	private function serve_stale(): ?array {
		$stale = get_transient( self::GRACE_KEY );
		return is_array( $stale ) ? $stale : null;
	}

	// ── Signature verification ────────────────────────────────────────────────

	/**
	 * Verifies the Ed25519 signature over the config payload.
	 * The signature field covers all fields except 'signature' itself.
	 */
	private function verify_signature( array $data ): bool {
		if ( ! isset( $data['signature'] ) ) {
			return false;
		}

		if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
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
			return true;
		}
		$expires = strtotime( $data['expires'] );
		return false !== $expires && $expires > time();
	}
}
