<?php
/**
 * Test stub for WP_CSP\CSP\Plugin_Nonce_Manager.
 *
 * bootstrap.php requires this file at the end of its setup block, after
 * spl_autoload_register(). The class_exists() guard below (with the second
 * argument false to suppress autoloading) is the actual runtime protection —
 * it prevents the stub from being defined if the real class was already loaded
 * by the autoloader. Any code added to bootstrap.php that references
 * Plugin_Nonce_Manager before this require_once will load the real class and
 * silently bypass this stub.
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