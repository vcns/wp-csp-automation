<?php
/**
 * Test stub for WP_CSP\CSP\Plugin_Nonce_Manager.
 *
 * bootstrap.php requires this file BEFORE registering the PSR-4 autoloader so
 * this stub definition wins the race against the real class. The class_exists()
 * guard below is belt-and-braces — the pre-autoloader require is the primary
 * protection; the guard covers any future load-order regression.
 *
 * Because this stub is in the WP_CSP\CSP namespace it must have no code
 * outside the namespace block.
 *
 * The stub reads from a test global so PolicyBuilderTest can inject a nonce
 * without needing Plugin::instance() or a real WordPress request.
 */

declare( strict_types=1 );

namespace WP_CSP\CSP;

if ( ! class_exists( Plugin_Nonce_Manager::class, false ) ) {
	final class Plugin_Nonce_Manager {
		public static function get_instance_nonce(): string {
			return $GLOBALS['_wp_csp_test_nonce'] ?? '';
		}
	}
}