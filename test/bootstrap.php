<?php
/**
 * PHPUnit bootstrap file for WP CSP Automation unit tests.
 *
 * Defines plugin constants and stubs for WordPress globals and functions so
 * the plugin classes can be loaded and exercised without a WordPress install.
 *
 * Only the functions actually called by the classes under test are stubbed.
 * Add stubs here as new test files require them.
 */

declare( strict_types=1 );

// ── Plugin constants ──────────────────────────────────────────────────────────
define( 'ABSPATH',               __DIR__ . '/' );
define( 'WP_CSP_VERSION',        '0.2.0' );
define( 'WP_CSP_DB_VERSION',     '4' );
define( 'WP_CSP_FILE',           dirname( __DIR__ ) . '/wp-csp-automation.php' );
define( 'WP_CSP_DIR',            dirname( __DIR__ ) . '/' );
define( 'WP_CSP_URL',            'https://example.com/wp-content/plugins/wp-csp-automation/' );
define( 'WP_CSP_CONFIG_PUBLIC_KEY', 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=' );
define( 'WP_CSP_CONFIG_DNS_RECORD', '_csp-config.wp-csp-automation.dev' );
define( 'HOUR_IN_SECONDS',       3600 );
define( 'DAY_IN_SECONDS',        86400 );
if ( ! defined( 'DNS_TXT' ) ) {
	define( 'DNS_TXT', 16 );
}
define( 'WP_CSP_WORKER_URL',     'https://wp-csp-config.example.com' );
define( 'ARRAY_A',               'ARRAY_A' );
define( 'ARRAY_N',               'ARRAY_N' );
define( 'OBJECT',                'OBJECT' );

// ── PSR-4 autoloader (mirrors wp-csp-automation.php) ─────────────────────────
spl_autoload_register( static function ( string $class ): void {
	$prefix = 'WP_CSP\\';
	if ( strncmp( $prefix, $class, strlen( $prefix ) ) !== 0 ) {
		return;
	}
	$relative = substr( $class, strlen( $prefix ) );
	$parts    = explode( '\\', $relative );
	$filename = 'class-' . strtolower( str_replace( '_', '-', (string) array_pop( $parts ) ) ) . '.php';
	$subdir   = ! empty( $parts ) ? strtolower( implode( '/', $parts ) ) . '/' : '';
	$file     = WP_CSP_DIR . 'includes/' . $subdir . $filename;
	if ( ! is_readable( $file ) ) {
		$file = WP_CSP_DIR . 'offline/' . $subdir . $filename;
	}
	if ( is_readable( $file ) ) {
		require $file;
	} else {
		trigger_error( "WP_CSP test autoloader: cannot resolve {$class}", E_USER_NOTICE );
	}
} );

// ── WordPress function stubs ──────────────────────────────────────────────────
// These are minimal implementations that satisfy the function signatures
// called by the classes under test. They do not replicate WordPress behaviour
// beyond what is needed for the assertions in the test suite.

if ( ! function_exists( 'current_time' ) ) {
	function current_time( string $type, bool $gmt = false ): string {
		return gmdate( 'Y-m-d H:i:s' );
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $option, mixed $default = false ): mixed {
		return $GLOBALS['_wp_options'][ $option ] ?? $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $option, mixed $value, bool|string $autoload = true ): bool {
		$GLOBALS['_wp_options'][ $option ] = $value;
		return true;
	}
}

if ( ! function_exists( 'add_option' ) ) {
	function add_option( string $option, mixed $value = '', string $deprecated = '', bool $autoload = true ): bool {
		if ( ! isset( $GLOBALS['_wp_options'][ $option ] ) ) {
			$GLOBALS['_wp_options'][ $option ] = $value;
			return true;
		}
		return false;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( string $option ): bool {
		unset( $GLOBALS['_wp_options'][ $option ] );
		return true;
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( string $transient ): mixed {
		return $GLOBALS['_wp_transients'][ $transient ] ?? false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( string $transient, mixed $value, int $expiration = 0 ): bool {
		$GLOBALS['_wp_transients'][ $transient ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( string $transient ): bool {
		unset( $GLOBALS['_wp_transients'][ $transient ] );
		return true;
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( string $str ): string {
		return trim( strip_tags( $str ) );
	}
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( string $str ): string {
		return trim( strip_tags( $str ) );
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( string $url ): string {
		return filter_var( $url, FILTER_SANITIZE_URL ) ?: '';
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( mixed $data, int $options = 0, int $depth = 512 ): string|false {
		return json_encode( $data, $options, $depth );
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( string $url, int $component = -1 ): mixed {
		return parse_url( $url, $component );
	}
}

if ( ! function_exists( 'wp_remote_get' ) ) {
	function wp_remote_get( string $url, array $args = [] ): array|WP_Error {
		return $GLOBALS['_wp_remote_get_response'] ?? [ 'response' => [ 'code' => 200 ], 'body' => '' ];
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( array|WP_Error $response ): int|string {
		return $response['response']['code'] ?? 0;
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( array|WP_Error $response ): string {
		return $response['body'] ?? '';
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( mixed $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( 'rest_url' ) ) {
	function rest_url( string $path = '' ): string {
		return 'https://example.com/wp-json/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'get_site_url' ) ) {
	function get_site_url( int $blog_id = 0, string $path = '', string $scheme = '' ): string {
		return 'https://example.com';
	}
}

if ( ! function_exists( 'home_url' ) ) {
	function home_url( string $path = '', string $scheme = '' ): string {
		return 'https://example.com' . ( '' !== $path ? '/' . ltrim( $path, '/' ) : '' );
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( string $path = '', string $scheme = 'admin' ): string {
		return 'https://example.com/wp-admin/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['_wp_actions'][ $hook ][] = [ $callback, $priority, $accepted_args ];
		return true;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		return add_action( $hook, $callback, $priority, $accepted_args );
	}
}

if ( ! function_exists( 'headers_sent' ) ) {
	// Intentionally not overriding the native headers_sent() -- it is a PHP
	// built-in and behaves correctly in a CLI test context (always returns false).
}

if ( ! function_exists( 'hash_equals' ) ) {
	// Native PHP function; already available in PHP 8.1+. Stub only if absent.
}

// ── WP_Error / REST stubs ─────────────────────────────────────────────────────
if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		public function __construct( public string $method = 'POST', public string $route = '' ) {}

		public function get_body(): string {
			return $GLOBALS['_wp_rest_body'] ?? '';
		}

		public function get_header( string $name ): ?string {
			return $GLOBALS['_wp_rest_headers'][ $name ] ?? null;
		}

		public function get_content_type(): ?array {
			$raw = $GLOBALS['_wp_rest_headers']['content-type'] ?? null;
			if ( null === $raw ) {
				return null;
			}
			$parts = explode( ';', $raw, 2 );
			return array(
				'value'      => trim( $parts[0] ),
				'parameters' => isset( $parts[1] ) ? array( 'params' => trim( $parts[1] ) ) : array(),
			);
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		public function __construct( public mixed $data = null, public int $status = 200 ) {}

		public function get_status(): int {
			return $this->status;
		}

		public function get_data(): mixed {
			return $this->data;
		}
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private string $code;
		private string $message;

		public function __construct( string $code = '', string $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}
}

// ── wpdb stub ─────────────────────────────────────────────────────────────────
// Minimal implementation that returns configurable values from globals.
// Tests set $GLOBALS['_wpdb_*'] before calling the code under test.
if ( ! class_exists( 'wpdb_stub' ) ) {
	class wpdb_stub {
		public string  $prefix     = 'wp_';
		public ?string $last_error = null;

		public function prepare( string $query, mixed ...$args ): string {
			$i = 0;
			return (string) preg_replace_callback(
				'/%%|%(s|d)/',
				static function ( array $m ) use ( &$i, $args ): string {
					if ( '%%' === $m[0] ) {
						return '%';
					}
					$val = $args[ $i++ ] ?? '';
					return 's' === $m[1]
						? "'" . addslashes( (string) $val ) . "'"
						: (string) (int) $val;
				},
				$query
			);
		}

		public function get_var( string $query ): mixed {
			return $GLOBALS['_wpdb_get_var'] ?? null;
		}

		public function get_row( string $query, string $output = 'ARRAY_A' ): mixed {
			return $GLOBALS['_wpdb_get_row'] ?? null;
		}

		public function get_results( string $query, string $output = 'ARRAY_A' ): array {
			return $GLOBALS['_wpdb_get_results'] ?? [];
		}

		public function query( string $sql ): int|false {
			$GLOBALS['_wpdb_last_operation'] = 'query';
			return $GLOBALS['_wpdb_query_result'] ?? 1;
		}

		public function insert( string $table, array $data, array $format = [] ): int|false {
			$GLOBALS['_wpdb_last_operation'] = 'insert';
			$GLOBALS['_wpdb_inserted_rows'][] = array(
				'table'  => $table,
				'data'   => $data,
				'format' => $format,
			);
			return $GLOBALS['_wpdb_insert_result'] ?? 1;
		}

		public function update( string $table, array $data, array $where, array $format = [], array $where_format = [] ): int|false {
			$GLOBALS['_wpdb_last_operation'] = 'update';
			$GLOBALS['_wpdb_updated_rows'][] = array(
				'table'        => $table,
				'data'         => $data,
				'where'        => $where,
				'format'       => $format,
				'where_format' => $where_format,
			);
			return $GLOBALS['_wpdb_update_result'] ?? 0;
		}

		public function get_charset_collate(): string {
			return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
		}
	}
}

// ── WordPress function stubs (activation / cron) ──────────────────────────────

if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( string $hook, array $args = [] ): int|false {
		return $GLOBALS['_wp_cron'][ $hook ] ?? false;
	}
}

if ( ! function_exists( 'wp_schedule_event' ) ) {
	function wp_schedule_event( int $timestamp, string $recurrence, string $hook ): bool {
		$GLOBALS['_wp_cron'][ $hook ] = $timestamp;
		return true;
	}
}

if ( ! function_exists( 'wp_unschedule_hook' ) ) {
	function wp_unschedule_hook( string $hook ): int {
		unset( $GLOBALS['_wp_cron'][ $hook ] );
		return 1;
	}
}

if ( ! function_exists( 'dbDelta' ) ) {
	function dbDelta( string $queries = '' ): array {
		return [];
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $capability ): bool {
		return $GLOBALS['_wp_current_user_can'][ $capability ] ?? false;
	}
}

// ── Global state reset helper ─────────────────────────────────────────────────
// Call this in setUp() to start each test with a clean slate.
function wp_test_reset_globals(): void {
	$GLOBALS['_wp_options']              = [];
	$GLOBALS['_wp_transients']           = [];
	$GLOBALS['_wp_actions']              = [];
	$GLOBALS['_wp_remote_get_response']  = null;
	$GLOBALS['_wp_cron']                 = [];
	$GLOBALS['_wp_current_user_can']     = [];
	$GLOBALS['_wpdb_get_var']            = null;
	$GLOBALS['_wpdb_get_row']            = null;
	$GLOBALS['_wpdb_get_results']        = [];
	$GLOBALS['_wpdb_insert_result']      = 1;
	$GLOBALS['_wpdb_update_result']      = 0;
	$GLOBALS['wpdb']                     = new wpdb_stub();
	$GLOBALS['_wp_csp_test_nonce']       = '';
	$GLOBALS['_wp_rest_body']            = '';
	$GLOBALS['_wp_rest_headers']         = [];
	$GLOBALS['_wpdb_query_result']       = 1;
	$GLOBALS['_wpdb_last_operation']     = null;
	$GLOBALS['_wpdb_inserted_rows']      = [];
	$GLOBALS['_wpdb_updated_rows']       = [];
}

// Initialise globals so classes loaded at parse time do not hit undefined array errors.
wp_test_reset_globals();

// ── Test stubs ────────────────────────────────────────────────────────────────
// Load namespace-scoped stubs before any plugin class that might define the
// real counterpart. Order matters: stubs must come first.
require_once __DIR__ . '/unit/NonceBridge.php';
