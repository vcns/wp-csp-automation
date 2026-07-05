<?php
/**
 * Unit tests for WP_CSP\CSP\Learning_Window.
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use WP_CSP\CSP\Learning_Window;

class LearningWindowTest extends TestCase {

	protected function setUp(): void {
		wp_test_reset_globals();
	}

	public function test_missing_material_change_clock_is_initialised_once(): void {
		$window = new Learning_Window();

		$last_change = $window->last_material_change_at();

		$this->assertNotEmpty( $last_change );
		$this->assertSame( $last_change, get_option( Learning_Window::OPTION_LAST_CHANGE ) );
	}

	public function test_window_locks_after_configured_hours(): void {
		update_option( Learning_Window::OPTION_LAST_CHANGE, gmdate( 'Y-m-d H:i:s', time() - ( 49 * HOUR_IN_SECONDS ) ) );
		update_option( Learning_Window::OPTION_WINDOW_HOURS, 48 );

		$window = new Learning_Window();

		$this->assertFalse( $window->is_open() );
	}
}
