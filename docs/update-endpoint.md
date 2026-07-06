# Self-hosted Update Endpoint

## Purpose

WP CSP Automation Manager can be updated from a self-hosted release channel before it is listed in the WordPress.org plugin directory.

WordPress does not poll arbitrary JSON files for third-party plugins by default. The plugin registers a small update checker that reads the public GitHub Pages manifest and maps it into WordPress' native plugin update transient.

## Public endpoint

Current manifest URL:

- `https://vcns.github.io/wp-csp-automation/updates/wp-csp-automation.json`

The manifest describes the latest stable release and points WordPress at a public GitHub Pages ZIP asset.

Expected download URL shape:

- `https://vcns.github.io/wp-csp-automation/downloads/wp-csp-automation-vX.Y.Z.zip`

Key fields:

- `version` - latest stable plugin version without the leading `v`
- `download_url` - direct HTTPS URL for the public Pages-hosted release ZIP
- `requires` - minimum supported WordPress version
- `tested` - latest WordPress version tested for the release
- `requires_php` - minimum PHP version
- `sections` - plugin details modal content

## Runtime behaviour

The plugin hooks `site_transient_update_plugins` and `plugins_api`.

If the manifest version is newer than `WP_CSP_VERSION`, WordPress shows an update using the manifest `download_url`. If the installed version is current, the plugin adds a `no_update` entry so the native auto-update UI remains available for an externally hosted plugin.

The manifest URL is a PHP constant, `WP_CSP_UPDATE_MANIFEST_URL`, and can be overridden from `wp-config.php` before the plugin loads. It is intentionally not filterable, because another installed plugin should not be able to redirect update checks at runtime.

## Release pipeline

Tagged stable releases generate:

- `wp-csp-automation-vX.Y.Z.zip` attached to the GitHub Release
- `wp-csp-automation.json` attached to the GitHub Release
- `docs/downloads/wp-csp-automation-vX.Y.Z.zip` deployed through GitHub Pages
- `docs/updates/wp-csp-automation.json` deployed through GitHub Pages

Pre-release tags still create GitHub Release assets, but they do not update the Pages latest stable manifest or public Pages ZIP.
