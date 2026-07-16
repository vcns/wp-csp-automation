<?php
/**
 * Unit tests for WP_CSP\Activator.
 *
 * Focuses on the parts that can run without a real database:
 *   - Default options are seeded with correct keys and values.
 *   - Default directives structure is valid and surface-specific.
 *   - Cron event is scheduled once (idempotent on repeat activate).
 *
 * create_tables() / seed_default_profiles() are skipped here because they
 * require dbDelta() and a real wpdb. Integration tests should cover those.
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use WP_CSP\Activator;

class ActivatorTest extends TestCase {

	protected function setUp(): void {
		wp_test_reset_globals();
	}

	// ── Default options ───────────────────────────────────────────────────────

	public function test_activate_seeds_config_dns_domain_option(): void {
		Activator::activate();

		$this->assertSame( '', get_option( 'wp_csp_config_dns_domain' ) );
	}

	public function test_activate_seeds_config_cache_ttl_option(): void {
		Activator::activate();

		$this->assertSame( 3600, get_option( 'wp_csp_config_cache_ttl' ) );
	}

	public function test_activate_seeds_violation_retention_days_option(): void {
		Activator::activate();

		$this->assertSame( 90, get_option( 'wp_csp_violation_retention_days' ) );
	}

	public function test_activate_seeds_learning_window_option(): void {
		Activator::activate();

		$this->assertSame( 48, get_option( 'wp_csp_learning_window_hours' ) );
		$this->assertNotEmpty( get_option( 'wp_csp_last_material_change_at' ) );
	}

	public function test_activate_seeds_enforce_gate_violation_window_option(): void {
		Activator::activate();

		$this->assertSame( 24, get_option( 'wp_csp_enforce_gate_violation_window' ) );
	}

	public function test_activate_seeds_cron_hour_default_of_two(): void {
		Activator::activate();

		$this->assertSame( 2, get_option( 'wp_csp_cron_hour' ) );
	}

	public function test_activate_seeds_entitlement_grace_hours(): void {
		Activator::activate();

		$this->assertSame( 72, get_option( 'wp_csp_entitlement_grace_hours' ) );
	}

	public function test_activate_does_not_overwrite_existing_options(): void {
		// Pre-seed a custom value.
		update_option( 'wp_csp_cron_hour', 6 );

		Activator::activate();

		// add_option() is a no-op when the option already exists.
		$this->assertSame( 6, get_option( 'wp_csp_cron_hour' ) );
	}

	// ── Cron scheduling ───────────────────────────────────────────────────────

	public function test_activate_schedules_daily_scan_cron_event(): void {
		Activator::activate();

		$this->assertNotFalse( wp_next_scheduled( 'wp_csp_daily_scan' ) );
	}

	public function test_activate_does_not_double_schedule_cron_event(): void {
		// First activation schedules the event.
		Activator::activate();
		$first_timestamp = wp_next_scheduled( 'wp_csp_daily_scan' );

		// Second activation must be a no-op (cron event already exists).
		Activator::activate();
		$second_timestamp = wp_next_scheduled( 'wp_csp_daily_scan' );

		$this->assertSame( $first_timestamp, $second_timestamp );
	}

	// ── Default directives ────────────────────────────────────────────────────

	/**
	 * @dataProvider surface_provider
	 */
	public function test_default_directives_include_default_src_none( string $surface ): void {
		$directives = $this->get_default_directives( $surface );

		$this->assertArrayHasKey( 'default-src', $directives );
		$this->assertContains( "'none'", $directives['default-src'] );
	}

	/**
	 * @dataProvider surface_provider
	 */
	public function test_default_directives_include_object_src_none( string $surface ): void {
		$directives = $this->get_default_directives( $surface );

		$this->assertArrayHasKey( 'object-src', $directives );
		$this->assertContains( "'none'", $directives['object-src'] );
	}

	/**
	 * @dataProvider surface_provider
	 */
	public function test_default_directives_include_base_uri_none( string $surface ): void {
		$directives = $this->get_default_directives( $surface );

		$this->assertArrayHasKey( 'base-uri', $directives );
		$this->assertContains( "'none'", $directives['base-uri'] );
	}

	public function test_default_directives_include_upgrade_insecure_on_frontend(): void {
		$directives = $this->get_default_directives( 'frontend' );

		$this->assertArrayHasKey( 'upgrade-insecure-requests', $directives );
	}

	public function test_default_directives_omit_upgrade_insecure_on_api(): void {
		$directives = $this->get_default_directives( 'api' );

		$this->assertArrayNotHasKey( 'upgrade-insecure-requests', $directives );
	}

	public function test_default_directives_include_report_sample_in_script_src(): void {
		$directives = $this->get_default_directives( 'frontend' );

		$this->assertArrayHasKey( 'script-src', $directives );
		$this->assertContains( "'report-sample'", $directives['script-src'] );
	}

	public function test_default_directives_admin_surface_allows_self_for_frames(): void {
		$directives = $this->get_default_directives( 'admin' );

		$this->assertArrayHasKey( 'frame-src', $directives );
		$this->assertContains( "'self'", $directives['frame-src'] );
	}

	public function test_default_directives_sandbox_is_null(): void {
		$directives = $this->get_default_directives( 'frontend' );

		$this->assertArrayHasKey( 'sandbox', $directives );
		$this->assertNull( $directives['sandbox'] );
	}

	// ── Providers ─────────────────────────────────────────────────────────────

	public static function surface_provider(): array {
		return array(
			'frontend' => array( 'frontend' ),
			'admin'    => array( 'admin' ),
			'login'    => array( 'login' ),
			'api'      => array( 'api' ),
		);
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Invokes the private Activator::default_directives() via reflection.
	 */
	private function get_default_directives( string $surface ): array {
		$method = new ReflectionMethod( Activator::class, 'default_directives' );
		$method->setAccessible( true );
		return $method->invoke( null, $surface );
	}
}
