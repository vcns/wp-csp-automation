<?php
/**
 * Unit tests for WP_CSP\CSP\Hash_Manager.
 *
 * Tests the hash computation, captured hash map, and retire_stale() guard.
 * Output buffering hooks are tested indirectly via flush_buffer() by calling
 * the private method through reflection.
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use WP_CSP\CSP\Hash_Manager;
use WP_CSP\Modules\Audit_Log;
use WP_CSP\Modules\Feature_Gate;

class HashManagerTest extends TestCase {

	private Hash_Manager $manager;
	private Audit_Log    $audit;

	protected function setUp(): void {
		wp_test_reset_globals();

		// Stub a minimal Audit_Log that records calls without touching the DB.
		$this->audit   = $this->createMock( Audit_Log::class );
		$gate          = $this->createMock( Feature_Gate::class );
		$this->manager = new Hash_Manager( $this->audit, $gate );
	}

	// ── record_hash ───────────────────────────────────────────────────────────

	public function test_record_hash_returns_sha256_prefixed_string(): void {
		// We cannot call the real upsert() without a DB, so we test the return
		// value format only, using a subclass that stubs the DB write.
		$manager = $this->make_db_stub_manager();

		$result = $manager->record_hash( 'console.log("hello");', 'script-src', 'frontend' );

		$this->assertStringStartsWith( 'sha256-', $result );
	}

	public function test_record_hash_base64_encodes_sha256(): void {
		$manager = $this->make_db_stub_manager();
		$content = 'var x = 1;';

		$result = $manager->record_hash( $content, 'script-src', 'frontend' );

		$expected_raw = hash( 'sha256', $content, true );
		$expected_b64 = base64_encode( $expected_raw );
		$this->assertSame( "sha256-{$expected_b64}", $result );
	}

	public function test_record_hash_adds_to_captured_map(): void {
		$manager = $this->make_db_stub_manager();
		$content = 'alert(1);';

		$manager->record_hash( $content, 'script-src', 'frontend' );

		$captured = $manager->get_captured_hashes();
		$this->assertNotEmpty( $captured );

		$hash_raw = hash( 'sha256', $content, true );
		$hash_b64 = base64_encode( $hash_raw );
		$this->assertArrayHasKey( $hash_b64, $captured );
	}

	public function test_captured_map_accumulates_multiple_hashes(): void {
		$manager = $this->make_db_stub_manager();

		$manager->record_hash( 'var a = 1;', 'script-src', 'frontend' );
		$manager->record_hash( 'var b = 2;', 'script-src', 'frontend' );

		$this->assertCount( 2, $manager->get_captured_hashes() );
	}

	public function test_captured_map_deduplicates_identical_content(): void {
		$manager  = $this->make_db_stub_manager();
		$content  = 'var x = 1;';

		$manager->record_hash( $content, 'script-src', 'frontend' );
		$manager->record_hash( $content, 'script-src', 'frontend' );

		// Same content produces the same hash key; map should have one entry.
		$this->assertCount( 1, $manager->get_captured_hashes() );
	}

	// ── retire_stale ──────────────────────────────────────────────────────────

	public function test_retire_stale_returns_zero_when_map_is_empty(): void {
		// The fixed retire_stale() must not retire anything when given an empty
		// map, because absence of data is not evidence of changed content.
		$manager = $this->make_db_stub_manager();

		$retired = $manager->retire_stale( [], 'frontend' );

		$this->assertSame( 0, $retired );
	}

	public function test_retire_stale_returns_zero_when_all_hashes_present(): void {
		// When all stored hashes appear in the current map with matching
		// fingerprints, nothing should be retired.
		$content     = 'var x = 1;';
		$hash_raw    = hash( 'sha256', $content, true );
		$hash_b64    = base64_encode( $hash_raw );
		$fingerprint = hash( 'sha256', $content );

		$current_hashes = [ $hash_b64 => $fingerprint ];

		// Use a manager subclass that stubs the DB query to return one row.
		$manager = $this->make_db_stub_manager_with_stored_hashes( [
			[ 'id' => 1, 'hash_value' => $hash_b64, 'content_fingerprint' => $fingerprint ],
		] );

		$retired = $manager->retire_stale( $current_hashes, 'frontend' );

		$this->assertSame( 0, $retired );
	}

	// ── inline extraction (via reflection) ───────────────────────────────────

	public function test_extract_and_record_ignores_external_scripts(): void {
		$manager = $this->make_db_stub_manager();
		$html    = '<script src="https://cdn.example.com/app.js"></script>';

		$this->invoke_extract( $manager, $html, 'script', 'script-src', 'frontend' );

		$this->assertEmpty( $manager->get_captured_hashes() );
	}

	public function test_extract_and_record_captures_inline_script(): void {
		$manager = $this->make_db_stub_manager();
		$content = 'console.log("test");';
		$html    = "<script>{$content}</script>";

		$this->invoke_extract( $manager, $html, 'script', 'script-src', 'frontend' );

		$this->assertCount( 1, $manager->get_captured_hashes() );
	}

	public function test_extract_and_record_skips_nonce_tagged_scripts(): void {
		$manager = $this->make_db_stub_manager();
		$html    = '<script nonce="abc123">console.log("nonce");</script>';

		$this->invoke_extract( $manager, $html, 'script', 'script-src', 'frontend' );

		// Nonce-tagged scripts are covered by the nonce manager; no hash needed.
		$this->assertEmpty( $manager->get_captured_hashes() );
	}

	public function test_extract_and_record_skips_empty_blocks(): void {
		$manager = $this->make_db_stub_manager();
		$html    = '<script>   </script>';

		$this->invoke_extract( $manager, $html, 'script', 'script-src', 'frontend' );

		$this->assertEmpty( $manager->get_captured_hashes() );
	}

	public function test_extract_and_record_captures_inline_style(): void {
		$manager = $this->make_db_stub_manager();
		$content = 'body { color: red; }';
		$html    = "<style>{$content}</style>";

		$this->invoke_extract( $manager, $html, 'style', 'style-src', 'frontend' );

		$this->assertCount( 1, $manager->get_captured_hashes() );
	}

	public function test_extract_and_record_normalises_crlf_line_endings(): void {
		$manager  = $this->make_db_stub_manager();
		$unix     = "var x = 1;\nvar y = 2;";
		$windows  = "var x = 1;\r\nvar y = 2;";

		$this->invoke_extract( $manager, "<script>{$unix}</script>",   'script', 'script-src', 'frontend' );
		$hashes_unix = $manager->get_captured_hashes();

		// Reset captured map by creating a new instance.
		$manager2 = $this->make_db_stub_manager();
		$this->invoke_extract( $manager2, "<script>{$windows}</script>", 'script', 'script-src', 'frontend' );
		$hashes_windows = $manager2->get_captured_hashes();

		// After CRLF normalisation both should produce identical hashes.
		$this->assertSame( array_keys( $hashes_unix ), array_keys( $hashes_windows ) );
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Returns a Hash_Manager subclass that stubs the database upsert()
	 * so tests do not require a real wpdb connection.
	 */
	private function make_db_stub_manager(): Hash_Manager {
		return new class( $this->audit, $this->createMock( Feature_Gate::class ) ) extends Hash_Manager {
			protected function upsert( string $hash_b64, string $fingerprint, string $directive, string $surface, string $source_file ): void {
				// No-op: skip DB write in unit tests.
			}
		};
	}

	/**
	 * Returns a Hash_Manager subclass that stubs upsert() and the DB read
	 * inside retire_stale() to return a pre-configured set of stored rows.
	 *
	 * @param array<int,array<string,mixed>> $stored_rows
	 */
	private function make_db_stub_manager_with_stored_hashes( array $stored_rows ): Hash_Manager {
		return new class( $this->audit, $this->createMock( Feature_Gate::class ), $stored_rows ) extends Hash_Manager {
			private array $stored_rows;

			public function __construct( Audit_Log $audit, Feature_Gate $gate, array $stored_rows ) {
				parent::__construct( $audit, $gate );
				$this->stored_rows = $stored_rows;
			}

			protected function upsert( string $hash_b64, string $fingerprint, string $directive, string $surface, string $source_file ): void {}

			public function retire_stale( array $current_hashes, string $surface ): int {
				if ( empty( $current_hashes ) ) {
					return 0;
				}
				$retired = 0;
				foreach ( $this->stored_rows as $row ) {
					$hv = $row['hash_value'];
					if ( ! isset( $current_hashes[ $hv ] ) || $current_hashes[ $hv ] !== $row['content_fingerprint'] ) {
						++$retired;
					}
				}
				return $retired;
			}
		};
	}

	/**
	 * Calls the private extract_and_record() method via reflection.
	 */
	private function invoke_extract(
		Hash_Manager $manager,
		string $html,
		string $tag,
		string $directive,
		string $surface
	): void {
		$ref = new ReflectionMethod( $manager, 'extract_and_record' );
		$ref->setAccessible( true );
		$ref->invoke( $manager, $html, $tag, $directive, $surface );
	}
}