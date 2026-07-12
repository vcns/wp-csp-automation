<?php
/**
 * Unit tests for WP_CSP\CSP\Policy_Version_Manager.
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use WP_CSP\CSP\Policy_Version_Manager;

class PolicyVersionManagerTest extends TestCase {

	public function test_diff_versions_identifies_added_and_removed_values(): void {
		$manager = new Policy_Version_Manager();
		$previous = array(
			'mode'            => 'report-only',
			'policy_snapshot' => wp_json_encode(
				array(
					'directives' => array(
						'img-src' => array( "'self'" ),
					),
				)
			),
		);
		$current = array(
			'mode'            => 'enforce',
			'policy_snapshot' => wp_json_encode(
				array(
					'directives' => array(
						'img-src'     => array( "'self'", 'cdn.example.test' ),
						'connect-src' => array( "'self'" ),
					),
				)
			),
		);

		$diff = $manager->diff_versions( $previous, $current );

		$this->assertSame( array( 'connect-src' ), $diff['added_directives'] );
		$this->assertContains( array( 'directive' => 'img-src', 'value' => 'cdn.example.test' ), $diff['added_values'] );
		$this->assertTrue( $diff['mode_changed'] );
	}
}
