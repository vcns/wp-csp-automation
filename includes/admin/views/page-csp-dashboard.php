<?php
/**
 * Admin view: CSP Automation Manager dashboard.
 * Shows per-surface policy profiles, source inventory, violations, scan log.
 * Rendered by Admin_UI::render_dashboard().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

// Current tab.
$tab          = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'profiles';
$allowed_tabs = array( 'profiles', 'sources', 'policy-changes', 'violations', 'scan-log' );
if ( ! in_array( $tab, $allowed_tabs, true ) ) {
	$tab = 'profiles';
}

$base_url = admin_url( 'admin.php?page=csp-automation-manager-dashboard' );

// ── Data queries ──────────────────────────────────────────────────────────────
// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$profiles_raw = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}csp_policy_profiles ORDER BY surface", ARRAY_A );
$profiles     = ! empty( $profiles_raw ) ? $profiles_raw : array();
$surfaces     = array( 'frontend', 'admin', 'login', 'api' );

// Shared pagination defaults.
$per_page = 20;
$page_num = max( 1, (int) ( isset( $_GET['paged'] ) ? $_GET['paged'] : 1 ) );
$offset   = ( $page_num - 1 ) * $per_page;

// Violations – last 50.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$violations_raw = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}csp_violation_reports ORDER BY reported_at DESC LIMIT 50", ARRAY_A );
$violations     = ! empty( $violations_raw ) ? $violations_raw : array();

// Scan log – last 20 runs.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$scan_logs_raw = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}csp_scan_logs ORDER BY started_at DESC LIMIT 20", ARRAY_A );
$scan_logs     = ! empty( $scan_logs_raw ) ? $scan_logs_raw : array();
?>
<div class="wrap wp-csp-wrap">
	<h1><?php esc_html_e( 'CSP Automation Manager Dashboard', 'csp-automation-manager' ); ?></h1>

	<!-- ── Top action bar ────────────────────────────────────────────────── -->
	<p>
		<button type="button" id="wp-csp-manual-scan" class="button button-primary">
			<?php esc_html_e( 'Run Manual Scan', 'csp-automation-manager' ); ?>
		</button>
		<span id="wp-csp-scan-status" style="margin-left:10px;display:none"></span>
	</p>

	<!-- ── Tabs ──────────────────────────────────────────────────────────── -->
	<nav class="nav-tab-wrapper">
		<a class="nav-tab<?php echo 'profiles' === $tab ? ' nav-tab-active' : ''; ?>"
			href="<?php echo esc_url( add_query_arg( 'tab', 'profiles', $base_url ) ); ?>">
			<?php esc_html_e( 'Profiles', 'csp-automation-manager' ); ?>
		</a>
		<a class="nav-tab<?php echo 'sources' === $tab ? ' nav-tab-active' : ''; ?>"
			href="<?php echo esc_url( add_query_arg( 'tab', 'sources', $base_url ) ); ?>">
			<?php esc_html_e( 'Source Inventory', 'csp-automation-manager' ); ?>
		</a>
		<a class="nav-tab<?php echo 'policy-changes' === $tab ? ' nav-tab-active' : ''; ?>"
			href="<?php echo esc_url( add_query_arg( 'tab', 'policy-changes', $base_url ) ); ?>">
			<?php esc_html_e( 'Policy Changes', 'csp-automation-manager' ); ?>
		</a>
		<a class="nav-tab<?php echo 'violations' === $tab ? ' nav-tab-active' : ''; ?>"
			href="<?php echo esc_url( add_query_arg( 'tab', 'violations', $base_url ) ); ?>">
			<?php esc_html_e( 'Violations', 'csp-automation-manager' ); ?>
		</a>
		<a class="nav-tab<?php echo 'scan-log' === $tab ? ' nav-tab-active' : ''; ?>"
			href="<?php echo esc_url( add_query_arg( 'tab', 'scan-log', $base_url ) ); ?>">
			<?php esc_html_e( 'Scan Log', 'csp-automation-manager' ); ?>
		</a>
	</nav>

	<div class="tab-content" style="margin-top:1em">

	<?php if ( 'profiles' === $tab ) : ?>
	<!-- ── Profiles tab ───────────────────────────────────────────────────── -->
		<?php
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- No user input; only $wpdb->prefix used in query.
		$profiles_raw = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}csp_policy_profiles ORDER BY surface", ARRAY_A );
		$profiles     = ! empty( $profiles_raw ) ? $profiles_raw : array();
		?>
	<table class="widefat fixed striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Surface', 'csp-automation-manager' ); ?></th>
				<th><?php esc_html_e( 'Mode', 'csp-automation-manager' ); ?></th>
				<th><?php esc_html_e( 'Strict-Dynamic', 'csp-automation-manager' ); ?></th>
				<th><?php esc_html_e( 'Last Updated', 'csp-automation-manager' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'csp-automation-manager' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $profiles as $profile ) : ?>
		<tr>
			<td><?php echo esc_html( ucfirst( $profile['surface'] ) ); ?></td>
			<td>
				<span class="wp-csp-mode-badge mode-<?php echo esc_attr( $profile['mode'] ); ?>">
					<?php echo esc_html( $profile['mode'] ); ?>
				</span>
			</td>
			<td><?php echo $profile['strict_dynamic'] ? esc_html__( 'Yes', 'csp-automation-manager' ) : '&mdash;'; ?></td>
			<td><?php echo esc_html( $profile['updated_at'] ); ?></td>
			<td>
				<?php foreach ( array( 'report-only', 'enforce', 'disabled' ) as $m ) : ?>
					<?php if ( $m !== $profile['mode'] ) : ?>
					<button type="button"
						class="button button-small wp-csp-toggle-mode"
						data-surface="<?php echo esc_attr( $profile['surface'] ); ?>"
						data-mode="<?php echo esc_attr( $m ); ?>">
						<?php echo esc_html( ucwords( str_replace( '-', ' ', $m ) ) ); ?>
					</button>
					<?php endif; ?>
				<?php endforeach; ?>
			</td>
		</tr>
		<?php endforeach; ?>
		<?php if ( empty( $profiles ) ) : ?>
		<tr><td colspan="5"><?php esc_html_e( 'No profiles found. Deactivate and reactivate the plugin to seed defaults.', 'csp-automation-manager' ); ?></td></tr>
		<?php endif; ?>
		</tbody>
	</table>

	<?php elseif ( 'sources' === $tab ) : ?>
	<!-- ── Sources tab ────────────────────────────────────────────────────── -->
		<?php
		$src_surface = isset( $_GET['src_surface'] ) ? sanitize_text_field( wp_unslash( $_GET['src_surface'] ) ) : '';
		$src_state   = isset( $_GET['src_state'] ) ? sanitize_text_field( wp_unslash( $_GET['src_state'] ) ) : '';
		$src_risk    = isset( $_GET['src_risk'] ) ? sanitize_text_field( wp_unslash( $_GET['src_risk'] ) ) : '';

		$src_where = array( '1=1' );
		$src_args  = array();
		if ( $src_surface ) {
			$src_where[] = 'surface = %s';
			$src_args[]  = $src_surface;
		}
		if ( $src_state ) {
			$src_where[] = 'approval_state = %s';
			$src_args[]  = $src_state;
		}
		if ( $src_risk ) {
			$src_where[] = 'risk_level = %s';
			$src_args[]  = $src_risk;
		}

		$src_where_sql = implode( ' AND ', $src_where );
		$count_sql     = "SELECT COUNT(*) FROM {$wpdb->prefix}csp_source_inventory WHERE {$src_where_sql}";
		if ( ! empty( $src_args ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$count_sql = $wpdb->prepare( $count_sql, ...$src_args );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
		$src_total = (int) $wpdb->get_var( $count_sql );

		$src_pages = max( 1, (int) ceil( $src_total / $per_page ) );
		$page_num  = min( max( 1, (int) ( isset( $_GET['paged'] ) ? $_GET['paged'] : 1 ) ), $src_pages );
		$offset    = ( $page_num - 1 ) * $per_page;

		$query_args = array_merge( $src_args, array( $per_page, $offset ) );
		$data_sql   = "SELECT * FROM {$wpdb->prefix}csp_source_inventory WHERE {$src_where_sql} ORDER BY CASE risk_level WHEN 'high' THEN 0 WHEN 'medium' THEN 1 ELSE 2 END, last_seen_at DESC LIMIT %d OFFSET %d";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$data_sql = $wpdb->prepare( $data_sql, ...$query_args );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		$sources_raw = $wpdb->get_results( $data_sql, ARRAY_A );
		$sources     = ! empty( $sources_raw ) ? $sources_raw : array();
		?>
	<form method="get" action="">
		<input type="hidden" name="page" value="csp-automation-manager-dashboard" />
		<input type="hidden" name="tab"  value="sources" />
		<select name="src_surface">
			<option value=""><?php esc_html_e( 'All surfaces', 'csp-automation-manager' ); ?></option>
			<?php foreach ( $surfaces as $s ) : ?>
			<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $src_surface, $s ); ?>><?php echo esc_html( ucfirst( $s ) ); ?></option>
			<?php endforeach; ?>
		</select>
		<select name="src_state">
			<option value=""><?php esc_html_e( 'All states', 'csp-automation-manager' ); ?></option>
			<?php foreach ( array( 'pending', 'approved', 'denied' ) as $st ) : ?>
			<option value="<?php echo esc_attr( $st ); ?>" <?php selected( $src_state, $st ); ?>><?php echo esc_html( ucfirst( $st ) ); ?></option>
			<?php endforeach; ?>
		</select>
		<select name="src_risk">
			<option value=""><?php esc_html_e( 'All risk levels', 'csp-automation-manager' ); ?></option>
			<?php foreach ( array( 'high', 'medium', 'low' ) as $risk ) : ?>
			<option value="<?php echo esc_attr( $risk ); ?>" <?php selected( $src_risk, $risk ); ?>><?php echo esc_html( ucfirst( $risk ) ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php submit_button( __( 'Filter', 'csp-automation-manager' ), 'secondary', 'filter_sources', false ); ?>
	</form>

	<table class="widefat fixed striped" style="margin-top:1em">
		<thead>
			<tr>
				<th style="width:80px"><?php esc_html_e( 'ID', 'csp-automation-manager' ); ?></th>
				<th><?php esc_html_e( 'Surface', 'csp-automation-manager' ); ?></th>
				<th><?php esc_html_e( 'Directive', 'csp-automation-manager' ); ?></th>
				<th><?php esc_html_e( 'Host', 'csp-automation-manager' ); ?></th>
				<th><?php esc_html_e( 'Risk', 'csp-automation-manager' ); ?></th>
				<th><?php esc_html_e( 'State', 'csp-automation-manager' ); ?></th>
				<th><?php esc_html_e( 'Evidence', 'csp-automation-manager' ); ?></th>
				<th><?php esc_html_e( 'Last Seen', 'csp-automation-manager' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'csp-automation-manager' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $sources as $src ) : ?>
		<tr>
			<td><?php echo esc_html( $src['id'] ); ?></td>
			<td><?php echo esc_html( $src['surface'] ); ?></td>
			<td><code><?php echo esc_html( $src['directive'] ); ?></code></td>
			<td><code><?php echo esc_html( $src['source_host'] ); ?></code></td>
			<td>
				<span class="wp-csp-risk-badge risk-<?php echo esc_attr( $src['risk_level'] ?? 'low' ); ?>" title="<?php echo esc_attr( $src['risk_reason'] ?? '' ); ?>">
					<?php echo esc_html( ucfirst( $src['risk_level'] ?? 'low' ) ); ?>
				</span>
			</td>
			<td>
				<span class="wp-csp-state-badge state-<?php echo esc_attr( $src['approval_state'] ); ?>">
					<?php echo esc_html( ucfirst( $src['approval_state'] ) ); ?>
				</span>
			</td>
			<td><?php echo esc_html( number_format( (int) ( $src['evidence_count'] ?? 1 ) ) ); ?></td>
			<td><?php echo esc_html( $src['last_seen_at'] ); ?></td>
			<td>
				<?php if ( 'pending' === $src['approval_state'] || 'denied' === $src['approval_state'] ) : ?>
				<button type="button" class="button button-small wp-csp-approve-source" data-id="<?php echo esc_attr( $src['id'] ); ?>">
					<?php esc_html_e( 'Approve', 'csp-automation-manager' ); ?>
				</button>
				<?php endif; ?>
				<?php if ( 'pending' === $src['approval_state'] || 'approved' === $src['approval_state'] ) : ?>
				<button type="button" class="button button-small wp-csp-deny-source" data-id="<?php echo esc_attr( $src['id'] ); ?>">
					<?php esc_html_e( 'Reject', 'csp-automation-manager' ); ?>
				</button>
				<?php endif; ?>
				<?php if ( 'approved' === $src['approval_state'] ) : ?>
				<button type="button" class="button button-small wp-csp-revert-source" data-id="<?php echo esc_attr( $src['id'] ); ?>">
					<?php esc_html_e( 'Revert', 'csp-automation-manager' ); ?>
				</button>
				<?php endif; ?>
			</td>
		</tr>
		<?php endforeach; ?>
		<?php if ( empty( $sources ) ) : ?>
		<tr><td colspan="9"><?php esc_html_e( 'No sources discovered yet. Run a scan to populate this table.', 'csp-automation-manager' ); ?></td></tr>
		<?php endif; ?>
		</tbody>
	</table>

		<?php if ( $src_pages > 1 ) : ?>
	<div class="tablenav bottom" style="margin-top:1em">
		<div class="tablenav-pages">
			<?php
				$src_page_args = array_filter(
					array(
						'tab'         => 'sources',
						'src_surface' => $src_surface,
						'src_state'   => $src_state,
						'src_risk'    => $src_risk,
					)
				);
			?>
			<?php if ( $page_num > 1 ) : ?>
			<a class="button" href="<?php echo esc_url( add_query_arg( array_merge( $src_page_args, array( 'paged' => $page_num - 1 ) ), $base_url ) ); ?>">
				&laquo; <?php esc_html_e( 'Previous', 'csp-automation-manager' ); ?>
			</a>
			<?php endif; ?>
			<span style="margin:0 8px">
				<?php
				printf(
					/* translators: 1: current page number, 2: total pages */
					esc_html__( 'Page %1$d of %2$d', 'csp-automation-manager' ),
					$page_num,
					$src_pages
				);
				?>
			</span>
			<?php if ( $page_num < $src_pages ) : ?>
			<a class="button" href="<?php echo esc_url( add_query_arg( array_merge( $src_page_args, array( 'paged' => $page_num + 1 ) ), $base_url ) ); ?>">
				<?php esc_html_e( 'Next', 'csp-automation-manager' ); ?> &raquo;
			</a>
			<?php endif; ?>
		</div>
	</div>
	<?php endif; ?>

	<?php elseif ( 'policy-changes' === $tab ) : ?>
	<!-- Policy Changes tab -->
		<?php
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- No user input; only $wpdb->prefix used in query.
		$policy_decisions_raw = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}csp_policy_change_decisions ORDER BY created_at DESC LIMIT 100", ARRAY_A );
		$policy_decisions     = ! empty( $policy_decisions_raw ) ? $policy_decisions_raw : array();
		?>
	<p class="description">
		<?php esc_html_e( 'Every approval, rejection, and revert is recorded here. Rejected and reverted changes suppress the same source fingerprint until a later approval becomes the newest decision.', 'csp-automation-manager' ); ?>
	</p>
	<table class="widefat fixed striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'When', 'csp-automation-manager' ); ?></th>
				<th><?php esc_html_e( 'Action', 'csp-automation-manager' ); ?></th>
				<th><?php esc_html_e( 'Surface', 'csp-automation-manager' ); ?></th>
				<th><?php esc_html_e( 'Directive', 'csp-automation-manager' ); ?></th>
				<th><?php esc_html_e( 'Host', 'csp-automation-manager' ); ?></th>
				<th><?php esc_html_e( 'Risk', 'csp-automation-manager' ); ?></th>
				<th><?php esc_html_e( 'Suppression', 'csp-automation-manager' ); ?></th>
				<th><?php esc_html_e( 'Reason', 'csp-automation-manager' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $policy_decisions as $decision ) : ?>
		<tr>
			<td><?php echo esc_html( $decision['created_at'] ); ?></td>
			<td><?php echo esc_html( ucfirst( $decision['action'] ) ); ?></td>
			<td><?php echo esc_html( $decision['surface'] ); ?></td>
			<td><code><?php echo esc_html( $decision['directive'] ); ?></code></td>
			<td><code><?php echo esc_html( $decision['source_host'] ); ?></code></td>
			<td>
				<span class="wp-csp-risk-badge risk-<?php echo esc_attr( $decision['risk_level'] ); ?>" title="<?php echo esc_attr( $decision['risk_reason'] ); ?>">
					<?php echo esc_html( ucfirst( $decision['risk_level'] ) ); ?>
				</span>
			</td>
			<td>
				<?php echo ! empty( $decision['suppression_active'] ) ? esc_html__( 'Active', 'csp-automation-manager' ) : '&mdash;'; ?>
			</td>
			<td><?php echo esc_html( $decision['reason'] ); ?></td>
		</tr>
		<?php endforeach; ?>
		<?php if ( empty( $policy_decisions ) ) : ?>
		<tr><td colspan="8"><?php esc_html_e( 'No policy decisions have been recorded yet.', 'csp-automation-manager' ); ?></td></tr>
		<?php endif; ?>
		</tbody>
	</table>

	<?php elseif ( 'violations' === $tab ) : ?>
	<!-- ── Violations tab ─────────────────────────────────────────────────── -->
		<?php
		$viol_page_num = max( 1, (int) ( isset( $_GET['v_paged'] ) ? $_GET['v_paged'] : 1 ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- No user input; only $wpdb->prefix used in query.
		$viol_total    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}csp_violation_reports" );
		$viol_pages    = max( 1, (int) ceil( $viol_total / $per_page ) );
		$viol_page_num = min( $viol_page_num, $viol_pages );
		$viol_offset   = ( $viol_page_num - 1 ) * $per_page;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$violations_raw = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}csp_violation_reports ORDER BY reported_at DESC LIMIT %d OFFSET %d", $per_page, $viol_offset ), ARRAY_A );
		$violations     = ! empty( $violations_raw ) ? $violations_raw : array();
		?>
	<table class="widefat fixed striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Surface', 'csp-automation-manager' ); ?></th>
				<th><?php esc_html_e( 'Blocked URI', 'csp-automation-manager' ); ?></th>
				<th><?php esc_html_e( 'Directive', 'csp-automation-manager' ); ?></th>
				<th><?php esc_html_e( 'Occurrences', 'csp-automation-manager' ); ?></th>
				<th><?php esc_html_e( 'Last Seen', 'csp-automation-manager' ); ?></th>
				<th><?php esc_html_e( 'Disposition', 'csp-automation-manager' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $violations as $v ) : ?>
		<tr>
			<td><?php echo esc_html( $v['profile_surface'] ); ?></td>
			<td><code style="word-break:break-all"><?php echo esc_html( $v['blocked_uri'] ); ?></code></td>
			<td><code><?php echo esc_html( $v['violated_directive'] ); ?></code></td>
			<td><?php echo esc_html( number_format( (int) $v['occurrence_count'] ) ); ?></td>
			<td><?php echo esc_html( $v['reported_at'] ); ?></td>
			<td><?php echo esc_html( $v['disposition'] ); ?></td>
		</tr>
		<?php endforeach; ?>
		<?php if ( empty( $violations ) ) : ?>
		<tr><td colspan="6"><?php esc_html_e( 'No violations recorded yet. Configure the report-uri on a live environment to start collecting.', 'csp-automation-manager' ); ?></td></tr>
		<?php endif; ?>
		</tbody>
	</table>

		<?php if ( $viol_pages > 1 ) : ?>
	<div class="tablenav bottom" style="margin-top:1em">
		<div class="tablenav-pages">
			<?php if ( $viol_page_num > 1 ) : ?>
			<a class="button" href="
				<?php
				echo esc_url(
					add_query_arg(
						array(
							'tab'     => 'violations',
							'v_paged' => $viol_page_num - 1,
						),
						$base_url
					)
				);
				?>
									">&laquo; <?php esc_html_e( 'Previous', 'csp-automation-manager' ); ?></a>
			<?php endif; ?>
			<span style="margin:0 8px">
				<?php
				printf(
					/* translators: 1: current page number, 2: total pages */
					esc_html__( 'Page %1$d of %2$d', 'csp-automation-manager' ),
					$viol_page_num,
					$viol_pages
				);
				?>
			</span>
			<?php if ( $viol_page_num < $viol_pages ) : ?>
			<a class="button" href="
				<?php
				echo esc_url(
					add_query_arg(
						array(
							'tab'     => 'violations',
							'v_paged' => $viol_page_num + 1,
						),
						$base_url
					)
				);
				?>
									"><?php esc_html_e( 'Next', 'csp-automation-manager' ); ?> &raquo;</a>
			<?php endif; ?>
		</div>
	</div>
	<?php endif; ?>

	<?php elseif ( 'scan-log' === $tab ) : ?>
	<!-- ── Scan log tab ───────────────────────────────────────────────────── -->
		<?php
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- No user input; only $wpdb->prefix used in query.
		$scan_logs_raw = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}csp_scan_logs ORDER BY started_at DESC LIMIT 20", ARRAY_A );
		$scan_logs     = ! empty( $scan_logs_raw ) ? $scan_logs_raw : array();
		?>
	<table class="widefat fixed striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Trigger', 'csp-automation-manager' ); ?></th>
				<th><?php esc_html_e( 'Status', 'csp-automation-manager' ); ?></th>
				<th><?php esc_html_e( 'Sources +/-', 'csp-automation-manager' ); ?></th>
				<th><?php esc_html_e( 'Hashes +/-', 'csp-automation-manager' ); ?></th>
				<th><?php esc_html_e( 'Policy Changed', 'csp-automation-manager' ); ?></th>
				<th><?php esc_html_e( 'Started', 'csp-automation-manager' ); ?></th>
				<th><?php esc_html_e( 'Duration', 'csp-automation-manager' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $scan_logs as $log ) : ?>
			<?php
			$duration = '';
			if ( $log['completed_at'] && $log['started_at'] ) {
				$diff     = strtotime( $log['completed_at'] ) - strtotime( $log['started_at'] );
				$duration = $diff . 's';
			}
			?>
		<tr>
			<td><?php echo esc_html( ucfirst( $log['trigger_type'] ) ); ?></td>
			<td><?php echo esc_html( ucfirst( $log['status'] ) ); ?></td>
			<td>+<?php echo esc_html( $log['sources_added'] ); ?> / -<?php echo esc_html( $log['sources_removed'] ); ?></td>
			<td>+<?php echo esc_html( $log['hashes_added'] ); ?> / -<?php echo esc_html( $log['hashes_removed'] ); ?></td>
			<td><?php echo $log['policy_changed'] ? esc_html__( 'Yes', 'csp-automation-manager' ) : '&mdash;'; ?></td>
			<td><?php echo esc_html( $log['started_at'] ); ?></td>
			<td><?php echo esc_html( $duration ); ?></td>
		</tr>
		<?php endforeach; ?>
		<?php if ( empty( $scan_logs ) ) : ?>
		<tr><td colspan="7"><?php esc_html_e( 'No scans run yet.', 'csp-automation-manager' ); ?></td></tr>
		<?php endif; ?>
		</tbody>
	</table>
	<?php endif; ?>

	</div><!-- .tab-content -->
</div><!-- .wp-csp-wrap -->
