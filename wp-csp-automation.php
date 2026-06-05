<?php
/**
 * Plugin Name:       WP CSP Automation Manager
 * Plugin URI:        https://github.com/sjackson0109/wp-csp-automation
 * Description:       Automates strict Content Security Policy generation, enforcement, and violation analysis for WordPress. Premium features unlocked via one-time Stripe payment.
 * Version:           0.2.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Simon Jackson
 * Author URI:        https://github.com/sjackson0109
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-csp-automation
 * Domain Path:       /languages
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Core constants ────────────────────────────────────────────────────────────
define( 'WP_CSP_VERSION',    '0.2.0' );

/**
 * Schema version. Increment whenever a database schema change is made.
 * maybe_upgrade_db() in Plugin compares this against the stored value and
 * calls Activator::activate() (which runs dbDelta) when they differ.
 *
 * v1 -- initial schema (7 tables)
 * v2 -- adds override_expires_at and override_owner to csp_policy_profiles
 */
define( 'WP_CSP_DB_VERSION', '2' );

define( 'WP_CSP_FILE',       __FILE__ );
define( 'WP_CSP_DIR',        plugin_dir_path( __FILE__ ) );
define( 'WP_CSP_URL',        plugin_dir_url( __FILE__ ) );

/**
 * Ed25519 public key for remote config signature verification.
 */
define(
	'WP_CSP_CONFIG_PUBLIC_KEY',
	'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=' // TODO: replace before distribution
);

/**
 * DNS TXT record queried to discover the latest signed config URL.
 */
define( 'WP_CSP_CONFIG_DNS_RECORD', '_wp-csp-automation.jacksonfamily.me' );

// ── PSR-4 autoloader ──────────────────────────────────────────────────────────
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
	if ( is_readable( $file ) ) {
		require $file;
	}
} );

// ── Lifecycle hooks ───────────────────────────────────────────────────────────
register_activation_hook( __FILE__, [ 'WP_CSP\\Activator', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'WP_CSP\\Deactivator', 'deactivate' ] );

// ── Bootstrap ─────────────────────────────────────────────────────────────────
add_action(
	'plugins_loaded',
	static function (): void {
		WP_CSP\Plugin::instance()->init();
	}
);