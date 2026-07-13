<?php
/**
 * Release metadata consistency tests.
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

class VersionConsistencyTest extends TestCase {

	public function test_release_version_metadata_is_consistent(): void {
		$root = dirname( __DIR__, 2 );

		$plugin_version   = $this->extract_plugin_header_version( $root . '/wp-csp-automation.php' );
		$constant_version = $this->extract_plugin_constant_version( $root . '/wp-csp-automation.php' );
		$stable_tag       = $this->extract_readme_stable_tag( $root . '/readme.txt' );
		$changelog        = $this->extract_latest_changelog_release( $root . '/CHANGELOG.md' );
		$manifest_version = $this->extract_manifest_version( $root . '/docs/updates/wp-csp-automation.json' );

		$this->assertMatchesRegularExpression( '/^\d+\.\d+\.\d+$/', $plugin_version, 'Plugin version must be a valid semver string.' );
		$this->assertSame( $plugin_version, $constant_version );
		$this->assertSame( $plugin_version, $stable_tag );
		$this->assertSame( $plugin_version, $changelog );
		$this->assertSame( $plugin_version, $manifest_version );
	}

	private function extract_plugin_header_version( string $file ): string {
		$contents = $this->read_file( $file );

		$this->assertMatchesRegularExpression( '/^\s*\*\s+Version:\s*([^\r\n]+)/mi', $contents );
		preg_match( '/^\s*\*\s+Version:\s*([^\r\n]+)/mi', $contents, $matches );

		return trim( $matches[1] );
	}

	private function extract_plugin_constant_version( string $file ): string {
		$contents = $this->read_file( $file );

		$this->assertMatchesRegularExpression( "/define\(\s*'WP_CSP_VERSION'\s*,\s*'([^']+)'\s*\)/", $contents );
		preg_match( "/define\(\s*'WP_CSP_VERSION'\s*,\s*'([^']+)'\s*\)/", $contents, $matches );

		return trim( $matches[1] );
	}

	private function extract_readme_stable_tag( string $file ): string {
		$contents = $this->read_file( $file );

		$this->assertMatchesRegularExpression( '/^Stable tag:\s*([^\r\n]+)/mi', $contents );
		preg_match( '/^Stable tag:\s*([^\r\n]+)/mi', $contents, $matches );

		return trim( $matches[1] );
	}

	private function extract_latest_changelog_release( string $file ): string {
		$contents = $this->read_file( $file );

		$this->assertMatchesRegularExpression( '/^## \[([0-9]+\.[0-9]+\.[0-9]+)\]/m', $contents );
		preg_match( '/^## \[([0-9]+\.[0-9]+\.[0-9]+)\]/m', $contents, $matches );

		return trim( $matches[1] );
	}

	private function extract_manifest_version( string $file ): string {
		$manifest = json_decode( $this->read_file( $file ), true, flags: JSON_THROW_ON_ERROR );

		$this->assertIsArray( $manifest );
		$this->assertArrayHasKey( 'version', $manifest );

		return (string) $manifest['version'];
	}

	private function read_file( string $file ): string {
		$contents = file_get_contents( $file );

		$this->assertIsString( $contents, "Expected {$file} to be readable." );

		return $contents;
	}
}
