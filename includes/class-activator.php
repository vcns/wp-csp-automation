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
		self::seed_initial_policy_versions();
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
  risk_level varchar(16) NOT NULL DEFAULT 'low',
  risk_reason text DEFAULT NULL,
  decision_fingerprint varchar(64) DEFAULT NULL,
  evidence_count int(11) NOT NULL DEFAULT 1,
  last_decision varchar(16) DEFAULT NULL,
  decision_reason text DEFAULT NULL,
  decided_at datetime DEFAULT NULL,
  decided_by bigint(20) UNSIGNED DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY surface (surface),
  KEY directive (directive),
  KEY approval_state (approval_state),
  KEY risk_level (risk_level),
  KEY decision_fingerprint (decision_fingerprint),
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
		// v3: adds sample column — populated only when 'report-sample' is in the policy.
		// v6: adds first/last roll-up timestamps and unique fingerprint support.
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
  sample varchar(256) DEFAULT NULL,
  reported_at datetime NOT NULL,
  first_reported_at datetime DEFAULT NULL,
  last_reported_at datetime DEFAULT NULL,
  fingerprint varchar(64) NOT NULL,
  occurrence_count int(11) NOT NULL DEFAULT 1,
  PRIMARY KEY  (id),
  KEY profile_surface (profile_surface),
  KEY violated_directive (violated_directive),
  KEY reported_at (reported_at),
  KEY last_reported_at (last_reported_at),
  UNIQUE KEY fingerprint (fingerprint)
) {$cc};"
		);

		self::migrate_violation_report_rollups();

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

		// 6. Legacy per-site entitlement compatibility records.
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

		// 7. Legacy external event idempotency log.
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

		// 8. Append-only structured audit log (R10).
		// No UPDATE or DELETE is ever issued against this table — it is an immutable record.
		dbDelta(
			"CREATE TABLE {$p}csp_audit_log (
  id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  component varchar(64) NOT NULL,
  event varchar(128) NOT NULL,
  detail text DEFAULT NULL,
  severity varchar(16) NOT NULL DEFAULT 'info',
  user_id bigint(20) UNSIGNED DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY severity (severity),
  KEY created_at (created_at)
) {$cc};"
		);

		// 9. Append-only policy change decision ledger.
		// v7 extends this with provenance fields; existing action/suppression semantics remain authoritative.
		dbDelta(
			"CREATE TABLE {$p}csp_policy_change_decisions (
  id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  change_type varchar(32) NOT NULL DEFAULT 'source',
  source_inventory_id bigint(20) UNSIGNED DEFAULT NULL,
  surface varchar(32) NOT NULL,
  directive varchar(64) NOT NULL,
  source_host varchar(255) DEFAULT NULL,
  source_uri varchar(2048) DEFAULT NULL,
  decision_fingerprint varchar(64) NOT NULL,
  action varchar(16) NOT NULL,
  state varchar(24) NOT NULL DEFAULT '',
  risk_level varchar(16) NOT NULL DEFAULT 'low',
  risk_reason text DEFAULT NULL,
  reason text DEFAULT NULL,
  user_id bigint(20) UNSIGNED DEFAULT NULL,
  actor_type varchar(32) NOT NULL DEFAULT 'administrator',
  actor_id varchar(64) DEFAULT NULL,
  previous_policy_version_id bigint(20) UNSIGNED DEFAULT NULL,
  policy_version_id bigint(20) UNSIGNED DEFAULT NULL,
  decision_engine_version varchar(32) DEFAULT NULL,
  deterministic_result longtext DEFAULT NULL,
  evidence_snapshot longtext DEFAULT NULL,
  reverted_decision_id bigint(20) UNSIGNED DEFAULT NULL,
  software_version varchar(32) DEFAULT NULL,
  suppression_active tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY source_inventory_id (source_inventory_id),
  KEY decision_fingerprint (decision_fingerprint),
  KEY action (action),
  KEY state (state),
  KEY actor_type (actor_type),
  KEY policy_version_id (policy_version_id),
  KEY risk_level (risk_level),
  KEY suppression_active (suppression_active),
  KEY created_at (created_at)
) {$cc};"
		);

		// 10. Immutable policy version snapshots per surface.
		dbDelta(
			"CREATE TABLE {$p}csp_policy_versions (
  id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  surface varchar(32) NOT NULL,
  version_number bigint(20) UNSIGNED NOT NULL,
  mode varchar(16) NOT NULL DEFAULT 'report-only',
  effective_header longtext NOT NULL,
  policy_snapshot longtext NOT NULL,
  previous_version_id bigint(20) UNSIGNED DEFAULT NULL,
  trigger_type varchar(32) NOT NULL DEFAULT 'system',
  trigger_id bigint(20) UNSIGNED DEFAULT NULL,
  software_version varchar(32) DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY surface_version (surface, version_number),
  KEY surface (surface),
  KEY previous_version_id (previous_version_id),
  KEY trigger (trigger_type, trigger_id),
  KEY created_at (created_at)
) {$cc};"
		);

		// 11. Deterministic rule evaluation provenance.
		dbDelta(
			"CREATE TABLE {$p}csp_decision_rule_evaluations (
  id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  proposal_id bigint(20) UNSIGNED DEFAULT NULL,
  decision_id bigint(20) UNSIGNED DEFAULT NULL,
  engine_version varchar(32) NOT NULL,
  rule_id varchar(32) NOT NULL,
  rule_version varchar(16) NOT NULL,
  result varchar(16) NOT NULL,
  risk_effect varchar(16) DEFAULT NULL,
  automation_effect varchar(32) DEFAULT NULL,
  explanation text DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY proposal_id (proposal_id),
  KEY decision_id (decision_id),
  KEY rule_id (rule_id),
  KEY created_at (created_at)
) {$cc};"
		);

		update_option( 'wp_csp_db_version', WP_CSP_DB_VERSION );
	}

	private static function migrate_violation_report_rollups(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'csp_violation_reports';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $table_exists !== $table ) {
			return;
		}

		// Backfill new roll-up timestamps from the legacy reported_at column.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "UPDATE {$table} SET first_reported_at = reported_at WHERE first_reported_at IS NULL" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "UPDATE {$table} SET last_reported_at = reported_at WHERE last_reported_at IS NULL" );

		// Collapse any historic duplicate fingerprints before enforcing uniqueness.
		// The current reporter has deduped for some time, but this keeps upgrades safe.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$duplicates = $wpdb->get_results(
			"SELECT fingerprint, MIN(id) AS keep_id, SUM(occurrence_count) AS total_count, MIN(first_reported_at) AS first_seen, MAX(last_reported_at) AS last_seen
			 FROM {$table}
			 GROUP BY fingerprint
			 HAVING COUNT(*) > 1",
			ARRAY_A
		);

		foreach ( is_array( $duplicates ) ? $duplicates : array() as $duplicate ) {
			$keep_id = (int) $duplicate['keep_id'];
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"UPDATE {$table}
					 SET occurrence_count = %d, first_reported_at = %s, last_reported_at = %s, reported_at = %s
					 WHERE id = %d",
					(int) $duplicate['total_count'],
					(string) $duplicate['first_seen'],
					(string) $duplicate['last_seen'],
					(string) $duplicate['last_seen'],
					$keep_id
				)
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"DELETE FROM {$table} WHERE fingerprint = %s AND id <> %d",
					(string) $duplicate['fingerprint'],
					$keep_id
				)
			);
		}

		// Convert the fingerprint index to a unique index if required.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$index = $wpdb->get_row( "SHOW INDEX FROM {$table} WHERE Key_name = 'fingerprint' AND Non_unique = 0" );
		if ( null === $index ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$table} DROP INDEX fingerprint" );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$table} ADD UNIQUE KEY fingerprint (fingerprint)" );
		}
	}

	// ── Default options ───────────────────────────────────────────────────────

	private static function set_default_options(): void {
		$defaults = array(
			'wp_csp_config_dns_domain'             => '',
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
			// Data retention: violation reports older than this many days are purged
			// by the daily cron scan. 0 = keep forever (not recommended for busy sites).
			'wp_csp_violation_retention_days'      => 90,
			// Report-endpoint learning closes after this many hours from the latest
			// post, page, or plugin material change.
			'wp_csp_learning_window_hours'         => 48,
			'wp_csp_last_material_change_at'       => current_time( 'mysql', true ),
			'wp_csp_automation_config'             => self::default_automation_config(),
		);

		foreach ( $defaults as $key => $value ) {
			if ( false === get_option( $key ) ) {
				add_option( $key, $value );
			}
		}
	}

	private static function default_automation_config(): array {
		$surface_config = array(
			'mode'                           => 'manual',
			'enabled_directives'             => array(),
			'excluded_directives'            => array(),
			'allowed_source_schemes'         => array( 'https' ),
			'treat_same_origin_as_low'       => true,
			'treat_known_cdn_as_low'         => false,
			'allow_wildcards'                => false,
			'allow_cleartext_http'           => false,
			'allow_browser_schemes'          => false,
			'allow_ip_literals'              => false,
			'allow_non_standard_ports'       => false,
			'approval_confidence_threshold'  => 1.0,
			'require_ai_agreement'           => false,
			'automatic_rejection_enabled'    => false,
			'max_automatic_changes_per_scan' => 0,
			'change_rate_guardrail'          => 0,
			'emergency_disabled'             => true,
		);

		return array(
			'frontend' => $surface_config,
			'admin'    => $surface_config,
			'login'    => $surface_config,
			'api'      => $surface_config,
		);
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

	private static function seed_initial_policy_versions(): void {
		if ( ! class_exists( 'WP_CSP\CSP\Policy_Version_Manager' ) ) {
			return;
		}

		global $wpdb;
		$table   = $wpdb->prefix . 'csp_policy_versions';
		$manager = new \WP_CSP\CSP\Policy_Version_Manager();

		foreach ( array( 'frontend', 'admin', 'login', 'api' ) as $surface ) {
			$exists = $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT id FROM {$table} WHERE surface = %s LIMIT 1",
					$surface
				)
			);

			if ( ! $exists ) {
				$manager->capture_snapshot( $surface, 'system_migration', 0 );
			}
		}
	}

	private static function default_directives( string $surface ): array {
		// 'report-sample' added to script/style-src so browsers include the offending
		// inline code snippet in violation reports (R7). Harmless when no violation occurs.
		$d = array(
			'default-src'               => array( "'none'" ),
			'script-src'                => array( "'report-sample'" ),
			'script-src-elem'           => array( "'report-sample'" ),
			'script-src-attr'           => array( "'none'" ),
			'style-src'                 => array( "'report-sample'" ),
			'style-src-elem'            => array( "'report-sample'" ),
			'style-src-attr'            => array( "'none'" ),
			'img-src'                   => array( "'self'", 'data:' ),
			'font-src'                  => array( "'self'" ),
			'connect-src'               => array( "'self'" ),
			'frame-src'                 => array( "'none'" ),
			'frame-ancestors'           => array( "'none'" ),
			'base-uri'                  => array( "'none'" ),
			'form-action'               => array( "'self'" ),
			'object-src'                => array( "'none'" ),
			'media-src'                 => array( "'none'" ),
			// worker-src: explicitly set on all surfaces. child-src is also set as a
			// legacy fallback: Safari falls back worker-src → child-src → script-src,
			// so without child-src the nonce would bleed through to workers in Safari.
			'worker-src'                => array( "'none'" ),
			'child-src'                 => array( "'none'" ),
			'manifest-src'              => array( "'self'" ),
			// upgrade-insecure-requests: auto-upgrades http→https for sub-resource requests.
			// Boolean directive (empty array = valueless). Does NOT replace HSTS (RFC 6797).
			// Skipped on the api surface (REST responses have no navigable resources).
			// Not emitted on api surface — handled below.
			// fenced-frame-src: experimental Privacy Sandbox directive; 'none' is safe.
			'fenced-frame-src'          => array( "'none'" ),
			// sandbox: null = disabled. Set to an array of allow-* flags to enable.
			// Ignored by browsers in CSP-Report-Only mode and in <meta http-equiv>.
			'sandbox'                   => null,
			// Trusted Types directives: empty = disabled.
			// When enabled, always deploy in report-only first (Chromium-strong; R5).
			'require-trusted-types-for' => array(),
			'trusted-types'             => array(),
		);

		if ( 'admin' === $surface ) {
			$d['frame-src']       = array( "'self'" );
			$d['frame-ancestors'] = array( "'self'" );
		}

		// upgrade-insecure-requests on all surfaces except api.
		if ( 'api' !== $surface ) {
			$d['upgrade-insecure-requests'] = array();
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
