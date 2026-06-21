<?php
/**
 * Admin view: WP CSP Automation Dashboard.
 * Shows per-surface policy profiles, source inventory, violations, scan log.
 * Rendered by Admin_UI::render_dashboard().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

// Current tab.
$tab          = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'profiles';
$allowed_tabs = array( 'profiles', 'sources', 'violations', 'scan-log' );
if ( ! in_array( $tab, $allowed_tabs, true ) ) {
	$tab = 'profiles';
}

$base_url = admin_url( 'admin.php?page=wp-csp-automation-dashboard' );

$surfaces = array( 'frontend', 'admin', 'login', 'api' );
$per_page = 20;
?>
<div class="wrap wp-csp-wrap">
	<h1><?php esc_html_e( 'WP CSP Automation – Dashboard', 'wp-csp-automation' ); ?></h1>

	<!-- ── Top action bar ────────────────────────────────────────────────── -->
	<p>
		<button type="button" id="wp-csp-manual-scan" class="button button-primary">
			<?php esc_html_e( 'Run Manual Scan', 'wp-csp-automation' ); ?>
		</button>
		<span id="wp-csp-scan-status" style="margin-left:10px;display:none"></span>
	</p>

	<!-- ── Tabs ──────────────────────────────────────────────────────────── -->
	<nav class="nav-tab-wrapper">
		<a class="nav-tab<?php echo 'profiles' === $tab ? ' nav-tab-active' : ''; ?>"
			href="<?php echo esc_url( add_query_arg( 'tab', 'profiles', $base_url ) ); ?>">
			<?php esc_html_e( 'Profiles', 'wp-csp-automation' ); ?>
		</a>
		<a class="nav-tab<?php echo 'sources' === $tab ? ' nav-tab-active' : ''; ?>"
			href="<?php echo esc_url( add_query_arg( 'tab', 'sources', $base_url ) ); ?>">
			<?php esc_html_e( 'Source Inventory', 'wp-csp-automation' ); ?>
		</a>
		<a class="nav-tab<?php echo 'violations' === $tab ? ' nav-tab-active' : ''; ?>"
			href="<?php echo esc_url( add_query_arg( 'tab', 'violations', $base_url ) ); ?>">
			<?php esc_html_e( 'Violations', 'wp-csp-automation' ); ?>
		</a>
		<a class="nav-tab<?php echo 'scan-log' === $tab ? ' nav-tab-active' : ''; ?>"
			href="<?php echo esc_url( add_query_arg( 'tab', 'scan-log', $base_url ) ); ?>">
			<?php esc_html_e( 'Scan Log', 'wp-csp-automation' ); ?>
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
				<th><?php esc_html_e( 'Surface', 'wp-csp-automation' ); ?></th>
				<th><?php esc_html_e( 'Mode', 'wp-csp-automation' ); ?></th>
				<th><?php esc_html_e( 'Strict-Dynamic', 'wp-csp-automation' ); ?></th>
				<th><?php esc_html_e( 'Last Updated', 'wp-csp-automation' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'wp-csp-automation' ); ?></th>
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
			<td><?php echo $profile['strict_dynamic'] ? esc_html__( 'Yes', 'wp-csp-automation' ) : '&mdash;'; ?></td>
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
		<tr><td colspan="5"><?php esc_html_e( 'No profiles found. Deactivate and reactivate the plugin to seed defaults.', 'wp-csp-automation' ); ?></td></tr>
		<?php endif; ?>
		</tbody>
	</table>

	<?php elseif ( 'sources' === $tab ) : ?>
	<!-- ── Sources tab ────────────────────────────────────────────────────── -->
		<?php
		$src_surface = isset( $_GET['src_surface'] ) ? sanitize_text_field( wp_unslash( $_GET['src_surface'] ) ) : '';
		$src_state   = isset( $_GET['src_state'] ) ? sanitize_text_field( wp_unslash( $_GET['src_state'] ) ) : '';

		// Count matching rows (same filters as data query) for pagination.
		if ( $src_surface && $src_state ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$src_total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}csp_source_inventory WHERE surface = %s AND approval_state = %s", $src_surface, $src_state ) );
		} elseif ( $src_surface ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$src_total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}csp_source_inventory WHERE surface = %s", $src_surface ) );
		} elseif ( $src_state ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$src_total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}csp_source_inventory WHERE approval_state = %s", $src_state ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- No user input; only $wpdb->prefix used in query.
			$src_total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}csp_source_inventory" );
		}
		$src_pages = max( 1, (int) ceil( $src_total / $per_page ) );
		$page_num  = min( max( 1, (int) ( isset( $_GET['paged'] ) ? $_GET['paged'] : 1 ) ), $src_pages );
		$offset    = ( $page_num - 1 ) * $per_page;

		// Data query.
		if ( $src_surface && $src_state ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sources_raw = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}csp_source_inventory WHERE surface = %s AND approval_state = %s ORDER BY last_seen_at DESC LIMIT %d OFFSET %d", $src_surface, $src_state, $per_page, $offset ), ARRAY_A );
		} elseif ( $src_surface ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sources_raw = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}csp_source_inventory WHERE surface = %s ORDER BY last_seen_at DESC LIMIT %d OFFSET %d", $src_surface, $per_page, $offset ), ARRAY_A );
		} elseif ( $src_state ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sources_raw = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}csp_source_inventory WHERE approval_state = %s ORDER BY last_seen_at DESC LIMIT %d OFFSET %d", $src_state, $per_page, $offset ), ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sources_raw = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}csp_source_inventory ORDER BY last_seen_at DESC LIMIT %d OFFSET %d", $per_page, $offset ), ARRAY_A );
		}
		$sources = ! empty( $sources_raw ) ? $sources_raw : array();
		?>
	<form method="get" action="">
		<input type="hidden" name="page" value="wp-csp-automation-dashboard" />
		<input type="hidden" name="tab"  value="sources" />
		<select name="src_surface">
			<option value=""><?php esc_html_e( 'All surfaces', 'wp-csp-automation' ); ?></option>
			<?php foreach ( $surfaces as $s ) : ?>
			<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $src_surface, $s ); ?>><?php echo esc_html( ucfirst( $s ) ); ?></option>
			<?php endforeach; ?>
		</select>
		<select name="src_state">
			<option value=""><?php esc_html_e( 'All states', 'wp-csp-automation' ); ?></option>
			<?php foreach ( array( 'pending', 'approved', 'denied' ) as $st ) : ?>
			<option value="<?php echo esc_attr( $st ); ?>" <?php selected( $src_state, $st ); ?>><?php echo esc_html( ucfirst( $st ) ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php submit_button( __( 'Filter', 'wp-csp-automation' ), 'secondary', 'filter_sources', false ); ?>
	</form>

	<table class="widefat fixed striped" style="margin-top:1em">
		<thead>
			<tr>
				<th style="width:80px"><?php esc_html_e( 'ID', 'wp-csp-automation' ); ?></th>
				<th><?php esc_html_e( 'Surface', 'wp-csp-automation' ); ?></th>
				<th><?php esc_html_e( 'Directive', 'wp-csp-automation' ); ?></th>
				<th><?php esc_html_e( 'Host', 'wp-csp-automation' ); ?></th>
				<th><?php esc_html_e( 'State', 'wp-csp-automation' ); ?></th>
				<th><?php esc_html_e( 'Last Seen', 'wp-csp-automation' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'wp-csp-automation' ); ?></th>
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
				<span class="wp-csp-state-badge state-<?php echo esc_attr( $src['approval_state'] ); ?>">
					<?php echo esc_html( ucfirst( $src['approval_state'] ) ); ?>
				</span>
			</td>
			<td><?php echo esc_html( $src['last_seen_at'] ); ?></td>
			<td>
				<?php if ( 'pending' === $src['approval_state'] || 'denied' === $src['approval_state'] ) : ?>
				<button type="button" class="button button-small wp-csp-approve-source" data-id="<?php echo esc_attr( $src['id'] ); ?>">
					<?php esc_html_e( 'Approve', 'wp-csp-automation' ); ?>
				</button>
				<?php endif; ?>
				<?php if ( 'pending' === $src['approval_state'] || 'approved' === $src['approval_state'] ) : ?>
				<button type="button" class="button button-small wp-csp-deny-source" data-id="<?php echo esc_attr( $src['id'] ); ?>">
					<?php esc_html_e( 'Deny', 'wp-csp-automation' ); ?>
				</button>
				<?php endif; ?>
			</td>
		</tr>
		<?php endforeach; ?>
		<?php if ( empty( $sources ) ) : ?>
		<tr><td colspan="7"><?php esc_html_e( 'No sources discovered yet. Run a scan to populate this table.', 'wp-csp-automation' ); ?></td></tr>
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
					)
				);
			?>
			<?php if ( $page_num > 1 ) : ?>
			<a class="button" href="<?php echo esc_url( add_query_arg( array_merge( $src_page_args, array( 'paged' => $page_num - 1 ) ), $base_url ) ); ?>">
				&laquo; <?php esc_html_e( 'Previous', 'wp-csp-automation' ); ?>
			</a>
			<?php endif; ?>
			<span style="margin:0 8px">
				<?php
				printf(
					/* translators: 1: current page number, 2: total pages */
					esc_html__( 'Page %1$d of %2$d', 'wp-csp-automation' ),
					$page_num,
					$src_pages
				);
				?>
			</span>
			<?php if ( $page_num < $src_pages ) : ?>
			<a class="button" href="<?php echo esc_url( add_query_arg( array_merge( $src_page_args, array( 'paged' => $page_num + 1 ) ), $base_url ) ); ?>">
				<?php esc_html_e( 'Next', 'wp-csp-automation' ); ?> &raquo;
			</a>
			<?php endif; ?>
		</div>
	</div>
	<?php endif; ?>

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
				<th><?php esc_html_e( 'Surface', 'wp-csp-automation' ); ?></th>
				<th><?php esc_html_e( 'Blocked URI', 'wp-csp-automation' ); ?></th>
				<th><?php esc_html_e( 'Directive', 'wp-csp-automation' ); ?></th>
				<th><?php esc_html_e( 'Occurrences', 'wp-csp-automation' ); ?></th>
				<th><?php esc_html_e( 'Last Seen', 'wp-csp-automation' ); ?></th>
				<th><?php esc_html_e( 'Disposition', 'wp-csp-automation' ); ?></th>
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
		<tr><td colspan="6"><?php esc_html_e( 'No violations recorded yet. Configure the report-uri on a live environment to start collecting.', 'wp-csp-automation' ); ?></td></tr>
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
									">&laquo; <?php esc_html_e( 'Previous', 'wp-csp-automation' ); ?></a>
			<?php endif; ?>
			<span style="margin:0 8px">
				<?php
				printf(
					/* translators: 1: current page number, 2: total pages */
					esc_html__( 'Page %1$d of %2$d', 'wp-csp-automation' ),
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
									"><?php esc_html_e( 'Next', 'wp-csp-automation' ); ?> &raquo;</a>
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
				<th><?php esc_html_e( 'Trigger', 'wp-csp-automation' ); ?></th>
				<th><?php esc_html_e( 'Status', 'wp-csp-automation' ); ?></th>
				<th><?php esc_html_e( 'Sources +/-', 'wp-csp-automation' ); ?></th>
				<th><?php esc_html_e( 'Hashes +/-', 'wp-csp-automation' ); ?></th>
				<th><?php esc_html_e( 'Policy Changed', 'wp-csp-automation' ); ?></th>
				<th><?php esc_html_e( 'Started', 'wp-csp-automation' ); ?></th>
				<th><?php esc_html_e( 'Duration', 'wp-csp-automation' ); ?></th>
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
			<td><?php echo $log['policy_changed'] ? esc_html__( 'Yes', 'wp-csp-automation' ) : '&mdash;'; ?></td>
			<td><?php echo esc_html( $log['started_at'] ); ?></td>
			<td><?php echo esc_html( $duration ); ?></td>
		</tr>
		<?php endforeach; ?>
		<?php if ( empty( $scan_logs ) ) : ?>
		<tr><td colspan="7"><?php esc_html_e( 'No scans run yet.', 'wp-csp-automation' ); ?></td></tr>
		<?php endif; ?>
		</tbody>
	</table>
	<?php endif; ?>

	</div><!-- .tab-content -->
</div><!-- .wp-csp-wrap -->
