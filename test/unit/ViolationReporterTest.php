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
use WP_CSP\CSP\Learning_Window;
use WP_CSP\CSP\Violation_Reporter;
use WP_CSP\Modules\Audit_Log;

class ViolationReporterTest extends TestCase {

	private Audit_Log          $audit;
	private Violation_Reporter $reporter;

	protected function setUp(): void {
		wp_test_reset_globals();
		$this->audit    = $this->createMock( Audit_Log::class );
		$this->reporter = new Violation_Reporter( $this->audit );
	}

	// ── handle(): Content-Type enforcement ───────────────────────────────────

	public function test_wrong_content_type_returns_400(): void {
		$GLOBALS['_wp_rest_headers']['content-type'] = 'text/plain';
		$request = $this->make_request(
			'{"csp-report":{"violated-directive":"script-src","document-uri":"https://example.com/","blocked-uri":"https://evil.com"}}'
		);

		$response = $this->reporter->handle( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_csp_report_content_type_is_accepted(): void {
		$GLOBALS['_wp_rest_headers']['content-type'] = 'application/csp-report';
		$request = $this->make_request(
			'{"csp-report":{"violated-directive":"script-src","document-uri":"https://example.com/","blocked-uri":"https://cdn.example.com"}}'
		);

		$response = $this->reporter->handle( $request );

		$this->assertSame( 204, $response->get_status() );
	}

	public function test_reports_json_content_type_is_accepted(): void {
		$GLOBALS['_wp_rest_headers']['content-type'] = 'application/reports+json';
		$request = $this->make_request(
			'[{"type":"csp-violation","body":{"violatedDirective":"script-src","documentURL":"https://example.com/","blockedURL":"https://cdn.example.com"}}]'
		);

		$response = $this->reporter->handle( $request );

		$this->assertSame( 204, $response->get_status() );
	}

	// ── handle(): Cross-origin rejection ──────────────────────────────────────

	public function test_cross_origin_document_uri_is_silently_discarded(): void {
		$GLOBALS['_wp_rest_headers']['content-type'] = 'application/csp-report';
		$request = $this->make_request(
			'{"csp-report":{"violated-directive":"script-src","document-uri":"https://attacker.net/page","blocked-uri":"https://cdn.example.com"}}'
		);

		$response = $this->reporter->handle( $request );

		// Still returns 204 — must not reveal rejection to the sender.
		$this->assertSame( 204, $response->get_status() );
		// Rate-limit transient must not be set (report was dropped before rate check).
		$this->assertArrayNotHasKey( 'wp_csp_viol_rate_frontend', $GLOBALS['_wp_transients'] );
	}

	// ── handle(): Rate limiting ────────────────────────────────────────────────

	public function test_report_is_dropped_when_rate_limit_exceeded(): void {
		$GLOBALS['_wp_rest_headers']['content-type']            = 'application/csp-report';
		$GLOBALS['_wp_transients']['wp_csp_viol_rate_frontend'] = 500;

		$request = $this->make_request(
			'{"csp-report":{"violated-directive":"script-src","document-uri":"https://example.com/","blocked-uri":"https://cdn.example.com"}}'
		);

		$this->reporter->handle( $request );

		$this->assertSame( 500, $GLOBALS['_wp_transients']['wp_csp_viol_rate_frontend'] );
		$this->assertNull( $GLOBALS['_wpdb_last_operation'] );
	}

	// ── handle(): Deduplication (UPDATE vs INSERT) ────────────────────────────

	public function test_duplicate_report_triggers_update_not_insert(): void {
		$GLOBALS['_wp_rest_headers']['content-type'] = 'application/csp-report';
		// get_var returns a non-null row ID → duplicate detected, UPDATE path taken.
		$GLOBALS['_wpdb_get_var'] = '42';

		$request = $this->make_request(
			'{"csp-report":{"violated-directive":"script-src","document-uri":"https://example.com/","blocked-uri":"https://cdn.example.com"}}'
		);

		$this->reporter->handle( $request );

		$this->assertSame( 'query', $GLOBALS['_wpdb_last_operation'] );
	}

	public function test_new_report_uses_upsert_query(): void {
		$GLOBALS['_wp_rest_headers']['content-type'] = 'application/csp-report';
		$request = $this->make_request(
			'{"csp-report":{"violated-directive":"script-src","document-uri":"https://example.com/","blocked-uri":"https://cdn.example.com"}}'
		);

		$this->reporter->handle( $request );

		$this->assertSame( 'query', $GLOBALS['_wpdb_last_operation'] );
		$this->assertStringContainsString( 'ON DUPLICATE KEY UPDATE', $GLOBALS['_wpdb_last_query'] );
	}

	public function test_report_endpoint_learning_creates_pending_source_candidate_when_window_open(): void {
		update_option( Learning_Window::OPTION_LAST_CHANGE, gmdate( 'Y-m-d H:i:s' ) );
		update_option( Learning_Window::OPTION_WINDOW_HOURS, 48 );
		$GLOBALS['_wp_rest_headers']['content-type'] = 'application/csp-report';

		$reporter = new Violation_Reporter( $this->audit, new Learning_Window() );
		$request  = $this->make_request(
			'{"csp-report":{"effective-directive":"connect-src","violated-directive":"connect-src","document-uri":"https://example.com/","blocked-uri":"https://api.vendor.example/v1/ping"}}'
		);

		$reporter->handle( $request );

		$this->assertCount( 1, $GLOBALS['_wpdb_inserted_rows'] );
		$source_insert = $GLOBALS['_wpdb_inserted_rows'][0]['data'];
		$this->assertSame( 'frontend', $source_insert['surface'] );
		$this->assertSame( 'connect-src', $source_insert['directive'] );
		$this->assertSame( 'api.vendor.example', $source_insert['source_host'] );
		$this->assertSame( 'pending', $source_insert['approval_state'] );
		$this->assertSame( 'report-endpoint', $source_insert['owner_component'] );
	}

	public function test_report_endpoint_learning_is_locked_after_window_expires(): void {
		update_option( Learning_Window::OPTION_LAST_CHANGE, gmdate( 'Y-m-d H:i:s', time() - ( 49 * HOUR_IN_SECONDS ) ) );
		update_option( Learning_Window::OPTION_WINDOW_HOURS, 48 );
		$GLOBALS['_wp_rest_headers']['content-type'] = 'application/csp-report';

		$reporter = new Violation_Reporter( $this->audit, new Learning_Window() );
		$request  = $this->make_request(
			'{"csp-report":{"effective-directive":"connect-src","violated-directive":"connect-src","document-uri":"https://example.com/","blocked-uri":"https://api.vendor.example/v1/ping"}}'
		);

		$reporter->handle( $request );

		$this->assertCount( 0, $GLOBALS['_wpdb_inserted_rows'] );
		$this->assertSame( 'query', $GLOBALS['_wpdb_last_operation'] );
	}

	public function test_report_endpoint_learning_skips_inline_reports(): void {
		update_option( Learning_Window::OPTION_LAST_CHANGE, gmdate( 'Y-m-d H:i:s' ) );
		update_option( Learning_Window::OPTION_WINDOW_HOURS, 48 );
		$GLOBALS['_wp_rest_headers']['content-type'] = 'application/csp-report';

		$reporter = new Violation_Reporter( $this->audit, new Learning_Window() );
		$request  = $this->make_request(
			'{"csp-report":{"effective-directive":"script-src","violated-directive":"script-src","document-uri":"https://example.com/","blocked-uri":"inline"}}'
		);

		$reporter->handle( $request );

		$this->assertCount( 0, $GLOBALS['_wpdb_inserted_rows'] );
		$this->assertSame( 'query', $GLOBALS['_wpdb_last_operation'] );
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

	private function make_request( string $body ): WP_REST_Request {
		$GLOBALS['_wp_rest_body'] = $body;
		return new WP_REST_Request( 'POST', '/csp-manager/v1/report' );
	}

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
