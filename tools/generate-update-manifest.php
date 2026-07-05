<?php
/**
 * Generate the self-hosted plugin update manifest used by GitHub Pages.
 */

declare( strict_types=1 );

$options = getopt(
	'',
	array(
		'version:',
		'tag:',
		'download-url:',
		'release-url:',
		'published-at:',
		'output:',
	)
);

if ( false === $options || empty( $options['output'] ) ) {
	fwrite( STDERR, "Usage: php tools/generate-update-manifest.php --output docs/updates/wp-csp-automation.json [--tag v0.2.1] [--download-url URL]\n" );
	exit( 1 );
}

$plugin_file = dirname( __DIR__ ) . '/wp-csp-automation.php';
$plugin      = file_get_contents( $plugin_file );
if ( false === $plugin ) {
	fwrite( STDERR, "Unable to read {$plugin_file}\n" );
	exit( 1 );
}

$tag     = string_option( $options, 'tag' );
$version = string_option( $options, 'version' );
if ( '' === $version && '' !== $tag ) {
	$version = ltrim( $tag, 'vV' );
}
if ( '' === $version ) {
	$version = header_value( $plugin, 'Version' );
}

$download_url = string_option( $options, 'download-url' );
$release_url  = string_option( $options, 'release-url' );
if ( '' === $release_url && '' !== $tag ) {
	$release_url = 'https://github.com/vcns/wp-csp-automation/releases/tag/' . rawurlencode( $tag );
}

$published_at = string_option( $options, 'published-at' );
if ( '' === $published_at ) {
	$published_at = gmdate( 'c' );
}

$manifest = array(
	'slug'         => 'wp-csp-automation',
	'plugin'       => 'wp-csp-automation/wp-csp-automation.php',
	'name'         => 'WP CSP Automation Manager',
	'version'      => $version,
	'download_url' => $download_url,
	'homepage'     => 'https://github.com/vcns/wp-csp-automation',
	'release_url'  => $release_url,
	'requires'     => header_value( $plugin, 'Requires at least' ) ?: '6.4',
	'tested'       => '6.8',
	'requires_php' => header_value( $plugin, 'Requires PHP' ) ?: '8.1',
	'last_updated' => $published_at,
	'author'       => header_value( $plugin, 'Author' ) ?: 'Simon Jackson',
	'sections'     => array(
		'description' => 'Automates strict Content Security Policy generation, enforcement, and violation analysis for WordPress.',
		'changelog'   => '' !== $release_url ? 'See the release notes: ' . $release_url : 'Release notes are published with tagged GitHub Releases.',
	),
);

$json = json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
if ( false === $json ) {
	fwrite( STDERR, "Unable to encode update manifest\n" );
	exit( 1 );
}

$output = (string) $options['output'];
$dir    = dirname( $output );
if ( ! is_dir( $dir ) && ! mkdir( $dir, 0777, true ) ) {
	fwrite( STDERR, "Unable to create {$dir}\n" );
	exit( 1 );
}

file_put_contents( $output, $json . PHP_EOL );

function header_value( string $plugin, string $header ): string {
	if ( preg_match( '/^\s*\*\s+' . preg_quote( $header, '/' ) . ':\s*(.+)$/mi', $plugin, $matches ) ) {
		return trim( $matches[1] );
	}

	return '';
}

function string_option( array $options, string $key ): string {
	if ( ! isset( $options[ $key ] ) || false === $options[ $key ] ) {
		return '';
	}

	return trim( (string) $options[ $key ] );
}
