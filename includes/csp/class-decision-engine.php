<?php
/**
 * Versioned deterministic CSP decision engine.
 */

declare( strict_types=1 );

namespace WP_CSP\CSP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Decision_Engine {

	public const ENGINE_VERSION = '1.0.0';

	private const RULE_VERSION = '1';

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
		'frame-ancestors',
		'base-uri',
		'worker-src',
		'child-src',
	);

	private const MEDIUM_RISK_DIRECTIVES = array(
		'font-src',
		'img-src',
		'media-src',
		'manifest-src',
	);

	/**
	 * @param array{surface?:string,directive?:string,source_scheme?:string,source_host?:string,source_uri?:string,evidence_count?:int,risk_level?:string,risk_reason?:string} $source
	 * @return array{engine_version:string,risk:string,automation_eligible:bool,required_human_review:bool,hard_exclusions:array<int,string>,summary:string,findings:array<int,array<string,mixed>>}
	 */
	public function evaluate_source( array $source, ?array $automation_config = null ): array {
		$directive  = strtolower( trim( (string) ( $source['directive'] ?? '' ) ) );
		$scheme     = strtolower( trim( (string) ( $source['source_scheme'] ?? '' ) ) );
		$host       = strtolower( trim( (string) ( $source['source_host'] ?? '' ) ) );
		$uri        = strtolower( trim( (string) ( $source['source_uri'] ?? '' ) ) );
		$findings   = array();
		$exclusions = array();
		$risk       = 'low';

		$this->add_finding( $findings, 'CSP-SRC-001', 'pass', 'none', 'neutral', 'Source expression is narrow unless another rule flags it.' );

		if ( '' === $directive || '' === $host ) {
			$risk         = 'unknown';
			$exclusions[] = 'unknown_or_malformed_source';
			$this->add_finding( $findings, 'CSP-SRC-002', 'fail', 'unknown', 'blocked', 'Missing directive or host prevents automatic handling.' );
		}

		if ( str_contains( $host, '*' ) || str_contains( $uri, '*' ) ) {
			$risk         = 'high';
			$exclusions[] = 'wildcard_source';
			$this->add_finding( $findings, 'CSP-SRC-003', 'fail', 'high', 'blocked', 'Wildcard sources are hard-excluded from automation.' );
		}

		if ( 'http' === $scheme || str_starts_with( $uri, 'http:' ) ) {
			$risk         = 'high';
			$exclusions[] = 'cleartext_http';
			$this->add_finding( $findings, 'CSP-SCHEME-001', 'fail', 'high', 'blocked', 'Cleartext HTTP sources are hard-excluded from automation.' );
		}

		if ( in_array( $scheme, array( 'data', 'blob' ), true ) || str_starts_with( $uri, 'data:' ) || str_starts_with( $uri, 'blob:' ) ) {
			$risk         = 'high';
			$exclusions[] = 'browser_scheme';
			$this->add_finding( $findings, 'CSP-SCHEME-002', 'fail', 'high', 'blocked', 'Browser schemes can affect executable or worker content and require review.' );
		}

		if ( str_contains( $uri, "'unsafe-inline'" ) || str_contains( $uri, "'unsafe-eval'" ) ) {
			$risk         = 'critical';
			$exclusions[] = 'unsafe_keyword';
			$this->add_finding( $findings, 'CSP-SRC-004', 'fail', 'critical', 'blocked', 'Unsafe CSP keywords are hard-excluded from automation.' );
		}

		if ( in_array( $directive, self::HIGH_RISK_DIRECTIVES, true ) ) {
			$risk = $this->max_risk( $risk, 'high' );
			$this->add_finding( $findings, 'CSP-DIR-001', 'review', 'high', 'review', "{$directive} can materially change script, style, connection, form, frame, or worker behaviour." );
		} elseif ( in_array( $directive, self::MEDIUM_RISK_DIRECTIVES, true ) ) {
			$risk = $this->max_risk( $risk, 'medium' );
			$this->add_finding( $findings, 'CSP-DIR-002', 'review', 'medium', 'review', "{$directive} allows new third-party asset loading." );
		}

		if ( isset( $source['evidence_count'] ) && (int) $source['evidence_count'] < 1 ) {
			$risk         = $this->max_risk( $risk, 'unknown' );
			$exclusions[] = 'insufficient_evidence';
			$this->add_finding( $findings, 'CSP-EVID-001', 'fail', 'unknown', 'blocked', 'At least one validated observation is required.' );
		}

		$mode                = (string) ( $automation_config['mode'] ?? 'manual' );
		$automation_eligible = 'manual' !== $mode && 'low' === $risk && empty( $exclusions );

		if ( 'manual' === $mode ) {
			$this->add_finding( $findings, 'CSP-AUTO-001', 'review', 'none', 'blocked', 'Automation mode is manual; administrator review is required.' );
		} elseif ( $automation_eligible ) {
			$this->add_finding( $findings, 'CSP-AUTO-002', 'pass', 'none', 'eligible', 'Proposal passes the low-risk deterministic automation boundary.' );
		} else {
			$this->add_finding( $findings, 'CSP-AUTO-003', 'review', $risk, 'blocked', 'Proposal does not meet the deterministic automation boundary.' );
		}

		return array(
			'engine_version'        => self::ENGINE_VERSION,
			'risk'                  => $risk,
			'automation_eligible'   => $automation_eligible,
			'required_human_review' => ! $automation_eligible,
			'hard_exclusions'       => array_values( array_unique( $exclusions ) ),
			'summary'               => $this->summarise( $risk, $exclusions ),
			'findings'              => $findings,
		);
	}

	private function add_finding( array &$findings, string $rule_id, string $result, string $risk_effect, string $automation_effect, string $explanation ): void {
		$findings[] = array(
			'rule_id'           => $rule_id,
			'rule_version'      => self::RULE_VERSION,
			'result'            => $result,
			'risk_effect'       => $risk_effect,
			'automation_effect' => $automation_effect,
			'explanation'       => $explanation,
		);
	}

	private function max_risk( string $a, string $b ): string {
		$order = array(
			'low'      => 1,
			'medium'   => 2,
			'high'     => 3,
			'critical' => 4,
			'unknown'  => 5,
		);
		return ( $order[ $b ] ?? 0 ) > ( $order[ $a ] ?? 0 ) ? $b : $a;
	}

	private function summarise( string $risk, array $exclusions ): string {
		if ( ! empty( $exclusions ) ) {
			return 'Hard automation exclusion: ' . implode( ', ', array_unique( $exclusions ) ) . '.';
		}
		if ( 'low' === $risk ) {
			return 'Narrow host-source proposal.';
		}
		return ucfirst( $risk ) . '-risk proposal requires administrator review.';
	}
}
