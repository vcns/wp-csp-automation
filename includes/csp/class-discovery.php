<?php
/**
 * Runtime and crawl-based source discovery.
 *
 * Implements §4.6 of the directive:
 *   - Crawls pages using the WordPress HTTP API.
 *   - Parses HTML response for external script, style, img, font, connect,
 *     frame, media, and manifest sources.
 *   - Classifies each discovered URL into the correct CSP directive.
 *   - Writes new sources to csp_source_inventory with approval_state='pending'.
 *   - Marks sources not seen in current crawl as candidates for removal.
 *
 * Coverage notes:
 *   - connect-src (fetch, XHR, WebSocket) and worker-src are JavaScript
 *     runtime concerns. They cannot be reliably discovered from static HTML.
 *     These directives must be populated from CSP violation reports collected
 *     in report-only mode on a real browser session.
 *   - font-src is partially discoverable by fetching linked CSS and parsing
 *     @font-face src: declarations. This is implemented below.
 *   - media-src is discoverable from <audio> and <video> src attributes.
 *   - manifest-src is discoverable from <link rel="manifest"> href.
 *
 * Premium feature: multi-surface crawl (admin, login pages).
 */

declare( strict_types=1 );

namespace WP_CSP\CSP;

use WP_CSP\Modules\Audit_Log;
use WP_CSP\Modules\Feature_Gate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Discovery {

	private Audit_Log $audit;
	private Feature_Gate $gate;

	public function __construct( Audit_Log $audit, Feature_Gate $gate ) {
		$this->audit = $audit;
		$this->gate  = $gate;
	}

	// ── Public API ────────────────────────────────────────────────────────────

	/**
	 * Runs a full discovery pass across configured surfaces.
	 * Returns counts of added/updated records.
	 */
	public function run_scan(): array {
		$stats    = array(
			'sources_added'   => 0,
			'sources_updated' => 0,
		);
		$surfaces = array( 'frontend' );

		if ( $this->gate->is_allowed( 'multi_surface_scan' ) ) {
			$surfaces = array( 'frontend', 'admin', 'login', 'api' );
		}

		foreach ( $surfaces as $surface ) {
			$urls = $this->get_crawl_urls( $surface );
			foreach ( $urls as $url ) {
				$result                    = $this->crawl_url( $url, $surface );
				$stats['sources_added']   += $result['added'];
				$stats['sources_updated'] += $result['updated'];
			}
		}

		return $stats;
	}

	/**
	 * Discovers sources from a single URL and stores them.
	 */
	public function crawl_url( string $url, string $surface ): array {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 20,
				'user-agent' => 'WP-CSP-Discovery/' . WP_CSP_VERSION,
				'sslverify'  => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->audit->log( 'discovery', 'crawl_failed', "Failed to fetch {$url}: " . $response->get_error_message(), 'warning' );
			return array(
				'added'   => 0,
				'updated' => 0,
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 400 ) {
			$this->audit->log( 'discovery', 'crawl_http_error', "HTTP {$code} for {$url}.", 'warning' );
			return array(
				'added'   => 0,
				'updated' => 0,
			);
		}

		$html    = wp_remote_retrieve_body( $response );
		$sources = $this->parse_html_sources( $html, $url );

		return $this->upsert_sources( $sources, $surface );
	}

	// ── HTML parsing ──────────────────────────────────────────────────────────

	/**
	 * Extracts external source URLs from an HTML document.
	 *
	 * Directives covered by static HTML parsing:
	 *   script-src-elem  -- <script src>
	 *   style-src-elem   -- <link rel="stylesheet">
	 *   img-src          -- <img src>
	 *   frame-src        -- <iframe src>
	 *   form-action      -- <form action>
	 *   media-src        -- <audio src>, <video src>, <source src> inside media elements
	 *   manifest-src     -- <link rel="manifest">
	 *   font-src         -- @font-face src in linked CSS (fetched separately)
	 *
	 * Directives NOT covered by static HTML (violation-report-driven only):
	 *   connect-src      -- fetch(), XMLHttpRequest, WebSocket, EventSource
	 *   worker-src       -- new Worker(), new SharedWorker()
	 *
	 * Returns array of [ 'directive' => string, 'uri' => string, 'host' => string, 'scheme' => string ].
	 */
	public function parse_html_sources( string $html, string $base_url ): array {
		if ( empty( trim( $html ) ) ) {
			return array();
		}

		$doc = new \DOMDocument();
		// Suppress malformed HTML warnings; we do best-effort parsing.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@$doc->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR );

		$found = array();

		// Scripts → script-src-elem
		foreach ( $doc->getElementsByTagName( 'script' ) as $el ) {
			$src = trim( $el->getAttribute( 'src' ) );
			if ( $src && ! $this->is_inline_data( $src ) ) {
				$found[] = $this->classify_url( $src, 'script-src-elem', $base_url );
			}
		}

		// Collect stylesheet hrefs for separate CSS parsing and classify here.
		$stylesheet_urls = array();
		foreach ( $doc->getElementsByTagName( 'link' ) as $el ) {
			$rel  = strtolower( trim( $el->getAttribute( 'rel' ) ) );
			$href = trim( $el->getAttribute( 'href' ) );

			if ( ! $href ) {
				continue;
			}

			if ( 'stylesheet' === $rel && ! $this->is_inline_data( $href ) ) {
				$found[]           = $this->classify_url( $href, 'style-src-elem', $base_url );
				$stylesheet_urls[] = $this->resolve_url( $href, $base_url );
			}

			// manifest-src
			if ( 'manifest' === $rel ) {
				$found[] = $this->classify_url( $href, 'manifest-src', $base_url );
			}
		}

		// Images → img-src
		foreach ( $doc->getElementsByTagName( 'img' ) as $el ) {
			$src = trim( $el->getAttribute( 'src' ) );
			if ( $src && ! $this->is_inline_data( $src ) ) {
				$found[] = $this->classify_url( $src, 'img-src', $base_url );
			}
		}

		// Iframes → frame-src
		foreach ( $doc->getElementsByTagName( 'iframe' ) as $el ) {
			$src = trim( $el->getAttribute( 'src' ) );
			if ( $src ) {
				$found[] = $this->classify_url( $src, 'frame-src', $base_url );
			}
		}

		// Forms → form-action
		foreach ( $doc->getElementsByTagName( 'form' ) as $el ) {
			$action = trim( $el->getAttribute( 'action' ) );
			if ( $action ) {
				$found[] = $this->classify_url( $action, 'form-action', $base_url );
			}
		}

		// Audio → media-src
		foreach ( $doc->getElementsByTagName( 'audio' ) as $el ) {
			$src = trim( $el->getAttribute( 'src' ) );
			if ( $src && ! $this->is_inline_data( $src ) ) {
				$found[] = $this->classify_url( $src, 'media-src', $base_url );
			}
		}

		// Video → media-src
		foreach ( $doc->getElementsByTagName( 'video' ) as $el ) {
			$src = trim( $el->getAttribute( 'src' ) );
			if ( $src && ! $this->is_inline_data( $src ) ) {
				$found[] = $this->classify_url( $src, 'media-src', $base_url );
			}
		}

		// <source> inside <audio>/<video> → media-src
		foreach ( $doc->getElementsByTagName( 'source' ) as $el ) {
			$src = trim( $el->getAttribute( 'src' ) );
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$parent = $el->parentNode;
			if ( $src && ! $this->is_inline_data( $src ) && $parent ) {
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$parent_tag = strtolower( $parent->nodeName );
				if ( in_array( $parent_tag, array( 'audio', 'video' ), true ) ) {
					$found[] = $this->classify_url( $src, 'media-src', $base_url );
				}
			}
		}

		// font-src: parsed from linked CSS files (fetched separately).
		foreach ( array_filter( $stylesheet_urls ) as $css_url ) {
			$font_sources = $this->parse_stylesheet_sources( $css_url, $base_url );
			$found        = array_merge( $found, $font_sources );
		}

		// Filter out null entries and same-origin sources (already covered by 'self').
		$site_host = wp_parse_url( get_site_url(), PHP_URL_HOST );
		return array_filter( $found, static fn( $item ) => null !== $item && $item['host'] !== $site_host );
	}

	// ── CSS font-src parsing ──────────────────────────────────────────────────

	/**
	 * Fetches a CSS file and extracts @font-face src: URLs for font-src.
	 *
	 * Only external (non-self) font URLs are returned; same-origin fonts are
	 * filtered out by parse_html_sources() after this method returns.
	 *
	 * @param string $css_url   Absolute URL of the stylesheet to fetch.
	 * @param string $base_url  Base URL of the originating page (for resolution).
	 * @return array            Classified source entries.
	 */
	public function parse_stylesheet_sources( string $css_url, string $base_url ): array {
		if ( empty( $css_url ) || ! filter_var( $css_url, FILTER_VALIDATE_URL ) ) {
			return array();
		}

		$response = wp_remote_get(
			$css_url,
			array(
				'timeout'    => 10,
				'user-agent' => 'WP-CSP-Discovery/' . WP_CSP_VERSION,
				'sslverify'  => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->audit->log( 'discovery', 'css_fetch_failed', "Failed to fetch CSS {$css_url}: " . $response->get_error_message(), 'warning' );
			return array();
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return array();
		}

		$css   = wp_remote_retrieve_body( $response );
		$found = array();

		// Match url() values inside @font-face blocks.
		// Pattern: @font-face { ... src: url('...') ... }
		if ( preg_match_all( '/@font-face\s*\{[^}]*src\s*:[^;}]+/i', $css, $face_blocks ) ) {
			foreach ( $face_blocks[0] as $block ) {
				if ( preg_match_all( '/url\(\s*[\'"]?([^\'"\)]+)[\'"]?\s*\)/i', $block, $url_matches ) ) {
					foreach ( $url_matches[1] as $font_url ) {
						$font_url = trim( $font_url );
						if ( $font_url && ! $this->is_inline_data( $font_url ) ) {
							$found[] = $this->classify_url( $font_url, 'font-src', $css_url );
						}
					}
				}
			}
		}

		return $found;
	}

	// ── DB upsert ─────────────────────────────────────────────────────────────

	private function upsert_sources( array $sources, string $surface ): array {
		global $wpdb;
		$table   = $wpdb->prefix . 'csp_source_inventory';
		$added   = 0;
		$updated = 0;
		$now     = current_time( 'mysql', true );

		foreach ( $sources as $src ) {
			if ( empty( $src['host'] ) ) {
				continue;
			}

			$existing = $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT id FROM {$table} WHERE surface = %s AND directive = %s AND source_host = %s LIMIT 1",
					$surface,
					$src['directive'],
					$src['host']
				)
			);

			if ( $existing ) {
				$wpdb->update(
					$table,
					array(
						'last_seen_at' => $now,
						'source_uri'   => $src['uri'],
					),
					array( 'id' => (int) $existing ),
					array( '%s', '%s' ),
					array( '%d' )
				);
				++$updated;
			} else {
				$wpdb->insert(
					$table,
					array(
						'surface'        => $surface,
						'directive'      => $src['directive'],
						'source_uri'     => $src['uri'],
						'source_scheme'  => $src['scheme'],
						'source_host'    => $src['host'],
						'approval_state' => 'pending',
						'first_seen_at'  => $now,
						'last_seen_at'   => $now,
					),
					array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
				);
				++$added;
			}
		}

		return array(
			'added'   => $added,
			'updated' => $updated,
		);
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	private function classify_url( string $raw_url, string $directive, string $base_url ): ?array {
		$resolved = $this->resolve_url( $raw_url, $base_url );
		if ( null === $resolved ) {
			return null;
		}

		$parsed = wp_parse_url( $resolved );
		if ( empty( $parsed['host'] ) ) {
			return null;
		}

		return array(
			'directive' => $directive,
			'uri'       => esc_url_raw( $resolved ),
			'host'      => strtolower( $parsed['host'] ),
			'scheme'    => strtolower( isset( $parsed['scheme'] ) ? $parsed['scheme'] : 'https' ),
		);
	}

	/**
	 * Resolves a potentially relative URL against a base URL.
	 * Returns an absolute URL string or null if resolution fails.
	 *
	 * FIX: replaces the original inline resolution logic scattered inside
	 * classify_url() with a single dedicated method, and removes the
	 * esc_attr() call that was incorrectly HTML-encoding values destined
	 * for use in HTTP headers (esc_attr encodes & which is invalid in header values).
	 * sanitize_text_field() is used instead via esc_url_raw() in classify_url().
	 */
	private function resolve_url( string $raw_url, string $base_url ): ?string {
		if ( empty( $raw_url ) ) {
			return null;
		}

		if ( str_starts_with( $raw_url, '//' ) ) {
			return 'https:' . $raw_url;
		}

		if ( str_starts_with( $raw_url, 'http://' ) || str_starts_with( $raw_url, 'https://' ) ) {
			return $raw_url;
		}

		if ( str_starts_with( $raw_url, '/' ) ) {
			$parsed_base = wp_parse_url( $base_url );
			if ( empty( $parsed_base['host'] ) ) {
				return null;
			}
			return ( isset( $parsed_base['scheme'] ) ? $parsed_base['scheme'] : 'https' ) . '://' . $parsed_base['host'] . $raw_url;
		}

		// Relative path: resolve against base URL directory.
		$base_dir = substr( $base_url, 0, (int) strrpos( $base_url, '/' ) + 1 );
		return $base_dir . $raw_url;
	}

	private function is_inline_data( string $src ): bool {
		return str_starts_with( $src, 'data:' ) || str_starts_with( $src, 'blob:' );
	}

	/**
	 * Returns representative crawl URLs for the given surface.
	 */
	private function get_crawl_urls( string $surface ): array {
		return match ( $surface ) {
			'frontend' => array( get_home_url( '/' ) ),
			'admin'    => array( admin_url() ),
			'login'    => array( wp_login_url() ),
			'api'      => array( rest_url() ),
			default    => array(),
		};
	}
}
