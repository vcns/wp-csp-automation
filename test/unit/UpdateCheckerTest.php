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
		$GLOBALS['_wp_remote_get_response'] = $this->response( $this->manifest( '1.0.4' ) );

		$checker   = new Update_Checker( 'https://updates.example.com/wp-csp-automation.json' );
		$transient = (object) array(
			'checked'   => array(),
			'response'  => array(),
			'no_update' => array(),
		);

		$result = $checker->filter_update_plugins( $transient );

		$item = $result->response['wp-csp-automation/wp-csp-automation.php'] ?? null;
		$this->assertIsObject( $item );
		$this->assertSame( '1.0.4', $item->new_version );
		$this->assertSame( 'https://github.com/vcns/wp-csp-automation/releases/download/v1.0.4/wp-csp-automation-v1.0.4.zip', $item->package );
		$this->assertArrayNotHasKey( 'wp-csp-automation/wp-csp-automation.php', $result->no_update );
	}

	public function test_register_wires_native_update_hooks(): void {
		$checker = new Update_Checker( 'https://updates.example.com/wp-csp-automation.json' );

		$checker->register();

		$this->assertArrayHasKey( 'pre_set_site_transient_update_plugins', $GLOBALS['_wp_actions'] );
		$this->assertArrayHasKey( 'plugins_api', $GLOBALS['_wp_actions'] );
		$this->assertArrayHasKey( 'upgrader_process_complete', $GLOBALS['_wp_actions'] );
		$this->assertArrayHasKey( 'auto_update_plugin', $GLOBALS['_wp_actions'] );
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
		$this->assertSame( 'CSP Automation Manager', $result->name );
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

	public function test_failed_manifest_fetch_is_cached_between_calls(): void {
		$GLOBALS['_wp_remote_get_response'] = array(
			'response' => array( 'code' => 500 ),
			'body'     => '',
		);

		$checker = new Update_Checker( 'https://updates.example.com/wp-csp-automation.json' );

		$checker->get_manifest();
		$checker->get_manifest();

		$this->assertCount( 1, $GLOBALS['_wp_remote_get_requests'] );
	}

	public function test_update_completion_clears_cached_manifest(): void {
		$GLOBALS['_wp_remote_get_response'] = $this->response( $this->manifest( '0.3.0' ) );

		$checker = new Update_Checker( 'https://updates.example.com/wp-csp-automation.json' );
		$checker->get_manifest();

		$this->assertNotFalse( get_transient( 'wp_csp_update_manifest' ) );

		$checker->clear_cached_manifest(
			null,
			array(
				'action'  => 'update',
				'type'    => 'plugin',
				'plugins' => array( 'wp-csp-automation/wp-csp-automation.php' ),
			)
		);

		$this->assertFalse( get_transient( 'wp_csp_update_manifest' ) );
	}

	public function test_update_completion_clears_cache_for_single_plugin_key(): void {
		$GLOBALS['_wp_remote_get_response'] = $this->response( $this->manifest( '0.3.0' ) );

		$checker = new Update_Checker( 'https://updates.example.com/wp-csp-automation.json' );
		$checker->get_manifest();

		$this->assertNotFalse( get_transient( 'wp_csp_update_manifest' ) );

		// WordPress uses `plugin` (singular) for single-plugin upgrades.
		$checker->clear_cached_manifest(
			null,
			array(
				'action' => 'update',
				'type'   => 'plugin',
				'plugin' => 'wp-csp-automation/wp-csp-automation.php',
			)
		);

		$this->assertFalse( get_transient( 'wp_csp_update_manifest' ) );
	}

	public function test_update_completion_does_not_clear_cache_for_other_single_plugin(): void {
		$GLOBALS['_wp_remote_get_response'] = $this->response( $this->manifest( '0.3.0' ) );

		$checker = new Update_Checker( 'https://updates.example.com/wp-csp-automation.json' );
		$checker->get_manifest();

		$cached = get_transient( 'wp_csp_update_manifest' );
		$this->assertNotFalse( $cached );

		// A different plugin is updated; the cache must be kept.
		$checker->clear_cached_manifest(
			null,
			array(
				'action' => 'update',
				'type'   => 'plugin',
				'plugin' => 'some-other-plugin/some-other-plugin.php',
			)
		);

		$this->assertNotFalse( get_transient( 'wp_csp_update_manifest' ) );
	}

	public function test_filter_auto_update_passes_through_when_constant_undefined(): void {
		// WP_CSP_DISABLE_AUTO_UPDATE is not defined in the test bootstrap, so
		// the filter must return the original $update value unchanged.
		$checker = new Update_Checker( 'https://updates.example.com/wp-csp-automation.json' );
		$item    = (object) array(
			'plugin' => 'wp-csp-automation/wp-csp-automation.php',
			'slug'   => 'wp-csp-automation',
		);

		$this->assertTrue( $checker->filter_auto_update_plugin( true, $item ) );
		$this->assertFalse( $checker->filter_auto_update_plugin( false, $item ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_filter_auto_update_disables_by_plugin_file(): void {
		define( 'WP_CSP_DISABLE_AUTO_UPDATE', true );

		$checker = new Update_Checker( 'https://updates.example.com/wp-csp-automation.json' );
		$item    = (object) array( 'plugin' => 'wp-csp-automation/wp-csp-automation.php', 'slug' => 'other-slug' );

		$this->assertFalse( $checker->filter_auto_update_plugin( true, $item ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_filter_auto_update_disables_by_slug(): void {
		define( 'WP_CSP_DISABLE_AUTO_UPDATE', true );

		$checker = new Update_Checker( 'https://updates.example.com/wp-csp-automation.json' );
		$item    = (object) array( 'plugin' => 'other/other.php', 'slug' => 'wp-csp-automation' );

		$this->assertFalse( $checker->filter_auto_update_plugin( true, $item ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_filter_auto_update_passes_through_for_unrelated_plugin(): void {
		define( 'WP_CSP_DISABLE_AUTO_UPDATE', true );

		$checker = new Update_Checker( 'https://updates.example.com/wp-csp-automation.json' );
		$item    = (object) array( 'plugin' => 'some-other/some-other.php', 'slug' => 'some-other' );

		$this->assertTrue( $checker->filter_auto_update_plugin( true, $item ) );
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
			'name'          => 'CSP Automation Manager',
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
