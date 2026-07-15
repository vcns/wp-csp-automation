<?php
/**
 * Self-hosted plugin update integration.
 *
 * WordPress does not automatically poll arbitrary update JSON endpoints for
 * plugins outside the WordPress.org directory, so this class maps the public
 * Pages manifest into the native update transient and plugin details modal.
 */

declare( strict_types=1 );

namespace WP_CSP\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Update_Checker {

	private const CACHE_KEY                 = 'wp_csp_update_manifest';
	private const CACHE_FAILURE_KEY         = 'wp_csp_update_manifest_failed';
	private const CACHE_TTL_SECONDS         = 12 * HOUR_IN_SECONDS;
	private const CACHE_FAILURE_TTL_SECONDS = HOUR_IN_SECONDS;
	private const SLUG                      = 'wp-csp-automation';

	private string $manifest_url;

	public function __construct( ?string $manifest_url = null ) {
		$this->manifest_url = null !== $manifest_url && '' !== $manifest_url ? $manifest_url : WP_CSP_UPDATE_MANIFEST_URL;
	}

	public function register(): void {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'filter_update_plugins' ) );
		add_filter( 'plugins_api', array( $this, 'filter_plugins_api' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( $this, 'clear_cached_manifest' ), 10, 2 );
		add_filter( 'auto_update_plugin', array( $this, 'filter_auto_update_plugin' ), 10, 2 );
	}

	public function filter_update_plugins( mixed $transient ): mixed {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$manifest = $this->get_manifest();
		if ( null === $manifest ) {
			return $transient;
		}

		$item = $this->manifest_to_update_item( $manifest );
		if ( null === $item ) {
			return $transient;
		}

		$plugin_file = plugin_basename( WP_CSP_FILE );

		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}
		if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
			$transient->no_update = array();
		}
		if ( isset( $transient->checked ) && is_array( $transient->checked ) ) {
			$transient->checked[ $plugin_file ] = WP_CSP_VERSION;
		}

		if ( version_compare( WP_CSP_VERSION, $item->new_version, '<' ) ) {
			$transient->response[ $plugin_file ] = $item;
			unset( $transient->no_update[ $plugin_file ] );
			return $transient;
		}

		$item->package                        = '';
		$transient->no_update[ $plugin_file ] = $item;
		unset( $transient->response[ $plugin_file ] );

		return $transient;
	}

	public function filter_plugins_api( mixed $result, string $action, mixed $args ): mixed {
		if ( 'plugin_information' !== $action || self::SLUG !== $this->get_requested_slug( $args ) ) {
			return $result;
		}

		$manifest = $this->get_manifest();
		if ( null === $manifest ) {
			return $result;
		}

		return (object) array(
			'name'          => $manifest['name'],
			'slug'          => self::SLUG,
			'version'       => $manifest['version'],
			'author'        => $manifest['author'],
			'homepage'      => $manifest['homepage'],
			'requires'      => $manifest['requires'],
			'tested'        => $manifest['tested'],
			'requires_php'  => $manifest['requires_php'],
			'last_updated'  => $manifest['last_updated'],
			'sections'      => $manifest['sections'],
			'download_link' => $manifest['download_url'],
		);
	}

	public function get_manifest(): ?array {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		if ( false !== get_transient( self::CACHE_FAILURE_KEY ) ) {
			return null;
		}

		$manifest = $this->fetch_manifest();
		if ( null !== $manifest ) {
			set_transient( self::CACHE_KEY, $manifest, self::CACHE_TTL_SECONDS );
			delete_transient( self::CACHE_FAILURE_KEY );
		} else {
			set_transient( self::CACHE_FAILURE_KEY, '1', self::CACHE_FAILURE_TTL_SECONDS );
		}

		return $manifest;
	}

	public function clear_cached_manifest( mixed $upgrader = null, mixed $hook_extra = null ): void {
		$plugin_file = plugin_basename( WP_CSP_FILE );

		if ( is_array( $hook_extra ) ) {
			$type   = $this->string_value( $hook_extra['type'] ?? '' );
			$action = $this->string_value( $hook_extra['action'] ?? '' );

			if ( 'plugin' !== $type || 'update' !== $action ) {
				return;
			}

			// WordPress passes `plugin` (string) for single-plugin upgrades and
			// `plugins` (array) for bulk upgrades.  Bail early unless this plugin
			// was among those updated.
			$single  = $this->string_value( $hook_extra['plugin'] ?? '' );
			$plugins = $hook_extra['plugins'] ?? array();

			if ( '' !== $single ) {
				if ( $plugin_file !== $single ) {
					return;
				}
			} elseif ( is_array( $plugins ) && ! in_array( $plugin_file, $plugins, true ) ) {
				return;
			}
		}

		delete_transient( self::CACHE_KEY );
		delete_transient( self::CACHE_FAILURE_KEY );
	}

	public function filter_auto_update_plugin( mixed $update, mixed $item ): mixed {
		if ( ! defined( 'WP_CSP_DISABLE_AUTO_UPDATE' ) || true !== (bool) constant( 'WP_CSP_DISABLE_AUTO_UPDATE' ) ) {
			return $update;
		}

		$plugin_file = plugin_basename( WP_CSP_FILE );
		$item_plugin = '';
		$item_slug   = '';

		if ( is_object( $item ) ) {
			$item_plugin = isset( $item->plugin ) && is_scalar( $item->plugin ) ? (string) $item->plugin : '';
			$item_slug   = isset( $item->slug ) && is_scalar( $item->slug ) ? (string) $item->slug : '';
		} elseif ( is_array( $item ) ) {
			$item_plugin = isset( $item['plugin'] ) && is_scalar( $item['plugin'] ) ? (string) $item['plugin'] : '';
			$item_slug   = isset( $item['slug'] ) && is_scalar( $item['slug'] ) ? (string) $item['slug'] : '';
		}

		if ( $plugin_file === $item_plugin || self::SLUG === $item_slug ) {
			return false;
		}

		return $update;
	}

	protected function fetch_manifest(): ?array {
		if ( '' === $this->manifest_url || ! $this->is_https_url( $this->manifest_url ) ) {
			return null;
		}

		$response = wp_remote_get(
			$this->manifest_url,
			array(
				'timeout'    => 8,
				'user-agent' => 'WP-CSP-Update-Checker/' . WP_CSP_VERSION,
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $decoded ) ) {
			return null;
		}

		return $this->normalise_manifest( $decoded );
	}

	private function normalise_manifest( array $manifest ): ?array {
		$version      = $this->string_value( $manifest['version'] ?? '' );
		$download_url = $this->string_value( $manifest['download_url'] ?? '' );
		$homepage     = $this->string_value( $manifest['homepage'] ?? 'https://github.com/vcns/wp-csp-automation' );

		if ( self::SLUG !== $this->string_value( $manifest['slug'] ?? '' ) ) {
			return null;
		}
		if ( '' === $version || ! preg_match( '/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $version ) ) {
			return null;
		}
		if ( '' !== $download_url && ! $this->is_https_url( $download_url ) ) {
			return null;
		}
		if ( '' !== $homepage && ! $this->is_https_url( $homepage ) ) {
			return null;
		}
		if ( version_compare( WP_CSP_VERSION, $version, '<' ) && '' === $download_url ) {
			return null;
		}

		return array(
			'name'         => $this->string_value( $manifest['name'] ?? 'CSP Automation Manager' ),
			'slug'         => self::SLUG,
			'plugin'       => $this->string_value( $manifest['plugin'] ?? plugin_basename( WP_CSP_FILE ) ),
			'version'      => $version,
			'download_url' => $download_url,
			'homepage'     => $homepage,
			'requires'     => $this->string_value( $manifest['requires'] ?? '6.4' ),
			'tested'       => $this->string_value( $manifest['tested'] ?? '' ),
			'requires_php' => $this->string_value( $manifest['requires_php'] ?? '8.1' ),
			'last_updated' => $this->string_value( $manifest['last_updated'] ?? '' ),
			'author'       => $this->string_value( $manifest['author'] ?? 'VCNS Tech Ltd' ),
			'sections'     => $this->normalise_sections( $manifest['sections'] ?? array() ),
		);
	}

	private function manifest_to_update_item( array $manifest ): ?object {
		return (object) array(
			'id'            => $this->manifest_url,
			'slug'          => self::SLUG,
			'plugin'        => plugin_basename( WP_CSP_FILE ),
			'new_version'   => $manifest['version'],
			'url'           => $manifest['homepage'],
			'package'       => $manifest['download_url'],
			'icons'         => array(),
			'banners'       => array(),
			'banners_rtl'   => array(),
			'tested'        => $manifest['tested'],
			'requires'      => $manifest['requires'],
			'requires_php'  => $manifest['requires_php'],
			'compatibility' => new \stdClass(),
		);
	}

	private function normalise_sections( mixed $sections ): array {
		if ( ! is_array( $sections ) ) {
			return array();
		}

		$normalised = array();
		foreach ( $sections as $key => $value ) {
			if ( is_string( $key ) && is_scalar( $value ) ) {
				$normalised[ $key ] = (string) $value;
			}
		}

		return $normalised;
	}

	private function get_requested_slug( mixed $args ): string {
		if ( is_object( $args ) && isset( $args->slug ) ) {
			return (string) $args->slug;
		}
		if ( is_array( $args ) && isset( $args['slug'] ) ) {
			return (string) $args['slug'];
		}

		return '';
	}

	private function is_https_url( string $url ): bool {
		return 'https' === wp_parse_url( $url, PHP_URL_SCHEME );
	}

	private function string_value( mixed $value ): string {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}
}
