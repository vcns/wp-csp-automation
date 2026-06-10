<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 * Removes all custom database tables and option entries.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// ── Drop custom tables ────────────────────────────────────────────────────────
$tables = array(
	'csp_policy_profiles',
	'csp_source_inventory',
	'csp_hash_inventory',
	'csp_violation_reports',
	'csp_scan_logs',
	'csp_entitlements',
	'csp_processed_events',
	'csp_audit_log',
);

foreach ( $tables as $table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" );
}

// ── Delete options ────────────────────────────────────────────────────────────
$options = array(
	'wp_csp_db_version',
	'wp_csp_config_dns_domain',
	'wp_csp_config_fallback_url',
	'wp_csp_config_cache_ttl',
	'wp_csp_config_grace_ttl',
	'wp_csp_config_last_fetched',
	'wp_csp_config_version',
	'wp_csp_entitlement_grace_hours',
	'wp_csp_enforce_gate_violation_window',
	'wp_csp_cron_hour',
	'wp_csp_notify_email',
	'wp_csp_violation_retention_days',
	'wp_csp_admin_notices',
);

foreach ( $options as $option ) {
	delete_option( $option );
}

// ── Remove transients ─────────────────────────────────────────────────────────
delete_transient( 'wp_csp_remote_config' );
delete_transient( 'wp_csp_config_stale' );
delete_transient( 'wp_csp_conflict_probe_ran' );

// ── Clear scheduled hooks ─────────────────────────────────────────────────────
wp_clear_scheduled_hook( 'wp_csp_daily_scan' );
