<?php
/**
 * Admin view: Settings page.
 * Rendered by Admin_UI::render_settings().
 * All output is escaped; form submission handled by WordPress Settings API.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$config_domain   = get_option( 'wp_csp_config_dns_domain', '' );
$config_version  = get_option( 'wp_csp_config_version', __( 'Not yet fetched', 'wp-csp-automation' ) );
$config_fetched  = get_option( 'wp_csp_config_last_fetched', '' );
$webhook_url     = rest_url( 'csp-manager/v1/stripe-webhook' );
$learning_window = new \WP_CSP\CSP\Learning_Window();
$learning_status = $learning_window->is_open() ? __( 'Open', 'wp-csp-automation' ) : __( 'Locked', 'wp-csp-automation' );
?>
<div class="wrap wp-csp-wrap">
	<h1><?php esc_html_e( 'WP CSP Automation – Settings', 'wp-csp-automation' ); ?></h1>

	<form method="post" action="options.php">
		<?php settings_fields( 'wp_csp_settings_group' ); ?>

		<!-- ── Payment / licensing ──────────────────────────────────────── -->
		<h2 class="title"><?php esc_html_e( 'Payments &amp; Licensing', 'wp-csp-automation' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Payment processing is handled entirely by the remote licensing server. Your Stripe API keys never touch this WordPress installation — they live as encrypted secrets on the Cloudflare Worker. There is nothing to configure here.', 'wp-csp-automation' ); ?>
		</p>

		<!-- ── Remote product config ────────────────────────────────────── -->
		<h2 class="title"><?php esc_html_e( 'Remote Product Configuration (DNS)', 'wp-csp-automation' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'The plugin fetches product tier definitions and Stripe price IDs from a signed JSON document discovered via DNS. Non-secret data only.', 'wp-csp-automation' ); ?>
		</p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="wp_csp_config_dns_domain"><?php esc_html_e( 'Config DNS Record', 'wp-csp-automation' ); ?></label></th>
				<td>
					<input type="text" id="wp_csp_config_dns_domain" name="wp_csp_config_dns_domain"
						value="<?php echo esc_attr( $config_domain ); ?>"
						class="regular-text" />
					<p class="description"><?php esc_html_e( 'Default: _csp-config.wp-csp-automation.dev — leave unchanged unless self-hosting config.', 'wp-csp-automation' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="wp_csp_config_fallback_url"><?php esc_html_e( 'Fallback Config URL', 'wp-csp-automation' ); ?></label></th>
				<td>
					<input type="url" id="wp_csp_config_fallback_url" name="wp_csp_config_fallback_url"
						value="<?php echo esc_attr( get_option( 'wp_csp_config_fallback_url', '' ) ); ?>"
						class="regular-text" placeholder="https://example.com/csp-config.json" />
					<p class="description">
						<?php esc_html_e( 'Optional. A direct HTTPS URL to the signed config JSON document. Used when DNS TXT lookup fails or dns_get_record() is unavailable on this host (common on some shared hosting environments). Must start with https://. The same Ed25519 signature verification applies regardless of which resolution path is used. Leave empty to rely on DNS only.', 'wp-csp-automation' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Config Status', 'wp-csp-automation' ); ?></th>
				<td>
					<p>
						<?php
						printf(
							/* translators: 1: config version, 2: last fetched time */
							esc_html__( 'Version: %1$s — Last fetched: %2$s', 'wp-csp-automation' ),
							'<strong>' . esc_html( $config_version ) . '</strong>',
							$config_fetched ? esc_html( $config_fetched ) : esc_html__( 'Never', 'wp-csp-automation' )
						);
						?>
					</p>
					<button type="button" id="wp-csp-refresh-config" class="button">
						<?php esc_html_e( 'Refresh Now', 'wp-csp-automation' ); ?>
					</button>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="wp_csp_config_cache_ttl"><?php esc_html_e( 'Cache TTL (seconds)', 'wp-csp-automation' ); ?></label></th>
				<td>
					<input type="number" id="wp_csp_config_cache_ttl" name="wp_csp_config_cache_ttl"
						value="<?php echo esc_attr( get_option( 'wp_csp_config_cache_ttl', 3600 ) ); ?>"
						min="300" max="86400" class="small-text" />
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="wp_csp_config_grace_ttl"><?php esc_html_e( 'Grace Window (seconds)', 'wp-csp-automation' ); ?></label></th>
				<td>
					<input type="number" id="wp_csp_config_grace_ttl" name="wp_csp_config_grace_ttl"
						value="<?php echo esc_attr( get_option( 'wp_csp_config_grace_ttl', 86400 ) ); ?>"
						min="3600" max="604800" class="small-text" />
					<p class="description"><?php esc_html_e( 'How long to serve a stale config if the remote server is unreachable.', 'wp-csp-automation' ); ?></p>
				</td>
			</tr>
		</table>

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

		<!-- ── Entitlement settings ──────────────────────────────────────── -->
		<h2 class="title"><?php esc_html_e( 'Entitlement', 'wp-csp-automation' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="wp_csp_entitlement_grace_hours"><?php esc_html_e( 'Grace Period (hours)', 'wp-csp-automation' ); ?></label></th>
				<td>
					<input type="number" id="wp_csp_entitlement_grace_hours" name="wp_csp_entitlement_grace_hours"
						value="<?php echo esc_attr( get_option( 'wp_csp_entitlement_grace_hours', 72 ) ); ?>"
						min="1" max="720" class="small-text" />
					<p class="description"><?php esc_html_e( 'How long to continue serving premium features if Stripe revalidation fails.', 'wp-csp-automation' ); ?></p>
				</td>
			</tr>
		</table>

		<?php submit_button(); ?>
	</form>
</div>
