<?php
/**
 * Fired during plugin deactivation.
 * Removes scheduled cron events; leaves data intact (use uninstall.php to purge).
 */

declare( strict_types=1 );

namespace WP_CSP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Deactivator {

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'wp_csp_daily_scan' );
	}
}
