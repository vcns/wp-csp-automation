# Self-hosted Update Endpoint

## Purpose

WP CSP Automation Manager can be updated from a self-hosted release channel before it is listed in the WordPress.org plugin directory.

WordPress does not poll arbitrary JSON files for third-party plugins by default. The plugin registers a small update checker that reads the public GitHub Pages manifest and maps it into WordPress' native plugin update transient.

## Public endpoint

Current manifest URL:

- `https://vcns.github.io/wp-updates/wp-csp-automation/wp-csp-automation.json`

<<<<<<< HEAD
The manifest describes the latest stable release and points WordPress at the immutable GitHub Release ZIP asset.
=======
The manifest describes the latest stable release and points WordPress at a public GitHub Pages ZIP asset.

Expected download URL shape:

<<<<<<< HEAD
- `https://vcns.github.io/wp-updates/wp-csp-automation/wp-csp-automation-latest.zip`
=======
- `https://vcns.github.io/wp-csp-automation/downloads/wp-csp-automation-vX.Y.Z.zip`
>>>>>>> origin/development
>>>>>>> origin/development

Key fields:

- `version` - latest stable plugin version without the leading `v`
<<<<<<< HEAD
- `download_url` - direct HTTPS URL for the release ZIP
=======
- `download_url` - direct HTTPS URL for the public Pages-hosted release ZIP
>>>>>>> origin/development
- `requires` - minimum supported WordPress version
- `tested` - latest WordPress version tested for the release
- `requires_php` - minimum PHP version
- `sections` - plugin details modal content

## Runtime behaviour

The plugin hooks `pre_set_site_transient_update_plugins`, `plugins_api`, `upgrader_process_complete`, and `auto_update_plugin`.

If the manifest version is newer than `WP_CSP_VERSION`, WordPress shows an update using the manifest `download_url`. If the installed version is current, the plugin adds a `no_update` entry so the native auto-update UI remains available for an externally hosted plugin.

The manifest URL is a PHP constant, `WP_CSP_UPDATE_MANIFEST_URL`, and can be overridden from `wp-config.php` before the plugin loads. It is intentionally not filterable, because another installed plugin should not be able to redirect update checks at runtime.

Successful metadata is cached for 12 hours. Failed fetches are cached for 1 hour so a temporary Pages or network failure does not slow every admin request. Completing a plugin update clears both caches.

Background auto-updates can be blocked on staging or local installs without hiding manual update availability:

```php
define( 'WP_CSP_DISABLE_AUTO_UPDATE', true );
```

## Release pipeline

Tagged stable releases generate:

- `wp-csp-automation-vX.Y.Z.zip` attached to the GitHub Release
- `wp-csp-automation.json` attached to the GitHub Release
<<<<<<< HEAD
- `wp-csp-automation-latest.zip` deployed to `vcns/wp-updates` on the `gh-pages` branch
- `wp-csp-automation.json` deployed to `vcns/wp-updates` on the `gh-pages` branch

Pre-release tags still create GitHub Release assets, but they do not update the Pages latest stable manifest or public Pages ZIP.

The source repository must define `WP_UPDATES_TOKEN` as a repository or organization secret with write access to `vcns/wp-updates`.
=======
<<<<<<< HEAD
- `docs/updates/wp-csp-automation.json` deployed through GitHub Pages

Pre-release tags still create GitHub Release assets, but they do not update the Pages "latest stable" manifest.
=======
- `docs/downloads/wp-csp-automation-vX.Y.Z.zip` deployed through GitHub Pages
- `docs/updates/wp-csp-automation.json` deployed through GitHub Pages

Pre-release tags still create GitHub Release assets, but they do not update the Pages latest stable manifest or public Pages ZIP.
>>>>>>> origin/development
>>>>>>> origin/development
