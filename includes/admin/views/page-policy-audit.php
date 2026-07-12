<?php
/**
 * Audit-first CSP policy view.
 *
 * @var \WP_CSP\Plugin $this Admin UI instance scope.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$surfaces          = array( 'frontend', 'admin', 'login', 'api' );
$automation_config = get_option( 'wp_csp_automation_config', array() );
$versions_table    = $wpdb->prefix . 'csp_policy_versions';
$decisions_table   = $wpdb->prefix . 'csp_policy_change_decisions';
$pending_table     = $wpdb->prefix . 'csp_source_inventory';

?>
<div class="wrap wp-csp-wrap">
	<h1><?php esc_html_e( 'CSP Policy Audit', 'wp-csp-automation' ); ?></h1>
	<p><?php esc_html_e( 'Inspect effective policies, decision provenance, pending review items, and policy version history.', 'wp-csp-automation' ); ?></p>

	<h2><?php esc_html_e( 'Current Policy', 'wp-csp-automation' ); ?></h2>
	<table class="widefat striped wp-csp-audit-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Surface', 'wp-csp-automation' ); ?></th>
				<th><?php esc_html_e( 'Mode', 'wp-csp-automation' ); ?></th>
				<th><?php esc_html_e( 'Automation', 'wp-csp-automation' ); ?></th>
				<th><?php esc_html_e( 'Policy Version', 'wp-csp-automation' ); ?></th>
				<th><?php esc_html_e( 'Pending', 'wp-csp-automation' ); ?></th>
				<th><?php esc_html_e( 'High Risk', 'wp-csp-automation' ); ?></th>
				<th><?php esc_html_e( 'Effective Header', 'wp-csp-automation' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $surfaces as $surface ) : ?>
				<?php
				$profile        = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT * FROM {$wpdb->prefix}csp_policy_profiles WHERE surface = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$surface
					),
					ARRAY_A
				);
				$latest         = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT * FROM {$versions_table} WHERE surface = %s ORDER BY version_number DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$surface
					),
					ARRAY_A
				);
				$pending_count  = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$pending_table} WHERE surface = %s AND approval_state = 'pending'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$surface
					)
				);
				$high_count     = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$pending_table} WHERE surface = %s AND approval_state = 'pending' AND risk_level IN ('critical','high','unknown')", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$surface
					)
				);
				$surface_config = is_array( $automation_config ) && isset( $automation_config[ $surface ] ) && is_array( $automation_config[ $surface ] )
					? $automation_config[ $surface ]
					: array( 'mode' => 'manual' );
				?>
				<tr>
					<td><strong><?php echo esc_html( ucfirst( $surface ) ); ?></strong></td>
					<td><?php echo esc_html( $profile['mode'] ?? 'unknown' ); ?></td>
					<td><?php echo esc_html( $surface_config['mode'] ?? 'manual' ); ?></td>
					<td><?php echo isset( $latest['version_number'] ) ? esc_html( (string) $latest['version_number'] ) : esc_html__( 'Not captured yet', 'wp-csp-automation' ); ?></td>
					<td><?php echo esc_html( (string) $pending_count ); ?></td>
					<td><?php echo esc_html( (string) $high_count ); ?></td>
					<td><code><?php echo esc_html( $latest['effective_header'] ?? '' ); ?></code></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<h2><?php esc_html_e( 'Pending Review Queue', 'wp-csp-automation' ); ?></h2>
	<?php
	$pending = $wpdb->get_results(
		"SELECT * FROM {$pending_table} WHERE approval_state = 'pending' ORDER BY FIELD(risk_level, 'critical', 'high', 'unknown', 'medium', 'low'), last_seen_at DESC LIMIT 50", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		ARRAY_A
	);
	?>
	<table class="widefat striped wp-csp-audit-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Surface', 'wp-csp-automation' ); ?></th>
				<th><?php esc_html_e( 'Directive', 'wp-csp-automation' ); ?></th>
				<th><?php esc_html_e( 'Source', 'wp-csp-automation' ); ?></th>
				<th><?php esc_html_e( 'Risk', 'wp-csp-automation' ); ?></th>
				<th><?php esc_html_e( 'Evidence', 'wp-csp-automation' ); ?></th>
				<th><?php esc_html_e( 'First Seen', 'wp-csp-automation' ); ?></th>
				<th><?php esc_html_e( 'Last Seen', 'wp-csp-automation' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( is_array( $pending ) ? $pending : array() as $item ) : ?>
				<tr>
					<td><?php echo esc_html( $item['surface'] ); ?></td>
					<td><code><?php echo esc_html( $item['directive'] ); ?></code></td>
					<td><code><?php echo esc_html( $item['source_host'] ); ?></code></td>
					<td><span class="wp-csp-risk-badge risk-<?php echo esc_attr( $item['risk_level'] ); ?>"><?php echo esc_html( ucfirst( $item['risk_level'] ) ); ?></span> <?php echo esc_html( $item['risk_reason'] ); ?></td>
					<td><?php echo esc_html( (string) $item['evidence_count'] ); ?></td>
					<td><?php echo esc_html( $item['first_seen_at'] ); ?></td>
					<td><?php echo esc_html( $item['last_seen_at'] ); ?></td>
				</tr>
			<?php endforeach; ?>
			<?php if ( empty( $pending ) ) : ?>
				<tr><td colspan="7"><?php esc_html_e( 'No pending source proposals require review.', 'wp-csp-automation' ); ?></td></tr>
			<?php endif; ?>
		</tbody>
	</table>

	<h2><?php esc_html_e( 'Recent Decisions', 'wp-csp-automation' ); ?></h2>
	<?php $decisions = $wpdb->get_results( "SELECT * FROM {$decisions_table} ORDER BY created_at DESC LIMIT 50", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared ?>
	<table class="widefat striped wp-csp-audit-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'When', 'wp-csp-automation' ); ?></th>
				<th><?php esc_html_e( 'Decision', 'wp-csp-automation' ); ?></th>
				<th><?php esc_html_e( 'Actor', 'wp-csp-automation' ); ?></th>
				<th><?php esc_html_e( 'Surface', 'wp-csp-automation' ); ?></th>
				<th><?php esc_html_e( 'Directive', 'wp-csp-automation' ); ?></th>
				<th><?php esc_html_e( 'Source', 'wp-csp-automation' ); ?></th>
				<th><?php esc_html_e( 'Risk', 'wp-csp-automation' ); ?></th>
				<th><?php esc_html_e( 'Engine', 'wp-csp-automation' ); ?></th>
				<th><?php esc_html_e( 'Policy Version', 'wp-csp-automation' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( is_array( $decisions ) ? $decisions : array() as $decision ) : ?>
				<tr>
					<td><?php echo esc_html( $decision['created_at'] ); ?></td>
					<td><?php echo esc_html( ucfirst( '' !== (string) $decision['state'] ? (string) $decision['state'] : (string) $decision['action'] ) ); ?></td>
					<td><?php echo esc_html( $decision['actor_type'] ?? 'administrator' ); ?></td>
					<td><?php echo esc_html( $decision['surface'] ); ?></td>
					<td><code><?php echo esc_html( $decision['directive'] ); ?></code></td>
					<td><code><?php echo esc_html( $decision['source_host'] ); ?></code></td>
					<td><span class="wp-csp-risk-badge risk-<?php echo esc_attr( $decision['risk_level'] ); ?>"><?php echo esc_html( ucfirst( $decision['risk_level'] ) ); ?></span></td>
					<td><?php echo esc_html( $decision['decision_engine_version'] ?? '' ); ?></td>
					<td><?php echo ! empty( $decision['policy_version_id'] ) ? esc_html( (string) $decision['policy_version_id'] ) : esc_html( '—' ); ?></td>
				</tr>
			<?php endforeach; ?>
			<?php if ( empty( $decisions ) ) : ?>
				<tr><td colspan="9"><?php esc_html_e( 'No decisions have been recorded yet.', 'wp-csp-automation' ); ?></td></tr>
			<?php endif; ?>
		</tbody>
	</table>
</div>
