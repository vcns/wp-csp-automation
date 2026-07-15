<?php
/**
 * Admin view: Settings page.
 * Rendered by Admin_UI::render_settings().
 * All output is escaped; form submission handled by WordPress Settings API.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$learning_window = new \WP_CSP\CSP\Learning_Window();
$learning_status = $learning_window->is_open() ? __( 'Open', 'wp-csp-automation' ) : __( 'Locked', 'wp-csp-automation' );
?>
<div class="wrap wp-csp-wrap">
	<h1><?php esc_html_e( 'CSP Automation Manager Settings', 'wp-csp-automation' ); ?></h1>
	<form method="post" action="options.php">
		<?php settings_fields( 'wp_csp_settings_group' ); ?>


		<!-- ── Promotion gates ───────────────────────────────────────────── -->
		<h2 class="title"><?php esc_html_e( 'Promotion Gates', 'wp-csp-automation' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'These settings control the conditions that must be met before a surface can be promoted from report-only to enforce mode.', 'wp-csp-automation' ); ?>
		</p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="wp_csp_enforce_gate_violation_window">
						<?php esc_html_e( 'Violation-free window (hours)', 'wp-csp-automation' ); ?>
					</label>
				</th>
				<td>
					<input type="number" id="wp_csp_enforce_gate_violation_window"
						name="wp_csp_enforce_gate_violation_window"
						value="<?php echo esc_attr( get_option( 'wp_csp_enforce_gate_violation_window', 24 ) ); ?>"
						min="1" max="720" class="small-text" />
					<p class="description">
						<?php esc_html_e( 'Number of hours without any CSP violations required before a surface can be promoted to enforce mode. Default: 24. Increase this for production sites to ensure stability.', 'wp-csp-automation' ); ?>
					</p>
				</td>
			</tr>
		</table>

		<h2 class="title"><?php esc_html_e( 'Report Endpoint Learning', 'wp-csp-automation' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Validated CSP reports can add pending source candidates while the site is inside the material-change learning window.', 'wp-csp-automation' ); ?>
		</p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="wp_csp_learning_window_hours"><?php esc_html_e( 'Learning window (hours)', 'wp-csp-automation' ); ?></label></th>
				<td>
					<input type="number" id="wp_csp_learning_window_hours" name="wp_csp_learning_window_hours"
						value="<?php echo esc_attr( get_option( 'wp_csp_learning_window_hours', 48 ) ); ?>"
						min="1" max="720" class="small-text" />
					<p class="description">
						<?php esc_html_e( 'Default: 48. The report endpoint stops creating or updating source candidates after this many hours from the latest page, post, or plugin change.', 'wp-csp-automation' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Learning status', 'wp-csp-automation' ); ?></th>
				<td>
					<p>
						<?php
						printf(
							/* translators: 1: status, 2: last material change time, 3: lock time */
							esc_html__( '%1$s. Last material change: %2$s. Locks at: %3$s UTC.', 'wp-csp-automation' ),
							'<strong>' . esc_html( $learning_status ) . '</strong>',
							esc_html( $learning_window->last_material_change_at() ),
							esc_html( $learning_window->locks_at() )
						);
						?>
					</p>
				</td>
			</tr>
		</table>

		<!-- ── Scan schedule ─────────────────────────────────────────────── -->
		<h2 class="title"><?php esc_html_e( 'Scan Schedule', 'wp-csp-automation' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="wp_csp_cron_hour"><?php esc_html_e( 'Daily Scan Hour (0–23, UTC)', 'wp-csp-automation' ); ?></label></th>
				<td>
					<input type="number" id="wp_csp_cron_hour" name="wp_csp_cron_hour"
						value="<?php echo esc_attr( get_option( 'wp_csp_cron_hour', 2 ) ); ?>"
						min="0" max="23" class="small-text" />
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="wp_csp_notify_email"><?php esc_html_e( 'Notification Email', 'wp-csp-automation' ); ?></label></th>
				<td>
					<input type="email" id="wp_csp_notify_email" name="wp_csp_notify_email"
						value="<?php echo esc_attr( get_option( 'wp_csp_notify_email', get_option( 'admin_email' ) ) ); ?>"
						class="regular-text" />
					<p class="description"><?php esc_html_e( 'Receive an email when the policy changes after a scheduled scan.', 'wp-csp-automation' ); ?></p>
				</td>
			</tr>
		</table>


		<?php submit_button(); ?>
	</form>
</div>
