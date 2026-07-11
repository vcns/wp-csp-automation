<?php
/**
 * Unit tests for WP_CSP\CSP\Automation_Config.
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use WP_CSP\CSP\Automation_Config;

class AutomationConfigTest extends TestCase {

	protected function setUp(): void {
		wp_test_reset_globals();
	}

	public function test_defaults_are_manual_and_emergency_disabled(): void {
		$config = ( new Automation_Config() )->all();

		foreach ( Automation_Config::SURFACES as $surface ) {
			$this->assertSame( 'manual', $config[ $surface ]['mode'] );
			$this->assertTrue( $config[ $surface ]['emergency_disabled'] );
			$this->assertSame( 0, $config[ $surface ]['max_automatic_changes_per_scan'] );
		}
	}

	public function test_invalid_mode_normalises_to_manual(): void {
		update_option(
			'wp_csp_automation_config',
			array(
				'frontend' => array(
					'mode'                   => 'reckless',
					'allowed_source_schemes' => array( 'HTTPS', 'javascript:' ),
				),
			)
		);

		$config = ( new Automation_Config() )->for_surface( 'frontend' );

		$this->assertSame( 'manual', $config['mode'] );
		$this->assertSame( array( 'https', 'javascript:' ), $config['allowed_source_schemes'] );
	}
}
