<?php
/**
 * Privileged CSP administration REST API.
 */

declare( strict_types=1 );

namespace WP_CSP\Rest;

use WP_CSP\CSP\Automation_Config;
use WP_CSP\CSP\Policy_Version_Manager;
use WP_CSP\Modules\Audit_Log;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin_Controller {

	private Audit_Log $audit;
	private Policy_Version_Manager $policy_versions;
	private Automation_Config $automation_config;

	public function __construct( Audit_Log $audit, ?Policy_Version_Manager $policy_versions = null, ?Automation_Config $automation_config = null ) {
		$this->audit             = $audit;
		$this->policy_versions   = $policy_versions ?? new Policy_Version_Manager();
		$this->automation_config = $automation_config ?? new Automation_Config();
	}

	public function register_routes(): void {
		register_rest_route(
			'csp-manager/v1',
			'/admin/policies',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_policies' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			'csp-manager/v1',
			'/admin/policies/(?P<surface>[a-z-]+)/history',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_policy_history' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			'csp-manager/v1',
			'/admin/policy-versions/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_policy_version' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			'csp-manager/v1',
			'/admin/policy-versions/(?P<id>\d+)/diff',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_policy_diff' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			'csp-manager/v1',
			'/admin/decisions',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'search_decisions' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			'csp-manager/v1',
			'/admin/decisions/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_decision' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			'csp-manager/v1',
			'/admin/reviews/pending',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_pending_reviews' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			'csp-manager/v1',
			'/admin/automation-config',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_automation_config' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_automation_config' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
			)
		);
	}

	public function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	public function list_policies(): \WP_REST_Response {
		global $wpdb;

		$profiles = $wpdb->get_results( "SELECT surface, mode FROM {$wpdb->prefix}csp_policy_profiles ORDER BY surface", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows     = array();
		foreach ( is_array( $profiles ) ? $profiles : array() as $profile ) {
			$surface = (string) $profile['surface'];
			$latest  = $this->policy_versions->latest_version( $surface );
			$rows[]  = array(
				'surface'                    => $surface,
				'mode'                       => (string) $profile['mode'],
				'policy_version'             => isset( $latest['version_number'] ) ? (int) $latest['version_number'] : 0,
				'last_material_change'       => (string) ( $latest['created_at'] ?? '' ),
				'automation_mode'            => $this->automation_config->for_surface( $surface )['mode'],
				'pending_decisions'          => $this->count_sources( $surface, "approval_state = 'pending'" ),
				'unresolved_high_risk_items' => $this->count_sources( $surface, "approval_state = 'pending' AND risk_level IN ('high','critical','unknown')" ),
				'effective_header'           => (string) ( $latest['effective_header'] ?? '' ),
			);
		}

		return new \WP_REST_Response( array( 'policies' => $rows ) );
	}

	public function list_policy_history( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;

		$surface = $this->normalise_surface( (string) $request['surface'] );
		if ( '' === $surface ) {
			return new \WP_REST_Response( array( 'code' => 'invalid_surface' ), 400 );
		}

		$table = $wpdb->prefix . 'csp_policy_versions';
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT id, surface, version_number, mode, effective_header, previous_version_id, trigger_type, trigger_id, software_version, created_at FROM {$table} WHERE surface = %s ORDER BY version_number DESC LIMIT 100",
				$surface
			),
			ARRAY_A
		);

		return new \WP_REST_Response( array( 'versions' => is_array( $rows ) ? $rows : array() ) );
	}

	public function get_policy_version( \WP_REST_Request $request ): \WP_REST_Response {
		$version = $this->policy_versions->get_version( (int) $request['id'] );
		if ( null === $version ) {
			return new \WP_REST_Response( array( 'code' => 'not_found' ), 404 );
		}

		return new \WP_REST_Response( array( 'version' => $version ) );
	}

	public function get_policy_diff( \WP_REST_Request $request ): \WP_REST_Response {
		$current = $this->policy_versions->get_version( (int) $request['id'] );
		if ( null === $current ) {
			return new \WP_REST_Response( array( 'code' => 'not_found' ), 404 );
		}

		$previous = ! empty( $current['previous_version_id'] ) ? $this->policy_versions->get_version( (int) $current['previous_version_id'] ) : null;
		return new \WP_REST_Response(
			array(
				'version' => $current,
				'diff'    => $this->policy_versions->diff_versions( $previous, $current ),
			)
		);
	}

	public function search_decisions( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;

		$table = $wpdb->prefix . 'csp_policy_change_decisions';
		$where = array( '1=1' );
		$args  = array();

		foreach ( array( 'surface', 'directive', 'state', 'actor_type', 'risk_level' ) as $field ) {
			$value = sanitize_text_field( (string) ( $request[ $field ] ?? '' ) );
			if ( '' !== $value ) {
				$where[] = "{$field} = %s";
				$args[]  = $value;
			}
		}

		$host = sanitize_text_field( (string) ( $request['source_host'] ?? '' ) );
		if ( '' !== $host ) {
			$where[] = 'source_host LIKE %s';
			$args[]  = '%' . $wpdb->esc_like( $host ) . '%';
		}

		$sql = "SELECT id, change_type, source_inventory_id, surface, directive, source_host, source_uri, decision_fingerprint, action, state, risk_level, risk_reason, reason, user_id, actor_type, actor_id, previous_policy_version_id, policy_version_id, decision_engine_version, reverted_decision_id, software_version, suppression_active, created_at FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY created_at DESC LIMIT 100';
		if ( ! empty( $args ) ) {
			$sql = $wpdb->prepare( $sql, ...$args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		return new \WP_REST_Response( array( 'decisions' => is_array( $rows ) ? $rows : array() ) );
	}

	public function get_decision( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;

		$table    = $wpdb->prefix . 'csp_policy_change_decisions';
		$decision = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE id = %d LIMIT 1",
				(int) $request['id']
			),
			ARRAY_A
		);

		if ( ! is_array( $decision ) ) {
			return new \WP_REST_Response( array( 'code' => 'not_found' ), 404 );
		}

		$rule_table = $wpdb->prefix . 'csp_decision_rule_evaluations';
		$rules      = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$rule_table} WHERE decision_id = %d ORDER BY id ASC",
				(int) $decision['id']
			),
			ARRAY_A
		);

		return new \WP_REST_Response(
			array(
				'decision'         => $decision,
				'rule_evaluations' => is_array( $rules ) ? $rules : array(),
			)
		);
	}

	public function list_pending_reviews(): \WP_REST_Response {
		global $wpdb;

		$table = $wpdb->prefix . 'csp_source_inventory';
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} WHERE approval_state = 'pending' ORDER BY FIELD(risk_level, 'critical', 'high', 'unknown', 'medium', 'low'), last_seen_at DESC LIMIT 100", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return new \WP_REST_Response( array( 'pending' => is_array( $rows ) ? $rows : array() ) );
	}

	public function get_automation_config(): \WP_REST_Response {
		return new \WP_REST_Response( array( 'automation_config' => $this->automation_config->all() ) );
	}

	public function update_automation_config( \WP_REST_Request $request ): \WP_REST_Response {
		$payload = json_decode( $request->get_body(), true );
		if ( ! is_array( $payload ) ) {
			return new \WP_REST_Response( array( 'code' => 'invalid_json' ), 400 );
		}

		$config = $this->automation_config->update_all( $payload['automation_config'] ?? $payload );
		$this->audit->log( 'automation_config', 'updated', 'Administrator updated CSP automation configuration.', 'info' );

		return new \WP_REST_Response( array( 'automation_config' => $config ) );
	}

	private function count_sources( string $surface, string $condition ): int {
		global $wpdb;

		$table = $wpdb->prefix . 'csp_source_inventory';
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$table} WHERE surface = %s AND {$condition}",
				$surface
			)
		);
	}

	private function normalise_surface( string $surface ): string {
		$surface = strtolower( trim( sanitize_text_field( $surface ) ) );
		return in_array( $surface, Automation_Config::SURFACES, true ) ? $surface : '';
	}
}
