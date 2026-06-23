<?php
/**
 * Admin view: Premium Entitlement page.
 * Shows current entitlement status and the "Buy Pro" Stripe checkout flow.
 * Rendered by Admin_UI::render_entitlement().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$plugin      = \WP_CSP\Plugin::instance();
$gate        = $plugin->gate;
$entitlement = $gate->get_entitlement();
$is_pro      = $gate->is_pro();
$products    = $plugin->config->get_products();

// Handle post-checkout redirect result.
$result     = isset( $_GET['csp_result'] ) ? sanitize_text_field( wp_unslash( $_GET['csp_result'] ) ) : '';
$session_id = isset( $_GET['session_id'] ) ? sanitize_text_field( wp_unslash( $_GET['session_id'] ) ) : '';
?>
<div class="wrap wp-csp-wrap">
	<h1><?php esc_html_e( 'VCNS CSP Manager – Premium', 'vcns-csp-manager' ); ?></h1>

	<?php if ( 'success' === $result ) : ?>
	<div class="notice notice-success">
		<p>
			<?php esc_html_e( 'Payment received. Your premium entitlement will be activated within a few seconds once the webhook is confirmed.', 'vcns-csp-manager' ); ?>
			<?php esc_html_e( 'Refresh this page in a moment to see your updated status.', 'vcns-csp-manager' ); ?>
		</p>
	</div>
	<?php elseif ( 'cancelled' === $result ) : ?>
	<div class="notice notice-warning">
		<p><?php esc_html_e( 'Checkout was cancelled. No payment was taken.', 'vcns-csp-manager' ); ?></p>
	</div>
	<?php endif; ?>

	<!-- ── Current entitlement status ────────────────────────────────────── -->
	<h2><?php esc_html_e( 'Current Status', 'vcns-csp-manager' ); ?></h2>
	<table class="widefat striped" style="max-width:700px">
		<tbody>
			<tr>
				<th><?php esc_html_e( 'Tier', 'vcns-csp-manager' ); ?></th>
				<td>
					<?php if ( $is_pro ) : ?>
						<span style="color:#2ea44f;font-weight:bold"><?php esc_html_e( 'Pro', 'vcns-csp-manager' ); ?></span>
					<?php else : ?>
						<?php esc_html_e( 'Free', 'vcns-csp-manager' ); ?>
					<?php endif; ?>
				</td>
			</tr>
			<?php if ( $entitlement ) : ?>
			<tr>
				<th><?php esc_html_e( 'Status', 'vcns-csp-manager' ); ?></th>
				<td><?php echo esc_html( ucfirst( $entitlement['status'] ) ); ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Granted', 'vcns-csp-manager' ); ?></th>
				<td><?php echo esc_html( $entitlement['granted_at'] ); ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Stripe Session', 'vcns-csp-manager' ); ?></th>
				<td><code><?php echo esc_html( $entitlement['stripe_session_id'] ); ?></code></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Last Validated', 'vcns-csp-manager' ); ?></th>
				<td><?php echo esc_html( isset( $entitlement['last_validated_at'] ) ? $entitlement['last_validated_at'] : '&mdash;' ); ?></td>
			</tr>
			<?php endif; ?>
			<tr>
				<th><?php esc_html_e( 'Site Identity', 'vcns-csp-manager' ); ?></th>
				<td><code><?php echo esc_html( $plugin->entitlements->get_site_identity() ); ?></code></td>
			</tr>
		</tbody>
	</table>

	<?php if ( ! $is_pro ) : ?>

	<!-- ── Buy Pro ────────────────────────────────────────────────────────── -->
	<h2 style="margin-top:2em"><?php esc_html_e( 'Upgrade to Pro', 'vcns-csp-manager' ); ?></h2>

		<?php if ( ! defined( 'WP_CSP_WORKER_URL' ) || empty( WP_CSP_WORKER_URL ) ) : ?>
	<div class="notice notice-error inline">
		<p>
			<?php esc_html_e( 'Licensing server URL is not configured. Define WP_CSP_WORKER_URL in wp-csp-automation.php.', 'vcns-csp-manager' ); ?>
		</p>
	</div>
	<?php else : ?>

	<div class="wp-csp-product-cards" style="display:flex;gap:20px;flex-wrap:wrap;margin-top:1em">

		<?php if ( ! empty( $products ) ) : ?>
			<?php foreach ( $products as $key => $product ) : ?>
		<div class="wp-csp-product-card" style="border:1px solid #ccd0d4;border-radius:4px;padding:20px;min-width:240px;max-width:340px;background:#fff">
			<h3 style="margin-top:0"><?php echo esc_html( isset( $product['name'] ) ? $product['name'] : $key ); ?></h3>
			<p class="wp-csp-price" style="font-size:2em;font-weight:bold;margin:0">
				<?php
				$amount   = isset( $product['amount'] ) ? ( (int) $product['amount'] / 100 ) : 0;
				$currency = strtoupper( isset( $product['currency'] ) ? $product['currency'] : 'USD' );
				echo esc_html( number_format( $amount, 2 ) . ' ' . $currency );
				?>
				<span style="font-size:0.4em;font-weight:normal"><?php esc_html_e( 'one-time', 'vcns-csp-manager' ); ?></span>
			</p>

				<?php if ( ! empty( $product['features'] ) && in_array( '*', $product['features'], true ) ) : ?>
			<ul style="margin:1em 0">
				<li><?php esc_html_e( 'All CSP surfaces (admin, login, API)', 'vcns-csp-manager' ); ?></li>
				<li><?php esc_html_e( 'Strict-Dynamic support', 'vcns-csp-manager' ); ?></li>
				<li><?php esc_html_e( 'Violation analytics export', 'vcns-csp-manager' ); ?></li>
				<li><?php esc_html_e( 'Promotion-gate enforcement', 'vcns-csp-manager' ); ?></li>
			</ul>
			<?php endif; ?>

			<button type="button"
				class="button button-primary wp-csp-buy-btn"
				data-product-key="<?php echo esc_attr( $key ); ?>"
				style="width:100%">
				<?php esc_html_e( 'Buy Now', 'vcns-csp-manager' ); ?>
			</button>
		</div>
		<?php endforeach; ?>
		<?php else : ?>
		<div class="notice notice-warning inline">
			<p>
				<?php
				printf(
					/* translators: %s: link to trigger a config refresh */
					esc_html__( 'Product catalog not yet loaded. %s to fetch the latest config from DNS.', 'vcns-csp-manager' ),
					'<a href="#" id="wp-csp-refresh-config">' . esc_html__( 'Refresh', 'vcns-csp-manager' ) . '</a>'
				);
				?>
			</p>
		</div>
		<?php endif; ?>
	</div>
	<p class="description" style="margin-top:1.5em">
		<?php esc_html_e( 'Clicking Buy Now redirects you to Stripe-hosted checkout. Your entitlement is activated after payment confirmation via webhook — not on redirect alone.', 'vcns-csp-manager' ); ?>
	</p>
	<?php endif; // Stripe key configured ?>

	<?php endif; // ! is_pro ?>

	<?php if ( $is_pro ) : ?>
	<!-- ── Pro features summary ───────────────────────────────────────────── -->
	<h2 style="margin-top:2em"><?php esc_html_e( 'Pro Features', 'vcns-csp-manager' ); ?></h2>
	<ul>
		<li><?php esc_html_e( 'Multi-surface CSP profiles (admin, login, API)', 'vcns-csp-manager' ); ?></li>
		<li><?php esc_html_e( 'strict-dynamic in script-src', 'vcns-csp-manager' ); ?></li>
		<li><?php esc_html_e( 'Violation analytics and CSV export', 'vcns-csp-manager' ); ?></li>
		<li><?php esc_html_e( 'Promotion gates: enforce mode gated behind approved inventory', 'vcns-csp-manager' ); ?></li>
		<li><?php esc_html_e( 'Priority email support', 'vcns-csp-manager' ); ?></li>
	</ul>
	<?php endif; ?>

</div><!-- .wp-csp-wrap -->
