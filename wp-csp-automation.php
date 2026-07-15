<?php
/**
 * Plugin Name:       CSP Automation Manager
 * Plugin URI:        https://github.com/vcns/wp-csp-automation
 * Description:       Automates strict Content Security Policy generation, enforcement, and violation analysis for WordPress.
 * Version:           1.0.4
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            VCNS Tech Ltd
 * Author URI:        https://vcns.tech
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
define( 'WP_CSP_VERSION', '1.0.4' );

/**
 * Schema version. Increment whenever a database schema change is made.
 * maybe_upgrade_db() in Plugin compares this against the stored value and
 * calls Activator::activate() (which runs dbDelta) when they differ.
 *
 * v1 -- initial schema (7 tables)
 * v2 -- adds override_expires_at and override_owner to csp_policy_profiles
 * v3 -- adds sample column to csp_violation_reports (R7: report-sample support)
 * v4 -- adds csp_audit_log append-only table (R10: immutable audit log)
 * v5 -- adds policy change proposal metadata and decision/suppression ledger
 * v6 -- adds violation first/last reported roll-up timestamps and unique fingerprint upsert support
 * v7 -- adds decision provenance, policy version snapshots, and deterministic rule evaluations
 */
define( 'WP_CSP_DB_VERSION', '7' );

define( 'WP_CSP_FILE', __FILE__ );
define( 'WP_CSP_DIR', plugin_dir_path( __FILE__ ) );
define( 'WP_CSP_URL', plugin_dir_url( __FILE__ ) );


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
