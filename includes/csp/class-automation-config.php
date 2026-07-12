<?php
/**
 * Option-backed CSP automation configuration.
 */

declare( strict_types=1 );

namespace WP_CSP\CSP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Automation_Config {

	public const MODES    = array( 'manual', 'conservative', 'balanced', 'expert' );
	public const SURFACES = array( 'frontend', 'admin', 'login', 'api' );

	public const DEFAULT_SURFACE_CONFIG = array(
		'mode'                           => 'manual',
		'enabled_directives'             => array(),
		'excluded_directives'            => array(),
		'allowed_source_schemes'         => array( 'https' ),
		'treat_same_origin_as_low'       => true,
		'treat_known_cdn_as_low'         => false,
		'allow_wildcards'                => false,
		'allow_cleartext_http'           => false,
		'allow_browser_schemes'          => false,
		'allow_ip_literals'              => false,
		'allow_non_standard_ports'       => false,
		'approval_confidence_threshold'  => 1.0,
		'require_ai_agreement'           => false,
		'automatic_rejection_enabled'    => false,
		'max_automatic_changes_per_scan' => 0,
		'change_rate_guardrail'          => 0,
		'emergency_disabled'             => true,
	);

	public function all(): array {
		$config = get_option( 'wp_csp_automation_config', array() );
		return $this->normalise_all( is_array( $config ) ? $config : array() );
	}

	public function for_surface( string $surface ): array {
		$config = $this->all();
		return $config[ $surface ] ?? self::DEFAULT_SURFACE_CONFIG;
	}

	public function update_all( array $config ): array {
		$normalised = $this->normalise_all( $config );
		update_option( 'wp_csp_automation_config', $normalised );
		return $normalised;
	}

	private function normalise_all( array $config ): array {
		$normalised = array();
		foreach ( self::SURFACES as $surface ) {
			$normalised[ $surface ] = $this->normalise_surface( $config[ $surface ] ?? array() );
		}
		return $normalised;
	}

	private function normalise_surface( array $config ): array {
		$merged = array_merge( self::DEFAULT_SURFACE_CONFIG, $config );
		if ( ! in_array( $merged['mode'], self::MODES, true ) ) {
			$merged['mode'] = 'manual';
		}

		foreach ( array( 'enabled_directives', 'excluded_directives', 'allowed_source_schemes' ) as $key ) {
			$values         = is_array( $merged[ $key ] ) ? $merged[ $key ] : array();
			$merged[ $key ] = array_values(
				array_unique(
					array_filter(
						array_map(
							static fn( mixed $value ): string => strtolower( trim( sanitize_text_field( (string) $value ) ) ),
							$values
						)
					)
				)
			);
		}

		foreach ( array( 'treat_same_origin_as_low', 'treat_known_cdn_as_low', 'allow_wildcards', 'allow_cleartext_http', 'allow_browser_schemes', 'allow_ip_literals', 'allow_non_standard_ports', 'require_ai_agreement', 'automatic_rejection_enabled', 'emergency_disabled' ) as $key ) {
			$merged[ $key ] = (bool) $merged[ $key ];
		}

		$merged['approval_confidence_threshold']  = max( 0.0, min( 1.0, (float) $merged['approval_confidence_threshold'] ) );
		$merged['max_automatic_changes_per_scan'] = max( 0, (int) $merged['max_automatic_changes_per_scan'] );
		$merged['change_rate_guardrail']          = max( 0, (int) $merged['change_rate_guardrail'] );

		return $merged;
	}
}
