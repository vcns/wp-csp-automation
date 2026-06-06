<?php
/**
 * Thin static bridge so Policy_Builder can read the nonce without
 * requiring a direct reference to the Nonce_Manager singleton.
 */

declare( strict_types=1 );

namespace WP_CSP\CSP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin_Nonce_Manager {
	public static function get_instance_nonce(): string {
		static $nonce = null;
		if ( null === $nonce ) {
			$plugin = \WP_CSP\Plugin::instance();
			$nonce  = isset( $plugin->nonce_manager ) ? $plugin->nonce_manager->get_nonce() : '';
		}
		return $nonce;
	}
}
