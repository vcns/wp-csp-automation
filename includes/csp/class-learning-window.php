<?php
/**
 * Tracks the bounded window where report-endpoint learning may update inventory.
 */

declare( strict_types=1 );

namespace WP_CSP\CSP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Learning_Window {

	public const OPTION_LAST_CHANGE   = 'wp_csp_last_material_change_at';
	public const OPTION_WINDOW_HOURS  = 'wp_csp_learning_window_hours';
	public const DEFAULT_WINDOW_HOURS = 48;

	public function register(): void {
		add_action( 'save_post_post', array( $this, 'mark_post_change' ), 10, 3 );
		add_action( 'save_post_page', array( $this, 'mark_post_change' ), 10, 3 );
		add_action( 'activated_plugin', array( $this, 'mark_material_change' ) );
		add_action( 'deactivated_plugin', array( $this, 'mark_material_change' ) );
		add_action( 'upgrader_process_complete', array( $this, 'mark_plugin_upgrader_change' ), 10, 2 );
	}

	/**
	 * Marks page/post changes, excluding autosaves and revisions.
	 */
	public function mark_post_change( int $post_id, object $post, bool $update ): void {
		if ( function_exists( 'wp_is_post_revision' ) && wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( function_exists( 'wp_is_post_autosave' ) && wp_is_post_autosave( $post_id ) ) {
			return;
		}

		$this->mark_material_change();
	}

	/**
	 * Marks plugin installs/updates from the upgrader workflow.
	 */
	public function mark_plugin_upgrader_change( object $upgrader, array $hook_extra ): void {
		if ( 'plugin' !== ( $hook_extra['type'] ?? '' ) ) {
			return;
		}

		$this->mark_material_change();
	}

	public function mark_material_change(): void {
		update_option( self::OPTION_LAST_CHANGE, current_time( 'mysql', true ), false );
	}

	public function is_open(): bool {
		$locks_at = $this->locks_at_timestamp();
		return null === $locks_at || time() < $locks_at;
	}

	public function last_material_change_at(): string {
		$last_change = get_option( self::OPTION_LAST_CHANGE, '' );
		if ( is_string( $last_change ) && '' !== $last_change ) {
			return $last_change;
		}

		$last_change = current_time( 'mysql', true );
		update_option( self::OPTION_LAST_CHANGE, $last_change, false );

		return $last_change;
	}

	public function locks_at(): string {
		$locks_at = $this->locks_at_timestamp();
		return null !== $locks_at ? gmdate( 'Y-m-d H:i:s', $locks_at ) : '';
	}

	public function window_hours(): int {
		return max( 1, (int) get_option( self::OPTION_WINDOW_HOURS, self::DEFAULT_WINDOW_HOURS ) );
	}

	private function locks_at_timestamp(): ?int {
		$last_change = $this->last_material_change_at();
		$last_ts     = strtotime( $last_change . ' UTC' );

		if ( false === $last_ts ) {
			return null;
		}

		return $last_ts + ( $this->window_hours() * HOUR_IN_SECONDS );
	}
}
