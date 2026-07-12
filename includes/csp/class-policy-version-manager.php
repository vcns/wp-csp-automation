<?php
/**
 * Captures and compares immutable CSP policy snapshots.
 */

declare( strict_types=1 );

namespace WP_CSP\CSP;

use WP_CSP\Modules\Feature_Gate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Policy_Version_Manager {

	private Policy_Builder $builder;

	public function __construct( ?Policy_Builder $builder = null ) {
		$this->builder = $builder ?? new Policy_Builder( new Feature_Gate() );
	}

	public function capture_snapshot( string $surface, string $trigger_type, int $trigger_id = 0 ): int {
		global $wpdb;

		$surface = $this->normalise_surface( $surface );
		if ( '' === $surface ) {
			return 0;
		}

		$profile = $this->load_profile( $surface );
		if ( empty( $profile ) || ! isset( $profile['directives'], $profile['overrides'], $profile['mode'] ) ) {
			return 0;
		}

		$previous = $this->latest_version( $surface );
		$version  = (int) ( $previous['version_number'] ?? 0 ) + 1;
		$snapshot = $this->build_snapshot( $surface, $profile );

		$inserted = $wpdb->insert(
			$wpdb->prefix . 'csp_policy_versions',
			array(
				'surface'             => $surface,
				'version_number'      => $version,
				'mode'                => (string) $profile['mode'],
				'effective_header'    => $snapshot['effective_header'],
				'policy_snapshot'     => wp_json_encode( $snapshot ),
				'previous_version_id' => isset( $previous['id'] ) ? (int) $previous['id'] : null,
				'trigger_type'        => $this->normalise_token( $trigger_type, 32 ),
				'trigger_id'          => $trigger_id > 0 ? $trigger_id : null,
				'software_version'    => defined( 'WP_CSP_VERSION' ) ? WP_CSP_VERSION : '',
				'created_at'          => current_time( 'mysql', true ),
			),
			array( '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s' )
		);

		return false === $inserted ? 0 : (int) $wpdb->insert_id;
	}

	public function latest_version( string $surface ): ?array {
		global $wpdb;

		$table = $wpdb->prefix . 'csp_policy_versions';
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE surface = %s ORDER BY version_number DESC LIMIT 1",
				$this->normalise_surface( $surface )
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function get_version( int $version_id ): ?array {
		global $wpdb;

		$table = $wpdb->prefix . 'csp_policy_versions';
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE id = %d LIMIT 1",
				$version_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function diff_versions( ?array $previous, array $current ): array {
		$prev_snapshot = $this->decode_snapshot( $previous['policy_snapshot'] ?? '' );
		$next_snapshot = $this->decode_snapshot( $current['policy_snapshot'] ?? '' );
		$prev_dirs     = $prev_snapshot['directives'] ?? array();
		$next_dirs     = $next_snapshot['directives'] ?? array();
		$diff          = array(
			'added_directives'   => array_values( array_diff( array_keys( $next_dirs ), array_keys( $prev_dirs ) ) ),
			'removed_directives' => array_values( array_diff( array_keys( $prev_dirs ), array_keys( $next_dirs ) ) ),
			'added_values'       => array(),
			'removed_values'     => array(),
			'mode_changed'       => ( $previous['mode'] ?? null ) !== ( $current['mode'] ?? null ),
		);

		foreach ( array_unique( array_merge( array_keys( $prev_dirs ), array_keys( $next_dirs ) ) ) as $directive ) {
			$prev_values = is_array( $prev_dirs[ $directive ] ?? null ) ? $prev_dirs[ $directive ] : array();
			$next_values = is_array( $next_dirs[ $directive ] ?? null ) ? $next_dirs[ $directive ] : array();
			foreach ( array_diff( $next_values, $prev_values ) as $value ) {
				$diff['added_values'][] = array(
					'directive' => $directive,
					'value'     => $value,
				);
			}
			foreach ( array_diff( $prev_values, $next_values ) as $value ) {
				$diff['removed_values'][] = array(
					'directive' => $directive,
					'value'     => $value,
				);
			}
		}

		return $diff;
	}

	private function build_snapshot( string $surface, array $profile ): array {
		$directives = json_decode( (string) $profile['directives'], true );
		$overrides  = json_decode( (string) $profile['overrides'], true );
		$sources    = $this->load_approved_sources( $surface );
		$hashes     = $this->load_active_hashes( $surface );

		$policy_directives = is_array( $directives ) ? $directives : array();
		if ( is_array( $overrides ) ) {
			foreach ( $overrides as $directive => $values ) {
				$policy_directives[ $directive ] = $values;
			}
		}
		foreach ( $sources as $source ) {
			$directive = (string) $source['directive'];
			if ( isset( $policy_directives[ $directive ] ) && is_array( $policy_directives[ $directive ] ) ) {
				$policy_directives[ $directive ][] = (string) $source['source_host'];
			}
		}
		foreach ( $hashes as $hash ) {
			$directive = (string) $hash['directive'];
			if ( isset( $policy_directives[ $directive ] ) && is_array( $policy_directives[ $directive ] ) ) {
				$policy_directives[ $directive ][] = "'" . $hash['hash_algo'] . '-' . $hash['hash_value'] . "'";
			}
		}

		foreach ( $policy_directives as $directive => $values ) {
			if ( is_array( $values ) ) {
				$policy_directives[ $directive ] = array_values( array_unique( array_filter( $values ) ) );
			}
		}

		return array(
			'surface'          => $surface,
			'mode'             => (string) $profile['mode'],
			'directives'       => $policy_directives,
			'approved_sources' => $sources,
			'active_hashes'    => $hashes,
			'effective_header' => $this->builder->build_policy_string( $profile, $surface ),
		);
	}

	private function load_profile( string $surface ): ?array {
		global $wpdb;

		$table = $wpdb->prefix . 'csp_policy_profiles';
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE surface = %s LIMIT 1",
				$surface
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	private function load_approved_sources( string $surface ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'csp_source_inventory';
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT id, directive, source_host, source_uri, first_seen_at, last_seen_at, risk_level FROM {$table} WHERE surface = %s AND approval_state = 'approved' ORDER BY directive, source_host",
				$surface
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	private function load_active_hashes( string $surface ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'csp_hash_inventory';
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT id, directive, hash_algo, hash_value, first_seen_at, last_seen_at FROM {$table} WHERE surface = %s AND status = 'active' ORDER BY directive, hash_algo, hash_value",
				$surface
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	private function decode_snapshot( string $json ): array {
		$decoded = json_decode( $json, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	private function normalise_surface( string $surface ): string {
		$surface = strtolower( trim( sanitize_text_field( $surface ) ) );
		return in_array( $surface, Automation_Config::SURFACES, true ) ? $surface : '';
	}

	private function normalise_token( string $token, int $length ): string {
		return substr( strtolower( trim( sanitize_text_field( $token ) ) ), 0, $length );
	}
}
