<?php
/**
 * Admin view: Premium Entitlement page.
 * Shows current entitlement status and the "Buy Pro" Stripe checkout flow.
 * Rendered by Admin_UI::render_entitlement().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$plugin       = \WP_CSP\Plugin::instance();
$gate         = $plugin->gate;
$entitlement  = $gate->get_entitlement();
$is_pro       = $gate->is_pro();
$products     = $plugin->config->get_products();

// Handle post-checkout redirect result.
$result = isset( $_GET['csp_result'] ) ? sanitize_text_field( wp_unslash( $_GET['csp_result'] ) ) : '';
$session_id = isset( $_GET['session_id'] ) ? sanitize_text_field( wp_unslash( $_GET['session_id'] ) ) : '';
?>
<div class="wrap wp-csp-wrap">
	<h1><?php esc_html_e( 'WP CSP Automation – Premium', 'wp-csp-automation' ); ?></h1>

	<?php if ( 'success' === $result ) : ?>
	<div class="notice notice-success">
		<p>
			<?php esc_html_e( 'Payment received. Your premium entitlement will be activated within a few seconds once the webhook is confirmed.', 'wp-csp-automation' ); ?>
			<?php esc_html_e( 'Refresh this page in a moment to see your updated status.', 'wp-csp-automation' ); ?>
		</p>
	</div>
	<?php elseif ( 'cancelled' === $result ) : ?>
	<div class="notice notice-warning">
		<p><?php esc_html_e( 'Checkout was cancelled. No payment was taken.', 'wp-csp-automation' ); ?></p>
	</div>
	<?php endif; ?>

	<!-- ── Current entitlement status ────────────────────────────────────── -->
	<h2><?php esc_html_e( 'Current Status', 'wp-csp-automation' ); ?></h2>
	<table class="widefat striped" style="max-width:700px">
		<tbody>
			<tr>
				<th><?php esc_html_e( 'Tier', 'wp-csp-automation' ); ?></th>
				<td>
					<?php if ( $is_pro ) : ?>
						<span style="color:#2ea44f;font-weight:bold"><?php esc_html_e( 'Pro', 'wp-csp-automation' ); ?></span>
					<?php else : ?>
						<?php esc_html_e( 'Free', 'wp-csp-automation' ); ?>
					<?php endif; ?>
				</td>
			</tr>
			<?php if ( $entitlement ) : ?>
			<tr>
				<th><?php esc_html_e( 'Status', 'wp-csp-automation' ); ?></th>
				<td><?php echo esc_html( ucfirst( $entitlement['status'] ) ); ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Granted', 'wp-csp-automation' ); ?></th>
				<td><?php echo esc_html( $entitlement['granted_at'] ); ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Stripe Session', 'wp-csp-automation' ); ?></th>
				<td><code><?php echo esc_html( $entitlement['stripe_session_id'] ); ?></code></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Last Validated', 'wp-csp-automation' ); ?></th>
				<td><?php echo esc_html( $entitlement['last_validated_at'] ?? '—' ); ?></td>
			</tr>
			<?php endif; ?>
			<tr>
				<th><?php esc_html_e( 'Site Identity', 'wp-csp-automation' ); ?></th>
				<td><code><?php echo esc_html( $plugin->entitlements->get_site_identity() ); ?></code></td>
			</tr>
		</tbody>
	</table>

	<?php if ( ! $is_pro ) : ?>

	<!-- ── Buy Pro ────────────────────────────────────────────────────────── -->
	<h2 style="margin-top:2em"><?php esc_html_e( 'Upgrade to Pro', 'wp-csp-automation' ); ?></h2>

	<?php if ( empty( get_option( 'wp_csp_stripe_secret_key' ) ) ) : ?>
	<div class="notice notice-error inline">
		<p>
			<?php
			printf(
				/* translators: settings page link */
				esc_html__( 'Stripe API keys are not configured. Please add them on the %s before purchasing.', 'wp-csp-automation' ),
				'<a href="' . esc_url( admin_url( 'admin.php?page=wp-csp-settings' ) ) . '">' . esc_html__( 'Settings page', 'wp-csp-automation' ) . '</a>'
			);
			?>
		</p>
	</div>
	<?php else : ?>

	<div class="wp-csp-product-cards" style="display:flex;gap:20px;flex-wrap:wrap;margin-top:1em">

		<?php if ( ! empty( $products ) ) : ?>
		<?php foreach ( $products as $key => $product ) : ?>
		<div class="wp-csp-product-card" style="border:1px solid #ccd0d4;border-radius:4px;padding:20px;min-width:240px;max-width:340px;background:#fff">
			<h3 style="margin-top:0"><?php echo esc_html( $product['name'] ?? $key ); ?></h3>
			<p class="wp-csp-price" style="font-size:2em;font-weight:bold;margin:0">
				<?php
				$amount   = isset( $product['amount'] ) ? ( (int) $product['amount'] / 100 ) : 0;
				$currency = strtoupper( $product['currency'] ?? 'USD' );
				echo esc_html( number_format( $amount, 2 ) . ' ' . $currency );
				?>
				<span style="font-size:0.4em;font-weight:normal"><?php esc_html_e( 'one-time', 'wp-csp-automation' ); ?></span>
			</p>

			<?php if ( ! empty( $product['features'] ) && in_array( '*', $product['features'], true ) ) : ?>
			<ul style="margin:1em 0">
				<li><?php esc_html_e( 'All CSP surfaces (admin, login, API)', 'wp-csp-automation' ); ?></li>
				<li><?php esc_html_e( 'Strict-Dynamic support', 'wp-csp-automation' ); ?></li>
				<li><?php esc_html_e( 'Violation analytics export', 'wp-csp-automation' ); ?></li>
				<li><?php esc_html_e( 'Promotion-gate enforcement', 'wp-csp-automation' ); ?></li>
			</ul>
			<?php endif; ?>

			<button type="button"
				class="button button-primary wp-csp-buy-btn"
				data-product-key="<?php echo esc_attr( $key ); ?>"
				style="width:100%">
				<?php esc_html_e( 'Buy Now', 'wp-csp-automation' ); ?>
			</button>
		</div>
		<?php endforeach; ?>
		<?php else : ?>
		<div class="notice notice-warning inline">
			<p>
				<?php
				printf(
					esc_html__( 'Product catalog not yet loaded. %s to fetch the latest config from DNS.', 'wp-csp-automation' ),
					'<a href="#" id="wp-csp-refresh-config">' . esc_html__( 'Refresh', 'wp-csp-automation' ) . '</a>'
				);
				?>
			</p>
		</div>
		<?php endif; ?>
	</div>
	<p class="description" style="margin-top:1.5em">
		<?php esc_html_e( 'Clicking Buy Now redirects you to Stripe-hosted checkout. Your entitlement is activated after payment confirmation via webhook — not on redirect alone.', 'wp-csp-automation' ); ?>
	</p>
	<?php endif; // Stripe key configured ?>

	<?php endif; // ! is_pro ?>

	<?php if ( $is_pro ) : ?>
	<!-- ── Pro features summary ───────────────────────────────────────────── -->
	<h2 style="margin-top:2em"><?php esc_html_e( 'Pro Features', 'wp-csp-automation' ); ?></h2>
	<ul>
		<li><?php esc_html_e( 'Multi-surface CSP profiles (admin, login, API)', 'wp-csp-automation' ); ?></li>
		<li><?php esc_html_e( "strict-dynamic in script-src", 'wp-csp-automation' ); ?></li>
		<li><?php esc_html_e( 'Violation analytics and CSV export', 'wp-csp-automation' ); ?></li>
		<li><?php esc_html_e( 'Promotion gates: enforce mode gated behind approved inventory', 'wp-csp-automation' ); ?></li>
		<li><?php esc_html_e( 'Priority email support', 'wp-csp-automation' ); ?></li>
	</ul>
	<?php endif; ?>

</div><!-- .wp-csp-wrap -->
