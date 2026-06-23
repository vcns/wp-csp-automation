<?php
/**
 * Plugin Name:       VCNS - CSP Manager
 * Plugin URI:        https://github.com/vcns/wp-csp-automation
 * Description:       Automated Content Security Policy (CSP) management for WordPress — source discovery, hash inventory, violation reporting, and enforce/report-only mode switching. Pro features available via VCNS hosted service.
 * Version:           0.2.0
 * Requires at least: 6.4
 * Tested up to:      6.7
 * Requires PHP:      8.1
 * Author:            VCNS Tech Ltd
 * Author URI:        https://github.com/vcns
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       vcns-csp-manager
 * Domain Path:       /languages
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Core constants ────────────────────────────────────────────────────────────
define( 'WP_CSP_VERSION', '0.2.0' );

/**
 * Schema version. Increment whenever a database schema change is made.
 * maybe_upgrade_db() in Plugin compares this against the stored value and
 * calls Activator::activate() (which runs dbDelta) when they differ.
 *
 * v1 -- initial schema (7 tables)
 * v2 -- adds override_expires_at and override_owner to csp_policy_profiles
 * v3 -- adds sample column to csp_violation_reports (R7: report-sample support)
 * v4 -- adds csp_audit_log append-only table (R10: immutable audit log)
 */
define( 'WP_CSP_DB_VERSION', '4' );

define( 'WP_CSP_FILE', __FILE__ );
define( 'WP_CSP_DIR', plugin_dir_path( __FILE__ ) );
define( 'WP_CSP_URL', plugin_dir_url( __FILE__ ) );

/**
 * Ed25519 public key (Base64) for remote config signature verification.
 * Generated with sodium_crypto_sign_keypair(). Replace this placeholder
 * once you have run the key-generation script — see offline/keygen.php.
 * The Stripe publishable key goes in WordPress settings, NOT here.
 *
 * Overridable by defining WP_CSP_CONFIG_PUBLIC_KEY in wp-config.php before
 * this plugin loads. Do not make this filterable — a plugin-layer override
 * could redirect signature verification to an attacker-controlled key.
 */
defined( 'WP_CSP_CONFIG_PUBLIC_KEY' ) || define( 'WP_CSP_CONFIG_PUBLIC_KEY', 'D/9fAq0rZLeWbHeh8hK0+C0viK36f+ee4LcP2D/J3Tg=' );

/**
 * DNS TXT record queried to discover the latest signed config URL.
 *
 * Overridable by defining WP_CSP_CONFIG_DNS_RECORD in wp-config.php.
 */
defined( 'WP_CSP_CONFIG_DNS_RECORD' ) || define( 'WP_CSP_CONFIG_DNS_RECORD', 'wp-csp-automation.jacksonfamily.me' );

/**
 * Base URL of the Cloudflare Worker that serves config, handles checkout
 * session creation, and stores entitlements. All Stripe keys live here as
 * Worker secrets — never on the customer's WordPress installation.
 *
 * Overridable by defining WP_CSP_WORKER_URL in wp-config.php. Do not make
 * this filterable — a plugin-layer override could redirect requests to a
 * malicious endpoint.
 */
defined( 'WP_CSP_WORKER_URL' ) || define( 'WP_CSP_WORKER_URL', 'https://wp-csp-config.jacksonfamily.me' );

// ── PSR-4 autoloader ──────────────────────────────────────────────────────────
spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'WP_CSP\\';
		if ( strncmp( $prefix, $class_name, strlen( $prefix ) ) !== 0 ) {
			return;
		}
		$relative = substr( $class_name, strlen( $prefix ) );
		$parts    = explode( '\\', $relative );
		$filename = 'class-' . strtolower( str_replace( '_', '-', (string) array_pop( $parts ) ) ) . '.php';
		$subdir   = ! empty( $parts ) ? strtolower( implode( '/', $parts ) ) . '/' : '';

		// Public includes/ directory.
		$file = WP_CSP_DIR . 'includes/' . $subdir . $filename;
		if ( is_readable( $file ) ) {
			require $file;
			return;
		}

		// offline/ directory: proprietary modules never committed to the repository.
		$file = WP_CSP_DIR . 'offline/' . $subdir . $filename;
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

// ── Lifecycle hooks ───────────────────────────────────────────────────────────
register_activation_hook( __FILE__, array( 'WP_CSP\\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WP_CSP\\Deactivator', 'deactivate' ) );

// ── Bootstrap ─────────────────────────────────────────────────────────────────
add_action(
	'plugins_loaded',
	static function (): void {
		WP_CSP\Plugin::instance()->init();
	}
);
