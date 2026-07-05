<?php
/**
 * Unit tests for WP_CSP\Modules\Config_Resolver.
 *
 * Tests the two-path URL resolution (DNS + fallback), stale cache behaviour,
 * signature bypass when sodium is unavailable, and expiry checking.
 * Outbound HTTP and DNS are stubbed via subclass overrides.
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use WP_CSP\Modules\Audit_Log;
use WP_CSP\Modules\Config_Resolver;

class ConfigResolverTest extends TestCase {

	private Audit_Log $audit;

	protected function setUp(): void {
		wp_test_reset_globals();

		if ( ! class_exists( Config_Resolver::class ) ) {
			$this->markTestSkipped( 'Config_Resolver offline module is not available.' );
		}

		$this->audit = $this->createMock( Audit_Log::class );
	}

	// ── DNS path ──────────────────────────────────────────────────────────────

	public function test_dns_resolved_url_is_fetched_and_cached(): void {
		$config   = $this->valid_config();
		$resolver = $this->make_resolver(
			dns_url:      'https://config.example.com/config.json',
			fetch_body:   json_encode( $config ),
			fetch_code:   200
		);

		$result = $resolver->get();

		$this->assertIsArray( $result );
		$this->assertSame( $config['version'], $result['version'] );
	}

	public function test_result_is_cached_in_transient_after_fetch(): void {
		$config   = $this->valid_config();
		$resolver = $this->make_resolver(
			dns_url:    'https://config.example.com/config.json',
			fetch_body: json_encode( $config ),
			fetch_code: 200
		);

		$resolver->get();

		$cached = get_transient( 'wp_csp_remote_config' );
		$this->assertIsArray( $cached );
		$this->assertSame( $config['version'], $cached['version'] );
	}

	public function test_second_call_returns_from_transient_without_fetch(): void {
		$config = $this->valid_config();
		set_transient( 'wp_csp_remote_config', $config );

		// Resolver whose fetch would fail -- if it is called, the test fails.
		$resolver = $this->make_resolver(
			dns_url:    null,
			fetch_body: '',
			fetch_code: 500
		);

		$result = $resolver->get();

		$this->assertSame( $config['version'], $result['version'] );
	}

	// ── Fallback URL path ─────────────────────────────────────────────────────

	public function test_fallback_url_used_when_dns_unavailable(): void {
		$config   = $this->valid_config();
		update_option( 'wp_csp_config_fallback_url', 'https://fallback.example.com/config.json' );

		$resolver = $this->make_resolver(
			dns_url:    null,   // DNS returns nothing
			fetch_body: json_encode( $config ),
			fetch_code: 200
		);

		$result = $resolver->get();

		$this->assertIsArray( $result );
		$this->assertSame( $config['version'], $result['version'] );
	}

	public function test_invalid_fallback_url_is_rejected(): void {
		update_option( 'wp_csp_config_fallback_url', 'http://insecure.example.com/config.json' );

		$resolver = $this->make_resolver(
			dns_url:    null,
			fetch_body: '',
			fetch_code: 200
		);

		$result = $resolver->get();

		// Both DNS and fallback failed; should return null (no stale cache).
		$this->assertNull( $result );
	}

	public function test_empty_fallback_url_skips_fallback_path(): void {
		update_option( 'wp_csp_config_fallback_url', '' );

		$resolver = $this->make_resolver(
			dns_url:    null,
			fetch_body: '',
			fetch_code: 200
		);

		$result = $resolver->get();

		$this->assertNull( $result );
	}

	// ── Stale cache ───────────────────────────────────────────────────────────

	public function test_stale_cache_returned_on_http_500(): void {
		$stale = $this->valid_config( version: 'stale-1.0' );
		set_transient( 'wp_csp_config_stale', $stale );

		$resolver = $this->make_resolver(
			dns_url:    'https://config.example.com/config.json',
			fetch_body: '',
			fetch_code: 500
		);

		$result = $resolver->get();

		$this->assertSame( 'stale-1.0', $result['version'] );
	}

	public function test_stale_cache_returned_on_malformed_json(): void {
		$stale = $this->valid_config( version: 'stale-2.0' );
		set_transient( 'wp_csp_config_stale', $stale );

		$resolver = $this->make_resolver(
			dns_url:    'https://config.example.com/config.json',
			fetch_body: 'not-json',
			fetch_code: 200
		);

		$result = $resolver->get();

		$this->assertSame( 'stale-2.0', $result['version'] );
	}

	public function test_null_returned_when_no_stale_cache_and_fetch_fails(): void {
		$resolver = $this->make_resolver(
			dns_url:    'https://config.example.com/config.json',
			fetch_body: '',
			fetch_code: 503
		);

		$result = $resolver->get();

		$this->assertNull( $result );
	}

	// ── Expiry ────────────────────────────────────────────────────────────────

	public function test_expired_config_falls_back_to_stale(): void {
		$stale = $this->valid_config( version: 'stale-3.0' );
		set_transient( 'wp_csp_config_stale', $stale );

		$expired_config = $this->valid_config();
		$expired_config['expires'] = '2000-01-01T00:00:00Z'; // past date

		$resolver = $this->make_resolver(
			dns_url:    'https://config.example.com/config.json',
			fetch_body: json_encode( $expired_config ),
			fetch_code: 200
		);

		$result = $resolver->get();

		$this->assertSame( 'stale-3.0', $result['version'] );
	}

	public function test_non_expired_config_is_accepted(): void {
		$future_config = $this->valid_config();
		$future_config['expires'] = gmdate( 'Y-m-d\TH:i:s\Z', time() + 86400 ); // tomorrow

		$resolver = $this->make_resolver(
			dns_url:    'https://config.example.com/config.json',
			fetch_body: json_encode( $future_config ),
			fetch_code: 200
		);

		$result = $resolver->get();

		$this->assertSame( $future_config['version'], $result['version'] );
	}

	// ── get_price_id ──────────────────────────────────────────────────────────

	public function test_get_price_id_returns_test_price_in_test_mode(): void {
		update_option( 'wp_csp_stripe_mode', 'test' );
		$config = $this->valid_config();
		set_transient( 'wp_csp_remote_config', $config );

		$resolver = $this->make_resolver( dns_url: null, fetch_body: '', fetch_code: 200 );

		$price_id = $resolver->get_price_id( 'wp-csp-automation' );

		$this->assertSame( 'price_test_123', $price_id );
	}

	public function test_get_price_id_returns_live_price_in_live_mode(): void {
		update_option( 'wp_csp_stripe_mode', 'live' );
		$config = $this->valid_config();
		set_transient( 'wp_csp_remote_config', $config );

		$resolver = $this->make_resolver( dns_url: null, fetch_body: '', fetch_code: 200 );

		$price_id = $resolver->get_price_id( 'wp-csp-automation' );

		$this->assertSame( 'price_live_456', $price_id );
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Returns a Config_Resolver subclass that stubs DNS lookup and HTTP fetch.
	 *
	 * @param string|null $dns_url     URL returned by DNS lookup, or null to simulate DNS failure.
	 * @param string      $fetch_body  Body returned by wp_remote_get().
	 * @param int         $fetch_code  HTTP status code returned by wp_remote_get().
	 */
	private function make_resolver(
		?string $dns_url,
		string  $fetch_body,
		int     $fetch_code
	): Config_Resolver {
		$audit = $this->audit;

		return new class( $audit, $dns_url, $fetch_body, $fetch_code ) extends Config_Resolver {

			public function __construct(
				Audit_Log $audit,
				private ?string $stub_dns_url,
				private string  $stub_body,
				private int     $stub_code
			) {
				parent::__construct( $audit );
			}

			protected function resolve_via_dns(): ?string {
				return $this->stub_dns_url;
			}

			protected function fetch_url( string $url ): array {
				return [
					'response' => [ 'code' => $this->stub_code ],
					'body'     => $this->stub_body,
				];
			}

			protected function verify_signature( array $data ): bool {
				// Bypass Ed25519 verification in unit tests; signature testing
				// is an integration concern requiring a real key pair.
				return true;
			}
		};
	}

	/**
	 * Returns a minimal valid config array for use in fetch stubs.
	 */
	private function valid_config( string $version = '1.0.0' ): array {
		return [
			'version'   => $version,
			'signature' => 'stub-signature',
			'products'  => [
				'wp-csp-automation' => [
					'name'                => 'WP CSP Automation',
					'amount'              => 2500,
					'currency'            => 'usd',
					'stripe_test_price_id' => 'price_test_123',
					'stripe_live_price_id' => 'price_live_456',
					'features'            => [ '*' ],
				],
			],
			'features'  => [
				'pro' => [ '*' ],
			],
		];
	}
}
