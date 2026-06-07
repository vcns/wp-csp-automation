<?php
/**
 * Per-request CSP nonce generation and script/style attribute injection.
 *
 * Implements §4.1 and §4.2 of the directive:
 *   - One nonce per request, cryptographically random, base64-encoded.
 *   - Injected via wp_script_attributes and wp_inline_script_attributes hooks
 *     (native WP 6.4+ hooks, preferred path).
 *   - Falls back to script_loader_tag / style_loader_tag filter manipulation
 *     for themes/plugins that bypass the native hooks.
 *   - Nonce is exposed to the Policy_Builder via get_nonce() for header emission.
 *
 * No 'unsafe-inline' is ever added; nonces are the exclusive inline path.
 */

declare( strict_types=1 );

namespace WP_CSP\CSP;

use WP_CSP\Modules\Feature_Gate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Nonce_Manager {

	private string $nonce = '';
	private Feature_Gate $gate;

	public function __construct( Feature_Gate $gate ) {
		$this->gate = $gate;
	}

	// ── Bootstrap ─────────────────────────────────────────────────────────────

	public function register(): void {
		// Generate nonce once, very early, before any output.
		add_action( 'init', array( $this, 'generate' ), 1 );

		// WP 6.4+ native hooks (preferred — no regex on tag strings).
		add_filter( 'wp_script_attributes', array( $this, 'add_script_nonce_attr' ), 10, 1 );
		add_filter( 'wp_inline_script_attributes', array( $this, 'add_script_nonce_attr' ), 10, 1 );

		// Legacy tag-string fallback.
		add_filter( 'script_loader_tag', array( $this, 'inject_nonce_into_script_tag' ), 10, 3 );
		add_filter( 'style_loader_tag', array( $this, 'inject_nonce_into_style_tag' ), 10, 4 );

		// Expose nonce to front-end JS (e.g. Stripe.js inline init).
		add_action( 'wp_head', array( $this, 'add_meta_nonce' ), 1 );
		add_action( 'admin_head', array( $this, 'add_meta_nonce' ), 1 );
	}

	// ── Nonce lifecycle ───────────────────────────────────────────────────────

	public function generate(): void {
		$bytes       = random_bytes( 16 );
		$this->nonce = rtrim( base64_encode( $bytes ), '=' );
	}

	public function get_nonce(): string {
		return $this->nonce;
	}

	// ── WP 6.4+ attribute filters ─────────────────────────────────────────────

	/**
	 * Adds the nonce attribute to the script attrs array.
	 *
	 * @param array $attrs  Script element attribute key→value pairs.
	 * @return array
	 */
	public function add_script_nonce_attr( array $attrs ): array {
		if ( ! empty( $this->nonce ) ) {
			$attrs['nonce'] = $this->nonce;
		}
		return $attrs;
	}

	// ── Legacy tag-string fallback ────────────────────────────────────────────

	/**
	 * Injects nonce="…" into <script …> tags generated via script_loader_tag.
	 * Only adds if not already present (avoids duplicate nonce attributes).
	 *
	 * @param string $tag     Full script HTML tag.
	 * @param string $handle  Script handle.
	 * @param string $src     Script src URL.
	 * @return string
	 */
	public function inject_nonce_into_script_tag( string $tag, string $handle, string $src ): string {
		if ( empty( $this->nonce ) || str_contains( $tag, 'nonce=' ) ) {
			return $tag;
		}
		return str_replace(
			'<script ',
			'<script nonce="' . esc_attr( $this->nonce ) . '" ',
			$tag
		);
	}

	/**
	 * Injects nonce="…" into <link …> style tags for inline style support.
	 *
	 * @param string $tag    Full link HTML tag.
	 * @param string $handle Style handle.
	 * @param string $href   Stylesheet URL.
	 * @param string $media  Media attribute value.
	 * @return string
	 */
	public function inject_nonce_into_style_tag( string $tag, string $handle, string $href, string $media ): string {
		if ( empty( $this->nonce ) || str_contains( $tag, 'nonce=' ) ) {
			return $tag;
		}
		return str_replace(
			'<link ',
			'<link nonce="' . esc_attr( $this->nonce ) . '" ',
			$tag
		);
	}

	// ── Meta nonce for JS access ──────────────────────────────────────────────

	/**
	 * Emits a <meta name="csp-nonce"> tag so theme/plugin JS can read the nonce
	 * without needing wp_localize_script (which would require unsafe-inline).
	 */
	public function add_meta_nonce(): void {
		if ( ! empty( $this->nonce ) ) {
			printf(
				'<meta name="csp-nonce" content="%s">' . "\n",
				esc_attr( $this->nonce )
			);
		}
	}
}
