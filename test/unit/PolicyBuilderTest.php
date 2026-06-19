<?php
/**
 * Unit tests for WP_CSP\CSP\Policy_Builder.
 *
 * Focuses on build_policy_string() which assembles the CSP header value.
 * Header emission (emit_header) is not tested here as it requires headers_sent()
 * and the WordPress send_headers hook, both of which are integration concerns.
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use WP_CSP\CSP\Policy_Builder;
use WP_CSP\Modules\Feature_Gate;

class PolicyBuilderTest extends TestCase {

	private Policy_Builder $builder;
	private Feature_Gate   $gate;

	protected function setUp(): void {
		wp_test_reset_globals();
		$this->gate    = $this->createMock( Feature_Gate::class );
		$this->builder = new Policy_Builder( $this->gate );
	}

	// ── default-src ───────────────────────────────────────────────────────────

	public function test_build_includes_default_src_none(): void {
		$profile = $this->make_profile( [ 'default-src' => [ "'none'" ] ] );

		$policy = $this->builder->build_policy_string( $profile, 'frontend' );

		$this->assertStringContainsString( "default-src 'none'", $policy );
	}

	public function test_build_returns_empty_string_for_malformed_directives(): void {
		$profile = $this->make_profile_raw( 'not-valid-json', '{}' );

		$policy = $this->builder->build_policy_string( $profile, 'frontend' );

		$this->assertSame( '', $policy );
	}

	// ── nonce injection ───────────────────────────────────────────────────────

	public function test_build_injects_nonce_into_script_src(): void {
		$profile = $this->make_profile( [
			'default-src' => [ "'none'" ],
			'script-src'  => [],
		] );

		$policy = $this->build_with_nonce( $profile, 'frontend', 'testNonce123' );

		$this->assertStringContainsString( "'nonce-testNonce123'", $policy );
	}

	public function test_build_injects_nonce_into_style_src(): void {
		$profile = $this->make_profile( [
			'default-src' => [ "'none'" ],
			'style-src'   => [],
		] );

		$policy = $this->build_with_nonce( $profile, 'frontend', 'styleNonce' );

		$this->assertStringContainsString( "'nonce-styleNonce'", $policy );
	}

	public function test_build_does_not_inject_empty_nonce(): void {
		$profile = $this->make_profile( [
			'default-src' => [ "'none'" ],
			'script-src'  => [ "'self'" ],
		] );

		// Build without setting a nonce.
		$policy = $this->builder->build_policy_string( $profile, 'frontend' );

		$this->assertStringNotContainsString( 'nonce-', $policy );
	}

	// ── source host sanitisation (esc_attr fix) ───────────────────────────────

	public function test_build_does_not_html_encode_ampersand_in_source_host(): void {
		// If esc_attr() were used, a host containing & would become &amp;
		// which is invalid in an HTTP header. sanitize_text_field() must be used.
		$profile = $this->make_profile( [ 'default-src' => [ "'none'" ], 'script-src' => [] ] );

		$policy = $this->build_with_approved_sources( $profile, 'frontend', [
			[ 'directive' => 'script-src', 'source_host' => 'cdn.example.com' ],
		] );

		// cdn.example.com contains no special characters; verify it appears verbatim.
		$this->assertStringContainsString( 'cdn.example.com', $policy );
		$this->assertStringNotContainsString( '&amp;', $policy );
	}

	public function test_build_sanitises_source_host_stripping_tags(): void {
		$profile = $this->make_profile( [ 'default-src' => [ "'none'" ], 'script-src' => [] ] );

		$policy = $this->build_with_approved_sources( $profile, 'frontend', [
			[ 'directive' => 'script-src', 'source_host' => '<script>bad</script>cdn.example.com' ],
		] );

		$this->assertStringNotContainsString( '<script>', $policy );
	}

	// ── overrides ─────────────────────────────────────────────────────────────

	public function test_build_applies_admin_overrides(): void {
		$profile = $this->make_profile_with_overrides(
			[ 'default-src' => [ "'none'" ], 'script-src' => [ "'self'" ] ],
			[ 'script-src'  => [ "'self'", 'https://override.example.com' ] ]
		);

		$policy = $this->builder->build_policy_string( $profile, 'frontend' );

		$this->assertStringContainsString( 'https://override.example.com', $policy );
	}

	// ── strict-dynamic ────────────────────────────────────────────────────────

	public function test_build_adds_strict_dynamic_when_gate_allows(): void {
		$this->gate->method( 'is_allowed' )->with( 'strict_dynamic' )->willReturn( true );

		$profile = $this->make_profile(
			[ 'default-src' => [ "'none'" ], 'script-src' => [] ],
			strict_dynamic: true
		);

		$policy = $this->builder->build_policy_string( $profile, 'frontend' );

		$this->assertStringContainsString( "'strict-dynamic'", $policy );
	}

	public function test_build_omits_strict_dynamic_when_gate_denies(): void {
		$this->gate->method( 'is_allowed' )->with( 'strict_dynamic' )->willReturn( false );

		$profile = $this->make_profile(
			[ 'default-src' => [ "'none'" ], 'script-src' => [] ],
			strict_dynamic: true
		);

		$policy = $this->builder->build_policy_string( $profile, 'frontend' );

		$this->assertStringNotContainsString( "'strict-dynamic'", $policy );
	}

	// ── reporting directives ──────────────────────────────────────────────────

	public function test_build_appends_report_uri(): void {
		$profile = $this->make_profile( [ 'default-src' => [ "'none'" ] ] );

		$policy = $this->builder->build_policy_string( $profile, 'frontend' );

		$this->assertStringContainsString( 'report-uri', $policy );
		$this->assertStringContainsString( 'csp-manager/v1/report', $policy );
	}

	public function test_build_appends_report_to(): void {
		$profile = $this->make_profile( [ 'default-src' => [ "'none'" ] ] );

		$policy = $this->builder->build_policy_string( $profile, 'frontend' );

		$this->assertStringContainsString( 'report-to csp-endpoint', $policy );
	}

	// ── object-src and base-uri hardening ────────────────────────────────────

	public function test_build_includes_object_src_none(): void {
		$profile = $this->make_profile( [
			'default-src' => [ "'none'" ],
			'object-src'  => [ "'none'" ],
		] );

		$policy = $this->builder->build_policy_string( $profile, 'frontend' );

		$this->assertStringContainsString( "object-src 'none'", $policy );
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	private function make_profile( array $directives, bool $strict_dynamic = false ): array {
		return [
			'mode'           => 'enforce',
			'directives'     => wp_json_encode( $directives ),
			'overrides'      => wp_json_encode( [] ),
			'strict_dynamic' => $strict_dynamic ? 1 : 0,
		];
	}

	private function make_profile_raw( string $directives_json, string $overrides_json ): array {
		return [
			'mode'           => 'enforce',
			'directives'     => $directives_json,
			'overrides'      => $overrides_json,
			'strict_dynamic' => 0,
		];
	}

	private function make_profile_with_overrides( array $directives, array $overrides ): array {
		return [
			'mode'           => 'enforce',
			'directives'     => wp_json_encode( $directives ),
			'overrides'      => wp_json_encode( $overrides ),
			'strict_dynamic' => 0,
		];
	}

	private function build_with_nonce( array $profile, string $surface, string $nonce ): string {
		$builder = $this->make_db_stub_builder( nonce: $nonce );
		return $builder->build_policy_string( $profile, $surface );
	}

	private function build_with_approved_sources( array $profile, string $surface, array $sources ): string {
		$builder = $this->make_db_stub_builder( approved_sources: $sources );
		return $builder->build_policy_string( $profile, $surface );
	}

	private function make_db_stub_builder(
		string $nonce = '',
		array $approved_hashes = [],
		array $approved_sources = []
	): Policy_Builder {
		$GLOBALS['_wp_csp_test_nonce'] = $nonce;
		return new Policy_Builder(
			$this->gate,
			fn( string $s ) => $approved_hashes,
			fn( string $s ) => $approved_sources
		);
	}
}

// Plugin_Nonce_Manager stub is defined in test/unit/NonceBridge.php,
// required by test/bootstrap.php before spl_autoload_register() is called.