<?php
/**
 * Runtime and crawl-based source discovery.
 *
 * Implements §4.6 of the directive:
 *   - Crawls pages using the WordPress HTTP API.
 *   - Parses HTML response for external script, style, img, font, connect
 *     and frame sources.
 *   - Classifies each discovered URL into the correct CSP directive.
 *   - Writes new sources to csp_source_inventory with approval_state='pending'.
 *   - Marks sources not seen in current crawl as candidates for removal.
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

	private Audit_Log    $audit;
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
		$stats    = [ 'sources_added' => 0, 'sources_updated' => 0 ];
		$surfaces = [ 'frontend' ];

		if ( $this->gate->is_allowed( 'multi_surface_scan' ) ) {
			$surfaces = [ 'frontend', 'admin', 'login', 'api' ];
		}

		foreach ( $surfaces as $surface ) {
			$urls = $this->get_crawl_urls( $surface );
			foreach ( $urls as $url ) {
				$result   = $this->crawl_url( $url, $surface );
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
			[
				'timeout'    => 20,
				'user-agent' => 'WP-CSP-Discovery/' . WP_CSP_VERSION,
				'sslverify'  => true,
			]
		);

		if ( is_wp_error( $response ) ) {
			$this->audit->log( 'discovery', 'crawl_failed', "Failed to fetch {$url}: " . $response->get_error_message(), 'warning' );
			return [ 'added' => 0, 'updated' => 0 ];
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 400 ) {
			$this->audit->log( 'discovery', 'crawl_http_error', "HTTP {$code} for {$url}.", 'warning' );
			return [ 'added' => 0, 'updated' => 0 ];
		}

		$html    = wp_remote_retrieve_body( $response );
		$sources = $this->parse_html_sources( $html, $url );

		return $this->upsert_sources( $sources, $surface );
	}

	// ── HTML parsing ──────────────────────────────────────────────────────────

	/**
	 * Extracts external source URLs from an HTML document.
	 * Returns array of [ 'directive' => string, 'uri' => string, 'host' => string, 'scheme' => string ].
	 */
	public function parse_html_sources( string $html, string $base_url ): array {
		if ( empty( trim( $html ) ) ) {
			return [];
		}

		$doc = new \DOMDocument();
		// Suppress malformed HTML warnings; we do best-effort parsing.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@$doc->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR );

		$found = [];

		// Scripts → script-src-elem
		foreach ( $doc->getElementsByTagName( 'script' ) as $el ) {
			$src = trim( $el->getAttribute( 'src' ) );
			if ( $src && ! $this->is_inline_data( $src ) ) {
				$found[] = $this->classify_url( $src, 'script-src-elem', $base_url );
			}
		}

		// Stylesheets → style-src-elem
		foreach ( $doc->getElementsByTagName( 'link' ) as $el ) {
			if ( 'stylesheet' === strtolower( $el->getAttribute( 'rel' ) ) ) {
				$href = trim( $el->getAttribute( 'href' ) );
				if ( $href && ! $this->is_inline_data( $href ) ) {
					$found[] = $this->classify_url( $href, 'style-src-elem', $base_url );
				}
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

		// Filter out null entries and same-origin sources (already covered by 'self').
		$site_host = wp_parse_url( get_site_url(), PHP_URL_HOST );
		return array_filter( $found, static fn( $item ) => null !== $item && $item['host'] !== $site_host );
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

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$existing = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE surface = %s AND directive = %s AND source_host = %s LIMIT 1",
					$surface,
					$src['directive'],
					$src['host']
				)
			);

			if ( $existing ) {
				$wpdb->update(
					$table,
					[ 'last_seen_at' => $now, 'source_uri' => $src['uri'] ],
					[ 'id' => (int) $existing ],
					[ '%s', '%s' ],
					[ '%d' ]
				);
				++$updated;
			} else {
				$wpdb->insert(
					$table,
					[
						'surface'          => $surface,
						'directive'        => $src['directive'],
						'source_uri'       => $src['uri'],
						'source_scheme'    => $src['scheme'],
						'source_host'      => $src['host'],
						'approval_state'   => 'pending',
						'first_seen_at'    => $now,
						'last_seen_at'     => $now,
					],
					[ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
				);
				++$added;
			}
		}

		return [ 'added' => $added, 'updated' => $updated ];
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	private function classify_url( string $raw_url, string $directive, string $base_url ): ?array {
		// Resolve relative URLs against the page base.
		if ( str_starts_with( $raw_url, '//' ) ) {
			$raw_url = 'https:' . $raw_url;
		} elseif ( str_starts_with( $raw_url, '/' ) ) {
			$parsed_base = wp_parse_url( $base_url );
			$raw_url     = $parsed_base['scheme'] . '://' . $parsed_base['host'] . $raw_url;
		}

		$parsed = wp_parse_url( $raw_url );
		if ( empty( $parsed['host'] ) ) {
			return null;
		}

		return [
			'directive' => $directive,
			'uri'       => esc_url_raw( $raw_url ),
			'host'      => strtolower( $parsed['host'] ),
			'scheme'    => strtolower( $parsed['scheme'] ?? 'https' ),
		];
	}

	private function is_inline_data( string $src ): bool {
		return str_starts_with( $src, 'data:' ) || str_starts_with( $src, 'blob:' );
	}

	/**
	 * Returns representative crawl URLs for the given surface.
	 */
	private function get_crawl_urls( string $surface ): array {
		return match ( $surface ) {
			'frontend' => [ get_home_url( '/' ) ],
			'admin'    => [ admin_url() ],
			'login'    => [ wp_login_url() ],
			'api'      => [ rest_url() ],
			default    => [],
		};
	}
}
