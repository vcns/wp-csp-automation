<?php
/**
 * Central plugin orchestrator.
 * Bootstraps every module, registers REST routes, and wires WordPress hooks.
 */

declare( strict_types=1 );

namespace WP_CSP;

use WP_CSP\Admin\Admin_UI;
use WP_CSP\CSP\Conflict_Detector;
use WP_CSP\CSP\Hash_Manager;
use WP_CSP\CSP\Nonce_Manager;
use WP_CSP\CSP\Policy_Builder;
use WP_CSP\CSP\Scheduler;
use WP_CSP\CSP\Violation_Reporter;
use WP_CSP\Modules\Audit_Log;
use WP_CSP\Modules\Config_Resolver;
use WP_CSP\Modules\Entitlement_Store;
use WP_CSP\Modules\Feature_Gate;
use WP_CSP\Modules\Checkout_Service;
use WP_CSP\Modules\Webhook_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {

	private static ?Plugin $instance = null;

	// Shared module instances (read by Admin_UI and other consumers).
	public Config_Resolver $config;
	public Entitlement_Store $entitlements;
	public Feature_Gate $gate;
	public Audit_Log $audit;
	public Nonce_Manager $nonce_manager;
	public Policy_Builder $policy_builder;

	/**
	 * Hash manager exposed publicly so Scheduler can retrieve captured hashes
	 * after a request-time capture pass.
	 */
	public Hash_Manager $hash_manager;

	private function __construct() {}

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function init(): void {
		$this->load_textdomain();
		$this->maybe_upgrade_db();
		$this->bootstrap();
	}

	// ── Text domain ───────────────────────────────────────────────────────────

	private function load_textdomain(): void {
		load_plugin_textdomain(
			'wp-csp-automation',
			false,
			dirname( plugin_basename( WP_CSP_FILE ) ) . '/languages'
		);
	}

	// ── DB migration gate ─────────────────────────────────────────────────────

	private function maybe_upgrade_db(): void {
		$installed = (int) get_option( 'wp_csp_db_version', 0 );
		if ( $installed < (int) WP_CSP_DB_VERSION ) {
			Activator::activate();
		}
	}

	// ── Module bootstrap ──────────────────────────────────────────────────────

	private function bootstrap(): void {
		// Shared service layer.
		$this->audit          = new Audit_Log();
		$this->config         = new Config_Resolver( $this->audit );
		$this->entitlements   = new Entitlement_Store( $this->audit );
		$this->gate           = new Feature_Gate( $this->entitlements, $this->config );
		$this->nonce_manager  = new Nonce_Manager( $this->gate );
		$this->policy_builder = new Policy_Builder( $this->gate );

		// Hash manager: instantiated here so Scheduler can read captured_hashes
		// after the request-time buffer pass, and so the public property is
		// always available to other modules.
		$this->hash_manager = new Hash_Manager( $this->audit, $this->gate );

		// Register CSP header emission on all request types.
		$this->nonce_manager->register();
		$this->policy_builder->register();

		// Register output-buffering hooks to capture inline blocks for hashing.
		// Must be registered after nonce_manager so nonce tags are already
		// stamped before the buffer captures them (and can be skipped).
		$this->hash_manager->register();

		// REST API: webhook + violation reporting endpoint.
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		// WP Cron: daily policy rescan.
		( new Scheduler( $this->audit ) )->register();

		// Conflict detection runs once per admin pageload.
		if ( is_admin() ) {
			( new Conflict_Detector( $this->audit ) )->register();
		}

		// Stripe checkout + webhook.
		$checkout = new Checkout_Service( $this->config, $this->audit );
		new Webhook_Controller( $this->entitlements, $this->audit, $checkout );

		// Admin UI.
		if ( is_admin() ) {
			( new Admin_UI( $this ) )->register();
		}
	}

	// ── REST routes ───────────────────────────────────────────────────────────

	public function register_rest_routes(): void {
		// Stripe webhook – public endpoint, verified by HMAC signature inside handler.
		register_rest_route(
			'csp-manager/v1',
			'/stripe-webhook',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( new Webhook_Controller( $this->entitlements, $this->audit, new Checkout_Service( $this->config, $this->audit ) ), 'handle' ),
				'permission_callback' => '__return_true',
			)
		);

		// CSP violation report – public, from browsers.
		register_rest_route(
			'csp-manager/v1',
			'/report',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( new Violation_Reporter( $this->audit ), 'handle' ),
				'permission_callback' => '__return_true',
			)
		);
	}
}
