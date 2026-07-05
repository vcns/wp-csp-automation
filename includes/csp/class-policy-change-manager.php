<?php
/**
 * CSP source proposal, risk, decision, and suppression workflow.
 */

declare( strict_types=1 );

namespace WP_CSP\CSP;

use WP_CSP\Modules\Audit_Log;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Policy_Change_Manager {

	private const HIGH_RISK_DIRECTIVES = array(
		'script-src',
		'script-src-elem',
		'script-src-attr',
		'style-src',
		'style-src-elem',
		'style-src-attr',
		'connect-src',
		'form-action',
		'frame-src',
		'worker-src',
	);

	private const MEDIUM_RISK_DIRECTIVES = array(
		'font-src',
		'img-src',
		'media-src',
		'manifest-src',
		'child-src',
	);

	private Audit_Log $audit;

	public function __construct( Audit_Log $audit ) {
		$this->audit = $audit;
	}

	/**
	 * Adds or refreshes a pending source proposal unless a prior admin decision suppresses it.
	 *
	 * @param array{directive:string,uri:string,scheme:string,host:string} $candidate Candidate source data.
	 * @return array{status:string,id:int,risk_level:string,risk_reason:string}
	 */
	public function propose_source(
		string $surface,
		array $candidate,
		string $owner_component,
		string $owner_type,
		string $notes = ''
	): array {
		global $wpdb;

		$surface   = $this->normalise_token( $surface, 32 );
		$directive = $this->normalise_directive( $candidate['directive'] ?? '' );
		$host      = $this->normalise_host( $candidate['host'] ?? '' );
		$scheme    = $this->normalise_token( $candidate['scheme'] ?? 'https', 16 );
		$uri       = esc_url_raw( (string) ( $candidate['uri'] ?? '' ) );

		if ( '' === $surface || '' === $directive || '' === $host ) {
			return array(
				'status'      => 'invalid',
				'id'          => 0,
				'risk_level'  => 'high',
				'risk_reason' => 'Missing surface, directive, or host.',
			);
		}

		$risk        = $this->classify_source_risk( $directive, $scheme, $host, $uri );
		$fingerprint = self::fingerprint( $surface, $directive, $host );

		if ( $this->is_suppressed( $surface, $directive, $host ) ) {
			$this->audit->log(
				'policy_change',
				'proposal_suppressed',
				"Skipped {$surface} {$directive} {$host}; previously rejected or reverted by an administrator.",
				'info'
			);
			return array(
				'status'      => 'suppressed',
				'id'          => 0,
				'risk_level'  => $risk['level'],
				'risk_reason' => $risk['reason'],
			);
		}

		$table = $wpdb->prefix . 'csp_source_inventory';
		$now   = current_time( 'mysql', true );

		$existing = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT id, approval_state, evidence_count FROM {$table} WHERE surface = %s AND directive = %s AND source_host = %s LIMIT 1",
				$surface,
				$directive,
				$host
			),
			ARRAY_A
		);

		if ( is_array( $existing ) && ! empty( $existing['id'] ) ) {
			$wpdb->update(
				$table,
				array(
					'source_uri'           => $uri,
					'source_scheme'        => $scheme,
					'owner_component'      => $this->normalise_token( $owner_component, 255 ),
					'owner_type'           => $this->normalise_token( $owner_type, 32 ),
					'last_seen_at'         => $now,
					'risk_level'           => $risk['level'],
					'risk_reason'          => $risk['reason'],
					'decision_fingerprint' => $fingerprint,
					'evidence_count'       => max( 1, (int) ( $existing['evidence_count'] ?? 1 ) ) + 1,
					'notes'                => sanitize_text_field( substr( $notes, 0, 512 ) ),
				),
				array( 'id' => (int) $existing['id'] ),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ),
				array( '%d' )
			);

			return array(
				'status'      => 'updated',
				'id'          => (int) $existing['id'],
				'risk_level'  => $risk['level'],
				'risk_reason' => $risk['reason'],
			);
		}

		$wpdb->insert(
			$table,
			array(
				'surface'              => $surface,
				'directive'            => $directive,
				'source_uri'           => $uri,
				'source_scheme'        => $scheme,
				'source_host'          => $host,
				'owner_component'      => $this->normalise_token( $owner_component, 255 ),
				'owner_type'           => $this->normalise_token( $owner_type, 32 ),
				'approval_state'       => 'pending',
				'first_seen_at'        => $now,
				'last_seen_at'         => $now,
				'notes'                => sanitize_text_field( substr( $notes, 0, 512 ) ),
				'risk_level'           => $risk['level'],
				'risk_reason'          => $risk['reason'],
				'decision_fingerprint' => $fingerprint,
				'evidence_count'       => 1,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
		);

		$severity = 'high' === $risk['level'] ? 'warning' : 'info';
		$this->audit->log(
			'policy_change',
			'source_proposed',
			"Proposed {$surface} {$directive} {$host} ({$risk['level']} risk: {$risk['reason']}).",
			$severity
		);

		return array(
			'status'      => 'added',
			'id'          => (int) ( $wpdb->insert_id ?? 0 ),
			'risk_level'  => $risk['level'],
			'risk_reason' => $risk['reason'],
		);
	}

	public function approve_source( int $source_id, string $reason = '' ): bool {
		return $this->decide_source( $source_id, 'approved', $reason, false );
	}

	public function reject_source( int $source_id, string $reason = '' ): bool {
		return $this->decide_source( $source_id, 'rejected', $reason, true );
	}

	public function revert_source( int $source_id, string $reason = '' ): bool {
		return $this->decide_source( $source_id, 'reverted', $reason, true );
	}

	public function is_suppressed( string $surface, string $directive, string $host ): bool {
		global $wpdb;

		$table       = $wpdb->prefix . 'csp_policy_change_decisions';
		$fingerprint = self::fingerprint( $surface, $directive, $host );

		$latest = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT action, suppression_active FROM {$table} WHERE decision_fingerprint = %s ORDER BY id DESC LIMIT 1",
				$fingerprint
			),
			ARRAY_A
		);

		return is_array( $latest ) && ! empty( $latest['suppression_active'] );
	}

	public static function fingerprint( string $surface, string $directive, string $host ): string {
		return hash(
			'sha256',
			strtolower( trim( $surface ) ) . '|' . strtolower( trim( $directive ) ) . '|' . strtolower( trim( $host ) )
		);
	}

	/**
	 * @return array{level:string,reason:string}
	 */
	public function classify_source_risk( string $directive, string $scheme, string $host, string $uri = '' ): array {
		$directive = $this->normalise_directive( $directive );
		$scheme    = strtolower( trim( $scheme ) );
		$host      = strtolower( trim( $host ) );
		$uri       = strtolower( trim( $uri ) );
		$reasons   = array();

		if ( str_contains( $host, '*' ) || str_contains( $uri, '*' ) ) {
			$reasons[] = 'wildcard source';
		}
		if ( 'http' === $scheme || str_starts_with( $uri, 'http:' ) ) {
			$reasons[] = 'cleartext HTTP source';
		}
		if ( in_array( $scheme, array( 'data', 'blob' ), true ) || str_starts_with( $uri, 'data:' ) || str_starts_with( $uri, 'blob:' ) ) {
			$reasons[] = 'broad browser scheme';
		}
		if ( str_contains( $uri, "'unsafe-inline'" ) || str_contains( $uri, "'unsafe-eval'" ) ) {
			$reasons[] = 'unsafe CSP keyword';
		}
		if ( in_array( $directive, self::HIGH_RISK_DIRECTIVES, true ) ) {
			$reasons[] = "{$directive} can materially change script, style, connection, form, frame, or worker execution";
		}

		if ( ! empty( $reasons ) ) {
			return array(
				'level'  => 'high',
				'reason' => implode( '; ', array_unique( $reasons ) ),
			);
		}

		if ( in_array( $directive, self::MEDIUM_RISK_DIRECTIVES, true ) ) {
			return array(
				'level'  => 'medium',
				'reason' => "{$directive} allows new third-party asset loading",
			);
		}

		return array(
			'level'  => 'low',
			'reason' => 'Narrow host-source proposal.',
		);
	}

	private function decide_source( int $source_id, string $action, string $reason, bool $suppress ): bool {
		if ( $source_id <= 0 ) {
			return false;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'csp_source_inventory';

		$source = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE id = %d LIMIT 1",
				$source_id
			),
			ARRAY_A
		);

		if ( ! is_array( $source ) || empty( $source['id'] ) ) {
			return false;
		}

		$now         = current_time( 'mysql', true );
		$user_id     = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$state       = 'approved' === $action ? 'approved' : 'denied';
		$fingerprint = ! empty( $source['decision_fingerprint'] ) ? $source['decision_fingerprint'] : self::fingerprint( $source['surface'], $source['directive'], $source['source_host'] );
		$risk_level  = ! empty( $source['risk_level'] ) ? $source['risk_level'] : 'low';
		$risk_reason = ! empty( $source['risk_reason'] ) ? $source['risk_reason'] : '';

		$data    = array(
			'approval_state'       => $state,
			'last_decision'        => $action,
			'decision_reason'      => sanitize_text_field( substr( $reason, 0, 512 ) ),
			'decided_at'           => $now,
			'decided_by'           => $user_id,
			'decision_fingerprint' => $fingerprint,
		);
		$formats = array( '%s', '%s', '%s', '%s', '%d', '%s' );

		if ( 'approved' === $state ) {
			$data['approved_at'] = $now;
			$formats[]           = '%s';
		}

		$updated = $wpdb->update(
			$table,
			$data,
			array( 'id' => $source_id ),
			$formats,
			array( '%d' )
		);

		if ( false === $updated ) {
			return false;
		}

		$this->record_decision( $source, $action, $fingerprint, $risk_level, $risk_reason, $reason, $suppress, $now, $user_id );

		$this->audit->log(
			'policy_change',
			"source_{$action}",
			"Administrator {$action} {$source['surface']} {$source['directive']} {$source['source_host']}.",
			'reverted' === $action || 'rejected' === $action ? 'warning' : 'info'
		);

		return true;
	}

	private function record_decision(
		array $source,
		string $action,
		string $fingerprint,
		string $risk_level,
		string $risk_reason,
		string $reason,
		bool $suppress,
		string $now,
		int $user_id
	): void {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'csp_policy_change_decisions',
			array(
				'change_type'          => 'source',
				'surface'              => $source['surface'],
				'directive'            => $source['directive'],
				'source_host'          => $source['source_host'],
				'source_uri'           => $source['source_uri'],
				'decision_fingerprint' => $fingerprint,
				'action'               => $action,
				'risk_level'           => $risk_level,
				'risk_reason'          => $risk_reason,
				'reason'               => sanitize_text_field( substr( $reason, 0, 512 ) ),
				'user_id'              => $user_id,
				'suppression_active'   => $suppress ? 1 : 0,
				'created_at'           => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' )
		);
	}

	private function normalise_directive( string $directive ): string {
		$directive = strtolower( trim( sanitize_text_field( $directive ) ) );
		return substr( $directive, 0, 64 );
	}

	private function normalise_host( string $host ): string {
		$host = strtolower( trim( sanitize_text_field( $host ) ) );
		return substr( $host, 0, 255 );
	}

	private function normalise_token( string $token, int $length ): string {
		return substr( strtolower( trim( sanitize_text_field( $token ) ) ), 0, $length );
	}
}
