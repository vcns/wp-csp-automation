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
define( 'WP_CSP_DB_VERSION', '1' );
define( 'WP_CSP_FILE',       __FILE__ );
define( 'WP_CSP_DIR',        plugin_dir_path( __FILE__ ) );
define( 'WP_CSP_URL',        plugin_dir_url( __FILE__ ) );

/**
 * Ed25519 public key for remote config signature verification.
 *
 * Generate your key pair with:
 *   $kp = sodium_crypto_sign_keypair();
 *   echo base64_encode( sodium_crypto_sign_publickey( $kp ) );
 *
 * Place the secret key on your config-serving infrastructure.
 * Ship only this public key in the plugin.
 */
define(
	'WP_CSP_CONFIG_PUBLIC_KEY',
	'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=' // TODO: replace before distribution
);

/**
 * DNS TXT record queried to discover the latest signed config URL.
 * Format: v=1;cfg=https://…/config.json
 */
define( 'WP_CSP_CONFIG_DNS_RECORD', '_csp-config.wp-csp-automation.dev' );

// ── PSR-4 autoloader ──────────────────────────────────────────────────────────
// Maps WP_CSP\{Sub}\{Class_Name} → includes/{sub}/class-{class-name}.php
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
