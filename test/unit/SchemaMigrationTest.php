<?php
/**
 * Schema activation and migration metadata tests.
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use WP_CSP\Activator;

class SchemaMigrationTest extends TestCase {

	protected function setUp(): void {
		wp_test_reset_globals();
	}

	public function test_fresh_activation_creates_expected_custom_tables(): void {
		Activator::activate();

		$schema = implode( "\n\n", $GLOBALS['_dbdelta_queries'] );

		foreach ( $this->expected_tables() as $table ) {
			$this->assertStringContainsString( "CREATE TABLE wp_{$table}", $schema );
		}

		$this->assertCount( 9, $GLOBALS['_dbdelta_queries'] );
		$this->assertSame( WP_CSP_DB_VERSION, get_option( 'wp_csp_db_version' ) );
	}

	public function test_schema_v6_violation_rollup_columns_are_declared(): void {
		Activator::activate();

		$schema = implode( "\n\n", $GLOBALS['_dbdelta_queries'] );

		$this->assertStringContainsString( 'first_reported_at datetime DEFAULT NULL', $schema );
		$this->assertStringContainsString( 'last_reported_at datetime DEFAULT NULL', $schema );
		$this->assertStringContainsString( 'UNIQUE KEY fingerprint (fingerprint)', $schema );
	}

	public function test_policy_decision_ledger_columns_are_declared(): void {
		Activator::activate();

		$schema = implode( "\n\n", $GLOBALS['_dbdelta_queries'] );

		$this->assertStringContainsString( 'decision_fingerprint varchar(64) NOT NULL', $schema );
		$this->assertStringContainsString( 'suppression_active tinyint(1) NOT NULL DEFAULT 0', $schema );
		$this->assertStringContainsString( 'KEY suppression_active (suppression_active)', $schema );
	}

	/**
	 * @dataProvider legacy_schema_version_provider
	 */
	public function test_activation_advances_legacy_schema_versions_to_current( string $legacy_version ): void {
		update_option( 'wp_csp_db_version', $legacy_version );

		Activator::activate();

		$this->assertSame( WP_CSP_DB_VERSION, get_option( 'wp_csp_db_version' ) );
	}

	public function test_repeated_activation_remains_idempotent_for_schema_version(): void {
		Activator::activate();
		Activator::activate();

		$this->assertSame( WP_CSP_DB_VERSION, get_option( 'wp_csp_db_version' ) );
		$this->assertCount( 18, $GLOBALS['_dbdelta_queries'] );
	}

	public static function legacy_schema_version_provider(): array {
		return array(
			'v1' => array( '1' ),
			'v2' => array( '2' ),
			'v3' => array( '3' ),
			'v4' => array( '4' ),
			'v5' => array( '5' ),
		);
	}

	private function expected_tables(): array {
		return array(
			'csp_policy_profiles',
			'csp_source_inventory',
			'csp_hash_inventory',
			'csp_violation_reports',
			'csp_scan_logs',
			'csp_entitlements',
			'csp_processed_events',
			'csp_audit_log',
			'csp_policy_change_decisions',
		);
	}
}
