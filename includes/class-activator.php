<?php
/**
 * Fired during plugin activation.
 * Creates custom database tables, seeds default option values, and
 * schedules the daily rescan cron event.
 *
 * Schema version 2 adds override_expires_at and override_owner to
 * csp_policy_profiles to support the full promotion gate checks (§4.12).
 */

declare( strict_types=1 );

namespace WP_CSP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Activator {

	public static function activate(): void {
		self::create_tables();
		self::set_default_options();
		self::seed_default_profiles();
		self::schedule_events();
	}

	// ── Database tables ───────────────────────────────────────────────────────

	private static function create_tables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$cc = $wpdb->get_charset_collate();
		$p  = $wpdb->prefix;

		// 1. Per-surface CSP policy profiles
		// v2: adds override_expires_at and override_owner for promotion gate §4.12.
		dbDelta(
			"CREATE TABLE {$p}csp_policy_profiles (
  id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  surface varchar(32) NOT NULL,
  mode varchar(16) NOT NULL DEFAULT 'report-only',
  directives longtext NOT NULL,
  overrides longtext NOT NULL,
  strict_dynamic tinyint(1) NOT NULL DEFAULT 0,
  override_expires_at datetime DEFAULT NULL,
  override_owner varchar(255) DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY surface (surface)
) {$cc};"
		);

		// 2. Discovered / approved external source URLs
		dbDelta(
			"CREATE TABLE {$p}csp_source_inventory (
  id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  surface varchar(32) NOT NULL,
  directive varchar(64) NOT NULL,
  source_uri varchar(2048) NOT NULL,
  source_scheme varchar(16) NOT NULL,
  source_host varchar(255) NOT NULL,
  owner_component varchar(255) DEFAULT NULL,
  owner_type varchar(32) DEFAULT NULL,
  approval_state varchar(16) NOT NULL DEFAULT 'pending',
  first_seen_at datetime NOT NULL,
  last_seen_at datetime NOT NULL,
  approved_at datetime DEFAULT NULL,
  expires_at datetime DEFAULT NULL,
  notes text DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY surface (surface),
  KEY directive (directive),
  KEY approval_state (approval_state),
  UNIQUE KEY surf_dir_host (surface, directive, source_host(191))
) {$cc};"
		);

		// 3. Inline script/style SHA-256 hashes
		dbDelta(
			"CREATE TABLE {$p}csp_hash_inventory (
  id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  surface varchar(32) NOT NULL,
  directive varchar(64) NOT NULL,
  hash_algo varchar(16) NOT NULL DEFAULT 'sha256',
  hash_value varchar(128) NOT NULL,
  content_fingerprint varchar(64) NOT NULL,
  source_file varchar(512) DEFAULT NULL,
  source_context text DEFAULT NULL,
  status varchar(16) NOT NULL DEFAULT 'active',
  first_seen_at datetime NOT NULL,
  last_seen_at datetime NOT NULL,
  retired_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY surface (surface),
  KEY directive (directive),
  KEY status (status),
  UNIQUE KEY hash_uniq (directive, hash_value)
) {$cc};"
		);

		// 4. Ingested CSP violation reports
		dbDelta(
			"CREATE TABLE {$p}csp_violation_reports (
  id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  profile_surface varchar(32) NOT NULL,
  blocked_uri varchar(2048) NOT NULL,
  document_uri varchar(2048) DEFAULT NULL,
  violated_directive varchar(128) NOT NULL,
  effective_directive varchar(128) DEFAULT NULL,
  original_policy text DEFAULT NULL,
  source_file varchar(512) DEFAULT NULL,
  line_number int(11) DEFAULT NULL,
  column_number int(11) DEFAULT NULL,
  status_code smallint(6) DEFAULT NULL,
  disposition varchar(16) NOT NULL DEFAULT 'report',
  referrer varchar(2048) DEFAULT NULL,
  user_agent varchar(512) DEFAULT NULL,
  reported_at datetime NOT NULL,
  fingerprint varchar(64) NOT NULL,
  occurrence_count int(11) NOT NULL DEFAULT 1,
  PRIMARY KEY  (id),
  KEY profile_surface (profile_surface),
  KEY violated_directive (violated_directive),
  KEY fingerprint (fingerprint),
  KEY reported_at (reported_at)
) {$cc};"
		);

		// 5. Scan / rescan run history
		dbDelta(
			"CREATE TABLE {$p}csp_scan_logs (
  id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  trigger_type varchar(16) NOT NULL,
  status varchar(16) NOT NULL DEFAULT 'running',
  sources_added int(11) NOT NULL DEFAULT 0,
  sources_removed int(11) NOT NULL DEFAULT 0,
  hashes_added int(11) NOT NULL DEFAULT 0,
  hashes_removed int(11) NOT NULL DEFAULT 0,
  policy_changed tinyint(1) NOT NULL DEFAULT 0,
  diff_summary text DEFAULT NULL,
  warnings text DEFAULT NULL,
  started_at datetime NOT NULL,
  completed_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY status (status),
  KEY trigger_type (trigger_type)
) {$cc};"
		);

		// 6. Per-site payment entitlements
		dbDelta(
			"CREATE TABLE {$p}csp_entitlements (
  id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  site_identity varchar(255) NOT NULL,
  product_key varchar(64) NOT NULL,
  tier varchar(32) NOT NULL DEFAULT 'free',
  status varchar(16) NOT NULL DEFAULT 'active',
  stripe_customer_id varchar(64) DEFAULT NULL,
  stripe_session_id varchar(255) DEFAULT NULL,
  stripe_payment_intent_id varchar(255) DEFAULT NULL,
  config_version varchar(32) DEFAULT NULL,
  granted_at datetime NOT NULL,
  expires_at datetime DEFAULT NULL,
  revoked_at datetime DEFAULT NULL,
  revocation_reason varchar(255) DEFAULT NULL,
  grace_until datetime DEFAULT NULL,
  last_validated_at datetime DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY site_identity (site_identity(191)),
  KEY product_key (product_key),
  KEY status (status),
  UNIQUE KEY session_id (stripe_session_id)
) {$cc};"
		);

		// 7. Stripe event idempotency log
		dbDelta(
			"CREATE TABLE {$p}csp_processed_events (
  id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  stripe_event_id varchar(255) NOT NULL,
  stripe_session_id varchar(255) DEFAULT NULL,
  event_type varchar(128) NOT NULL,
  processed_at datetime NOT NULL,
  outcome varchar(16) NOT NULL,
  detail varchar(512) DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY stripe_event_id (stripe_event_id),
  KEY stripe_session_id (stripe_session_id)
) {$cc};"
		);

		update_option( 'wp_csp_db_version', WP_CSP_DB_VERSION );
	}

	// ── Default options ───────────────────────────────────────────────────────

	private static function set_default_options(): void {
		$defaults = array(
			'wp_csp_stripe_mode'                   => 'test',
			'wp_csp_stripe_publishable_key'        => '',
			'wp_csp_stripe_secret_key'             => '',
			'wp_csp_webhook_secret'                => '',
			'wp_csp_config_dns_domain'             => WP_CSP_CONFIG_DNS_RECORD,
			// Fallback HTTPS URL used when DNS TXT lookup fails or dns_get_record
			// is unavailable on the host. Must be a valid https:// URL pointing
			// to a signed config JSON document. Leave empty to disable.
			'wp_csp_config_fallback_url'           => '',
			'wp_csp_config_cache_ttl'              => 3600,
			'wp_csp_config_grace_ttl'              => 86400,
			'wp_csp_entitlement_grace_hours'       => 72,
			'wp_csp_cron_hour'                     => 2,
			'wp_csp_notify_email'                  => get_option( 'admin_email' ),
			// Promotion gate: minimum hours without a high-severity violation
			// before enforce mode is permitted. Default: 24 hours.
			'wp_csp_enforce_gate_violation_window' => 24,
		);

		foreach ( $defaults as $key => $value ) {
			if ( false === get_option( $key ) ) {
				add_option( $key, $value );
			}
		}
	}

	// ── Seed default CSP profiles ─────────────────────────────────────────────

	private static function seed_default_profiles(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'csp_policy_profiles';
		$now   = current_time( 'mysql', true );

		foreach ( array( 'frontend', 'admin', 'login', 'api' ) as $surface ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE surface = %s LIMIT 1", $surface ) );
			if ( ! $exists ) {
				$wpdb->insert(
					$table,
					array(
						'surface'             => $surface,
						'mode'                => 'report-only',
						'directives'          => wp_json_encode( self::default_directives( $surface ) ),
						'overrides'           => wp_json_encode( array() ),
						'strict_dynamic'      => 0,
						'override_expires_at' => null,
						'override_owner'      => null,
						'created_at'          => $now,
						'updated_at'          => $now,
					),
					array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
				);
			}
		}
	}

	private static function default_directives( string $surface ): array {
		$d = array(
			'default-src'     => array( "'none'" ),
			'script-src'      => array(),
			'script-src-elem' => array(),
			'script-src-attr' => array( "'none'" ),
			'style-src'       => array(),
			'style-src-elem'  => array(),
			'style-src-attr'  => array( "'none'" ),
			'img-src'         => array( "'self'", 'data:' ),
			'font-src'        => array( "'self'" ),
			'connect-src'     => array( "'self'" ),
			'frame-src'       => array( "'none'" ),
			'frame-ancestors' => array( "'none'" ),
			'base-uri'        => array( "'none'" ),
			'form-action'     => array( "'self'" ),
			'object-src'      => array( "'none'" ),
			'media-src'       => array( "'none'" ),
			'worker-src'      => array( "'none'" ),
			'manifest-src'    => array( "'self'" ),
		);

		if ( 'admin' === $surface ) {
			$d['frame-src']       = array( "'self'" );
			$d['frame-ancestors'] = array( "'self'" );
		}

		return $d;
	}

	// ── WP Cron ───────────────────────────────────────────────────────────────

	private static function schedule_events(): void {
		$hook = 'wp_csp_daily_scan';
		if ( wp_next_scheduled( $hook ) ) {
			return;
		}
		$hour      = max( 0, min( 23, (int) get_option( 'wp_csp_cron_hour', 2 ) ) );
		$now       = time();
		$today     = mktime( $hour, 0, 0, (int) gmdate( 'n', $now ), (int) gmdate( 'j', $now ), (int) gmdate( 'Y', $now ) );
		$first_run = ( $today > $now ) ? $today : $today + DAY_IN_SECONDS;
		wp_schedule_event( $first_run, 'daily', $hook );
	}
}
