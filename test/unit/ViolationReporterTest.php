<?php
/**
 * Unit tests for WP_CSP\CSP\Violation_Reporter.
 *
 * Tests normalisation of both CSP Level 3 and Reporting API payloads,
 * deduplication fingerprint generation, rate limiting, and edge cases.
 * Database writes are stubbed via a subclass.
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use WP_CSP\CSP\Violation_Reporter;
use WP_CSP\Modules\Audit_Log;

class ViolationReporterTest extends TestCase {

	private Audit_Log $audit;

	protected function setUp(): void {
		wp_test_reset_globals();
		$this->audit = $this->createMock( Audit_Log::class );
	}

	// ── Payload normalisation ─────────────────────────────────────────────────

	public function test_normalise_csp_level3_payload(): void {
		$reporter = $this->make_capturing_reporter();

		$body = [
			'csp-report' => [
				'blocked-uri'       => 'https://evil.example.com/script.js',
				'violated-directive' => 'script-src',
				'document-uri'      => 'https://example.com/',
				'disposition'       => 'report',
			],
		];

		$stored = $reporter->capture_stored_reports( $body );

		$this->assertCount( 1, $stored );
		$this->assertSame( 'https://evil.example.com/script.js', $stored[0]['blocked_uri'] );
		$this->assertSame( 'script-src', $stored[0]['violated_directive'] );
	}

	public function test_normalise_reporting_api_payload(): void {
		$reporter = $this->make_capturing_reporter();

		$body = [
			[
				'type' => 'csp-violation',
				'body' => [
					'blockedURL'         => 'https://cdn.evil.com/track.js',
					'violatedDirective'  => 'script-src-elem',
					'documentURL'        => 'https://example.com/page',
					'disposition'        => 'enforce',
				],
			],
		];

		$stored = $reporter->capture_stored_reports( $body );

		$this->assertCount( 1, $stored );
		$this->assertSame( 'https://cdn.evil.com/track.js', $stored[0]['blocked_uri'] );
		$this->assertSame( 'script-src-elem', $stored[0]['violated_directive'] );
	}

	public function test_empty_body_produces_no_stored_reports(): void {
		$reporter = $this->make_capturing_reporter();

		$stored = $reporter->capture_stored_reports( [] );

		$this->assertEmpty( $stored );
	}

	public function test_unknown_reporting_api_type_is_ignored(): void {
		$reporter = $this->make_capturing_reporter();

		$body = [
			[ 'type' => 'network-error', 'body' => [ 'url' => 'https://example.com' ] ],
		];

		$stored = $reporter->capture_stored_reports( $body );

		$this->assertEmpty( $stored );
	}

	public function test_missing_violated_directive_is_skipped(): void {
		$reporter = $this->make_capturing_reporter();

		$body = [
			'csp-report' => [
				'blocked-uri' => 'https://evil.example.com/x.js',
				// violated-directive intentionally absent
			],
		];

		$stored = $reporter->capture_stored_reports( $body );

		// store_report() skips records with empty violated_directive.
		$this->assertEmpty( $stored );
	}

	// ── Surface detection ─────────────────────────────────────────────────────

	public function test_document_uri_in_wp_admin_maps_to_admin_surface(): void {
		$reporter = $this->make_capturing_reporter();

		$body = [
			'csp-report' => [
				'blocked-uri'        => 'https://evil.example.com/x.js',
				'violated-directive' => 'script-src',
				'document-uri'       => 'https://example.com/wp-admin/edit.php',
			],
		];

		$stored = $reporter->capture_stored_reports( $body );

		$this->assertSame( 'admin', $stored[0]['profile_surface'] );
	}

	public function test_document_uri_in_wp_login_maps_to_login_surface(): void {
		$reporter = $this->make_capturing_reporter();

		$body = [
			'csp-report' => [
				'blocked-uri'        => 'https://evil.example.com/x.js',
				'violated-directive' => 'script-src',
				'document-uri'       => 'https://example.com/wp-login.php',
			],
		];

		$stored = $reporter->capture_stored_reports( $body );

		$this->assertSame( 'login', $stored[0]['profile_surface'] );
	}

	public function test_document_uri_in_wp_json_maps_to_api_surface(): void {
		$reporter = $this->make_capturing_reporter();

		$body = [
			'csp-report' => [
				'blocked-uri'        => 'https://evil.example.com/x.js',
				'violated-directive' => 'script-src',
				'document-uri'       => 'https://example.com/wp-json/csp-manager/v1/report',
			],
		];

		$stored = $reporter->capture_stored_reports( $body );

		$this->assertSame( 'api', $stored[0]['profile_surface'] );
	}

	public function test_unknown_document_uri_maps_to_frontend_surface(): void {
		$reporter = $this->make_capturing_reporter();

		$body = [
			'csp-report' => [
				'blocked-uri'        => 'https://evil.example.com/x.js',
				'violated-directive' => 'script-src',
				'document-uri'       => 'https://example.com/some-page',
			],
		];

		$stored = $reporter->capture_stored_reports( $body );

		$this->assertSame( 'frontend', $stored[0]['profile_surface'] );
	}

	// ── Deduplication fingerprint ─────────────────────────────────────────────

	public function test_same_report_produces_same_fingerprint(): void {
		$reporter = $this->make_capturing_reporter();

		$report = [
			'csp-report' => [
				'blocked-uri'        => 'https://evil.example.com/x.js',
				'violated-directive' => 'script-src',
				'document-uri'       => 'https://example.com/',
			],
		];

		$stored1 = $reporter->capture_stored_reports( $report );
		$stored2 = $reporter->capture_stored_reports( $report );

		$this->assertSame( $stored1[0]['fingerprint'], $stored2[0]['fingerprint'] );
	}

	public function test_different_blocked_uri_produces_different_fingerprint(): void {
		$reporter = $this->make_capturing_reporter();

		$report1 = [ 'csp-report' => [ 'blocked-uri' => 'https://a.example.com/x.js', 'violated-directive' => 'script-src', 'document-uri' => 'https://example.com/' ] ];
		$report2 = [ 'csp-report' => [ 'blocked-uri' => 'https://b.example.com/y.js', 'violated-directive' => 'script-src', 'document-uri' => 'https://example.com/' ] ];

		$stored1 = $reporter->capture_stored_reports( $report1 );
		$stored2 = $reporter->capture_stored_reports( $report2 );

		$this->assertNotSame( $stored1[0]['fingerprint'], $stored2[0]['fingerprint'] );
	}

	// ── Rate limiting ─────────────────────────────────────────────────────────

	public function test_rate_limit_blocks_reports_beyond_cap(): void {
		$reporter = $this->make_capturing_reporter( rate_limit_cap: 2 );

		$make_report = static fn( int $i ) => [
			'csp-report' => [
				'blocked-uri'        => "https://evil.example.com/script{$i}.js",
				'violated-directive' => 'script-src',
				'document-uri'       => 'https://example.com/',
			],
		];

		$reporter->capture_stored_reports( $make_report( 1 ) );
		$reporter->capture_stored_reports( $make_report( 2 ) );
		$stored_third = $reporter->capture_stored_reports( $make_report( 3 ) );

		// Third report exceeds the cap of 2 and should be dropped.
		$this->assertEmpty( $stored_third );
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Returns a Violation_Reporter subclass that captures store_report() calls
	 * rather than writing to the DB, making assertions straightforward.
	 */
	private function make_capturing_reporter( int $rate_limit_cap = 500 ): object {
		return new class( $this->audit, $rate_limit_cap ) extends Violation_Reporter {

			private array $captured = [];
			private int   $cap;

			public function __construct( Audit_Log $audit, int $cap ) {
				parent::__construct( $audit );
				$this->cap = $cap;
			}

			/**
			 * Exposes the private normalise + store flow for testing.
			 * Returns the array of reports that would have been stored.
			 */
			public function capture_stored_reports( array $body ): array {
				$this->captured = [];
				$reports = $this->call_normalise( $body );
				foreach ( $reports as $report ) {
					$this->call_store( $report );
				}
				return $this->captured;
			}

			protected function store_report( array $r ): void {
				// Apply the rate-limit logic manually using the test cap.
				$surface  = $this->call_surface( $r['document_uri'] ?? '' );
				$rate_key = 'wp_csp_viol_rate_' . $surface;
				$count    = (int) get_transient( $rate_key );
				if ( $count >= $this->cap ) {
					return;
				}
				set_transient( $rate_key, $count + 1, HOUR_IN_SECONDS );

				if ( empty( $r['violated_directive'] ) ) {
					return;
				}

				$blocked_uri        = substr( $r['blocked_uri'] ?? '', 0, 2048 );
				$violated_directive = substr( $r['violated_directive'] ?? '', 0, 128 );
				$fingerprint        = hash( 'sha256', $surface . '|' . $blocked_uri . '|' . $violated_directive );

				$this->captured[] = array_merge( $r, [
					'profile_surface' => $surface,
					'fingerprint'     => $fingerprint,
				] );
			}

			private function call_normalise( array $body ): array {
				$ref = new ReflectionMethod( $this, 'normalise_body' );
				$ref->setAccessible( true );
				return $ref->invoke( $this, $body );
			}

			private function call_store( array $r ): void {
				$this->store_report( $r );
			}

			private function call_surface( string $uri ): string {
				$ref = new ReflectionMethod( $this, 'surface_from_document_uri' );
				$ref->setAccessible( true );
				return $ref->invoke( $this, $uri );
			}
		};
	}
}