<?php
/**
 * Test stub for WP_CSP\CSP\Plugin_Nonce_Manager.
 *
 * Must be loaded by bootstrap.php BEFORE any plugin file that defines the real
 * Plugin_Nonce_Manager (i.e. class-policy-builder.php). Because this file is
 * in the WP_CSP\CSP namespace it must have no code outside the namespace block.
 *
 * The stub reads from a test global so PolicyBuilderTest can inject a nonce
 * without needing Plugin::instance() or a real WordPress request.
 */

declare( strict_types=1 );

namespace WP_CSP\CSP;

if ( ! class_exists( Plugin_Nonce_Manager::class ) ) {
	final class Plugin_Nonce_Manager {
		public static function get_instance_nonce(): string {
			return $GLOBALS['_wp_csp_test_nonce'] ?? '';
		}
	}
}