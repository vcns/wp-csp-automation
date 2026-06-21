<?php
/**
 * Unit tests for WP_CSP\CSP\Nonce_Manager.
 *
 * Verifies nonce generation entropy, uniqueness, tag injection, and attribute
 * hook handling. Does not test register() because that calls add_action() which
 * only records to the stub and does not fire hooks in the test context.
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use WP_CSP\CSP\Nonce_Manager;
use WP_CSP\Modules\Feature_Gate;

class NonceManagerTest extends TestCase {

	private Nonce_Manager $manager;

	protected function setUp(): void {
		wp_test_reset_globals();
		$this->manager = new Nonce_Manager( $this->createMock( Feature_Gate::class ) );
	}

	// ── Initial state ─────────────────────────────────────────────────────────

	public function test_get_nonce_returns_empty_string_before_generate(): void {
		$this->assertSame( '', $this->manager->get_nonce() );
	}

	// ── generate() ────────────────────────────────────────────────────────────

	public function test_generate_produces_non_empty_nonce(): void {
		$this->manager->generate();

		$this->assertNotEmpty( $this->manager->get_nonce() );
	}

	public function test_generate_produces_valid_base64_string(): void {
		$this->manager->generate();
		$nonce = $this->manager->get_nonce();

		// Base64 without padding: only A-Z, a-z, 0-9, +, /
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9+\/]+$/', $nonce );
	}

	public function test_generate_produces_at_least_22_characters(): void {
		// 16 bytes of entropy → ceil(16 × 4/3) = 22 base64 chars (no padding).
		$this->manager->generate();

		$this->assertGreaterThanOrEqual( 22, strlen( $this->manager->get_nonce() ) );
	}

	public function test_two_generate_calls_produce_distinct_nonces(): void {
		$gate    = $this->createMock( Feature_Gate::class );
		$manager1 = new Nonce_Manager( $gate );
		$manager2 = new Nonce_Manager( $gate );

		$manager1->generate();
		$manager2->generate();

		// Statistically guaranteed distinct (128-bit CSPRNG).
		$this->assertNotSame( $manager1->get_nonce(), $manager2->get_nonce() );
	}

	// ── add_script_nonce_attr() ───────────────────────────────────────────────

	public function test_add_script_nonce_attr_adds_nonce_key_to_attrs(): void {
		$this->manager->generate();

		$result = $this->manager->add_script_nonce_attr( array() );

		$this->assertArrayHasKey( 'nonce', $result );
		$this->assertSame( $this->manager->get_nonce(), $result['nonce'] );
	}

	public function test_add_script_nonce_attr_does_nothing_when_nonce_is_empty(): void {
		// No generate() call; nonce remains ''.
		$result = $this->manager->add_script_nonce_attr( array( 'type' => 'text/javascript' ) );

		$this->assertArrayNotHasKey( 'nonce', $result );
	}

	public function test_add_script_nonce_attr_preserves_existing_attributes(): void {
		$this->manager->generate();
		$existing = array( 'type' => 'module', 'defer' => true );

		$result = $this->manager->add_script_nonce_attr( $existing );

		$this->assertArrayHasKey( 'type', $result );
		$this->assertArrayHasKey( 'defer', $result );
		$this->assertArrayHasKey( 'nonce', $result );
	}

	// ── inject_nonce_into_script_tag() ────────────────────────────────────────

	public function test_inject_nonce_into_script_tag_adds_nonce_attribute(): void {
		$this->manager->generate();
		$nonce = $this->manager->get_nonce();
		$tag   = '<script src="test.js"></script>';

		$result = $this->manager->inject_nonce_into_script_tag( $tag, 'test', 'test.js' );

		$this->assertStringContainsString( 'nonce="' . esc_attr( $nonce ) . '"', $result );
	}

	public function test_inject_nonce_into_script_tag_skips_when_nonce_already_present(): void {
		$this->manager->generate();
		$tag = '<script nonce="already-here" src="test.js"></script>';

		$result = $this->manager->inject_nonce_into_script_tag( $tag, 'test', 'test.js' );

		$this->assertSame( $tag, $result );
	}

	public function test_inject_nonce_into_script_tag_is_noop_when_nonce_empty(): void {
		$tag = '<script src="test.js"></script>';

		$result = $this->manager->inject_nonce_into_script_tag( $tag, 'test', 'test.js' );

		$this->assertSame( $tag, $result );
	}

	// ── inject_nonce_into_style_tag() ─────────────────────────────────────────

	public function test_inject_nonce_into_style_tag_adds_nonce_attribute(): void {
		$this->manager->generate();
		$nonce = $this->manager->get_nonce();
		$tag   = '<link rel="stylesheet" href="style.css" />';

		$result = $this->manager->inject_nonce_into_style_tag( $tag, 'test', 'style.css', 'all' );

		$this->assertStringContainsString( 'nonce="' . esc_attr( $nonce ) . '"', $result );
	}

	public function test_inject_nonce_into_style_tag_skips_when_nonce_already_present(): void {
		$this->manager->generate();
		$tag = '<link nonce="existing" rel="stylesheet" href="style.css" />';

		$result = $this->manager->inject_nonce_into_style_tag( $tag, 'test', 'style.css', 'all' );

		$this->assertSame( $tag, $result );
	}

	public function test_inject_nonce_into_style_tag_is_noop_when_nonce_empty(): void {
		$tag = '<link rel="stylesheet" href="style.css" />';

		$result = $this->manager->inject_nonce_into_style_tag( $tag, 'test', 'style.css', 'all' );

		$this->assertSame( $tag, $result );
	}

	// ── CSP header safety ─────────────────────────────────────────────────────

	public function test_nonce_is_safe_for_embedding_in_csp_header(): void {
		// Semicolons break directive boundaries; whitespace splits tokens; single
		// quotes collide with the 'nonce-…' keyword syntax. None must appear.
		$this->manager->generate();
		$nonce = $this->manager->get_nonce();

		$this->assertDoesNotMatchRegularExpression( '/[;\s\']/', $nonce );
	}
}
