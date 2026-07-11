<?php
/**
 * Unit tests for WP_CSP\CSP\Decision_Engine.
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use WP_CSP\CSP\Decision_Engine;

class DecisionEngineTest extends TestCase {

	public function test_manual_mode_requires_human_review_for_low_risk_source(): void {
		$engine = new Decision_Engine();

		$result = $engine->evaluate_source(
			array(
				'directive'      => 'prefetch-src',
				'source_scheme'  => 'https',
				'source_host'    => 'cdn.example.test',
				'source_uri'     => 'https://cdn.example.test/app.js',
				'evidence_count' => 1,
			),
			array( 'mode' => 'manual' )
		);

		$this->assertSame( Decision_Engine::ENGINE_VERSION, $result['engine_version'] );
		$this->assertSame( 'low', $result['risk'] );
		$this->assertFalse( $result['automation_eligible'] );
		$this->assertTrue( $result['required_human_review'] );
	}

	public function test_wildcard_is_hard_excluded_from_automation(): void {
		$engine = new Decision_Engine();

		$result = $engine->evaluate_source(
			array(
				'directive'      => 'img-src',
				'source_scheme'  => 'https',
				'source_host'    => '*.example.test',
				'source_uri'     => 'https://*.example.test/logo.png',
				'evidence_count' => 3,
			),
			array( 'mode' => 'conservative' )
		);

		$this->assertSame( 'high', $result['risk'] );
		$this->assertContains( 'wildcard_source', $result['hard_exclusions'] );
		$this->assertFalse( $result['automation_eligible'] );
	}

	public function test_low_risk_source_can_be_eligible_outside_manual_mode(): void {
		$engine = new Decision_Engine();

		$result = $engine->evaluate_source(
			array(
				'directive'      => 'prefetch-src',
				'source_scheme'  => 'https',
				'source_host'    => 'assets.example.test',
				'source_uri'     => 'https://assets.example.test/prefetch.json',
				'evidence_count' => 2,
			),
			array( 'mode' => 'conservative' )
		);

		$this->assertSame( 'low', $result['risk'] );
		$this->assertTrue( $result['automation_eligible'] );
	}
}
