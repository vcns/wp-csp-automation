<?php
/**
 * Unit tests for WP_CSP\Modules\Update_Checker.
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use WP_CSP\Modules\Update_Checker;

class UpdateCheckerTest extends TestCase {

	protected function setUp(): void {
		wp_test_reset_globals();
	}

	public function test_new_manifest_version_populates_update_response(): void {
		$GLOBALS['_wp_remote_get_response'] = $this->response( $this->manifest( '0.4.0' ) );

		$checker   = new Update_Checker( 'https://updates.example.com/wp-csp-automation.json' );
		$transient = (object) array(
			'checked'   => array(),
			'response'  => array(),
			'no_update' => array(),
		);

		$result = $checker->filter_update_plugins( $transient );

		$item = $result->response['wp-csp-automation/wp-csp-automation.php'] ?? null;
		$this->assertIsObject( $item );
		$this->assertSame( '0.4.0', $item->new_version );
		$this->assertSame( 'https://github.com/vcns/wp-csp-automation/releases/download/v0.4.0/wp-csp-automation-v0.4.0.zip', $item->package );
		$this->assertArrayNotHasKey( 'wp-csp-automation/wp-csp-automation.php', $result->no_update );
	}

	public function test_current_manifest_version_populates_no_update_without_package(): void {
		$GLOBALS['_wp_remote_get_response'] = $this->response( $this->manifest( WP_CSP_VERSION ) );

		$checker   = new Update_Checker( 'https://updates.example.com/wp-csp-automation.json' );
		$transient = (object) array(
			'checked'   => array(),
			'response'  => array(),
			'no_update' => array(),
		);

		$result = $checker->filter_update_plugins( $transient );

		$item = $result->no_update['wp-csp-automation/wp-csp-automation.php'] ?? null;
		$this->assertIsObject( $item );
		$this->assertSame( WP_CSP_VERSION, $item->new_version );
		$this->assertSame( '', $item->package );
		$this->assertArrayNotHasKey( 'wp-csp-automation/wp-csp-automation.php', $result->response );
	}

	public function test_invalid_manifest_does_not_offer_update(): void {
		$manifest                 = $this->manifest( '0.3.0' );
		$manifest['download_url'] = 'http://insecure.example.com/plugin.zip';

		$GLOBALS['_wp_remote_get_response'] = $this->response( $manifest );

		$checker   = new Update_Checker( 'https://updates.example.com/wp-csp-automation.json' );
		$transient = (object) array(
			'checked'   => array(),
			'response'  => array(),
			'no_update' => array(),
		);

		$result = $checker->filter_update_plugins( $transient );

		$this->assertSame( array(), $result->response );
		$this->assertSame( array(), $result->no_update );
	}

	public function test_plugin_information_modal_uses_manifest_sections(): void {
		$GLOBALS['_wp_remote_get_response'] = $this->response( $this->manifest( '0.3.0' ) );

		$checker = new Update_Checker( 'https://updates.example.com/wp-csp-automation.json' );
		$args    = (object) array( 'slug' => 'wp-csp-automation' );

		$result = $checker->filter_plugins_api( false, 'plugin_information', $args );

		$this->assertIsObject( $result );
		$this->assertSame( 'WP CSP Automation Manager', $result->name );
		$this->assertSame( '0.3.0', $result->version );
		$this->assertSame( 'Release notes', $result->sections['changelog'] );
	}

	public function test_manifest_is_cached_between_calls(): void {
		$GLOBALS['_wp_remote_get_response'] = $this->response( $this->manifest( '0.3.0' ) );

		$checker = new Update_Checker( 'https://updates.example.com/wp-csp-automation.json' );

		$checker->get_manifest();
		$checker->get_manifest();

		$this->assertCount( 1, $GLOBALS['_wp_remote_get_requests'] );
	}

	private function response( array $manifest ): array {
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) json_encode( $manifest ),
		);
	}

	private function manifest( string $version ): array {
		return array(
			'slug'          => 'wp-csp-automation',
			'plugin'        => 'wp-csp-automation/wp-csp-automation.php',
			'name'          => 'WP CSP Automation Manager',
			'version'       => $version,
			'download_url'  => 'https://github.com/vcns/wp-csp-automation/releases/download/v' . $version . '/wp-csp-automation-v' . $version . '.zip',
			'homepage'      => 'https://github.com/vcns/wp-csp-automation',
			'requires'      => '6.4',
			'tested'        => '6.8',
			'requires_php'  => '8.1',
			'last_updated'  => '2026-07-05T00:00:00+00:00',
			'author'        => 'Simon Jackson',
			'sections'      => array(
				'description' => 'Description',
				'changelog'   => 'Release notes',
			),
		);
	}
}
