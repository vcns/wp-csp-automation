# Release and Publishing

## Purpose

This document describes the internal release path from GitHub development work to a shipped WordPress.org plugin release.

## Branching model

Current intended flow:

- `feature/*` and `fix/*` branches target `development`
- `development` is the integration branch
- `release/*` branches are cut from `development` when stabilising a release
- `main` is the production and publishing branch

Operational rules:

- no direct commits to `development`
- no direct commits to `main`
- merge to protected branches by PR only
- production release artifacts come from `main` tags only

## Versioning

The plugin uses semantic versioning for public releases.

Update all release-facing version locations together:

- plugin header `Version` in `wp-csp-automation.php`
- `WP_CSP_VERSION` constant
- `readme.txt` stable tag
- `CHANGELOG.md`

If schema changes are included, also update:

- `WP_CSP_DB_VERSION`
- upgrade logic and schema docs

## Release workflow

### 1. Stabilise in development

- merge approved feature and fix PRs into `development`
- confirm CI is green
- confirm docs are up to date

### 2. Cut release branch

Example:

- `release/0.2.1`

On the release branch:

- bump versions
- finalise changelog
- update any release-facing readme details
- fix only release blockers

### 3. Validate release branch

Run or confirm:

- full CI pipeline
- manual smoke test on a clean WordPress install
- test-mode premium flow verification
- uninstall cleanup verification

### 4. Merge to main

- open PR from `release/*` to `main`
- require review and passing checks
- merge using the repository's protected-branch policy

### 5. Tag and publish

- create annotated tag, for example `v0.2.1`
- build the release artifact from the tag on `main`
- deploy to WordPress.org SVN from the tagged state

### 6. Back-merge

- merge `main` or the release branch back into `development`
- confirm branches are aligned before new feature work continues

## Packaging rules

The WordPress.org release zip should contain only the plugin directory contents required for runtime and compliance.

Include:

- plugin PHP files
- assets used by the plugin runtime
- `readme.txt`
- `LICENSE`
- translation files when added

Do not include:

- `.git`
- GitHub workflow files in the deploy artifact if your deploy process filters them out
- local test fixtures not needed at runtime
- development environment files
- secrets or example secrets

## WordPress.org repository model

WordPress.org plugin distribution uses SVN, even if GitHub is the development source of truth.

Typical mapping:

- GitHub `main` tag -> WordPress.org `tags/<version>`
- current stable release contents -> WordPress.org `trunk`
- assets such as banners and icons -> WordPress.org `assets` directory in SVN

## Suggested deployment automation

Use GitHub Actions or another CI runner to:

1. trigger on version tags from `main`
2. build a clean plugin artifact
3. check the artifact contents
4. commit the release to the WordPress.org SVN repo
5. tag the release in SVN

Repository workflow files now provide the baseline automation:

- `.github/workflows/ci.yml` for PHP lint, PHPCS, Semgrep, secret scanning, and package creation
- `.github/workflows/pr-branch-policy.yml` for source-branch enforcement on PRs into `development` and `main`
- `.github/workflows/codeql.yml` for GitHub-native static analysis
- `.github/workflows/dast.yml` for disposable-environment baseline DAST
- `.github/workflows/pages.yml` for publishing the public GitHub Pages help site from `docs/`
- `.github/workflows/release-package.yml` for release-candidate zip validation, GitHub Release ZIP publishing, and stable update manifest deployment
- `.github/workflows/wporg-deploy.yml` for tag-driven deployment to WordPress.org SVN

## Self-hosted update endpoint

The repository publishes a static update manifest for GitHub-distributed builds:

- `https://vcns.github.io/wp-updates/wp-csp-automation/wp-csp-automation.json`

WordPress does not automatically consume arbitrary update JSON for plugins outside the WordPress.org directory. The plugin registers an update checker that reads this manifest and maps it into the native plugin update transient.

Stable tag releases generate:

- `wp-csp-automation-vX.Y.Z.zip`
- `wp-csp-automation.json`
- `wp-csp-automation-latest.zip` in `vcns/wp-updates`
- `wp-csp-automation.json` in `vcns/wp-updates`

Pre-release tags attach ZIP and manifest assets to the GitHub Release, but they do not update the Pages "latest stable" manifest.

The normal documentation Pages workflow no longer publishes update metadata. The update feed lives in the separate public `vcns/wp-updates` repository so sister plugins can publish into their own subdirectories without overwriting each other.

The release workflow requires a repository or organization secret named `WP_UPDATES_TOKEN` with write access to `vcns/wp-updates`.

## Public docs site

The repository now includes a public GitHub Pages site in `docs/` intended for:

- install and rollout guidance
- operational help for CSP and Stripe setup
- release and publishing references
- support and security policy signposting

The Pages workflow publishes `docs/` when documentation-related changes reach `main`, including changes under:

- `docs/**`
- `readme.txt`
- `CHANGELOG.md`
- `SECURITY.md`

Published URL:

- `https://vcns.github.io/wp-csp-automation/`

Required GitHub repository secrets for WordPress.org deployment:

- environment variable `SVC_USERNAME`
- environment secret `SVN_PASSWORD`

Recommended GitHub environments:

- `development` for dry-run or non-production credential separation
- `production` for live WordPress.org deployment credentials

The deploy workflow maps `vars.SVC_USERNAME` into the action's required `SVN_USERNAME` environment variable at runtime.

## WordPress.org review readiness

Before first submission, verify:

- GPL-compatible licensing is clear
- `readme.txt` is valid and accurate
- no remote code execution or code download features exist
- external service usage is disclosed clearly in `readme.txt`
- premium upsell remains compliant with WordPress.org guidelines
- no obfuscated code is present
- no tracking without user consent is present

## Premium plugin directory caution

Because this plugin includes a paid upgrade path, review WordPress.org plugin guidelines carefully, especially around:

- upsell behaviour in wp-admin
- off-site service disclosures
- functionality split between free and paid versions
- branding and promotional copy

The free plugin should remain functional and useful on its own. Avoid making the WordPress.org listing feel like a thin paywall wrapper.

## Release checklist

Before publishing each version:

- confirm version numbers are aligned
- confirm README.md, readme.txt, SECURITY.md, and docs/architecture.md are mutually consistent and accurately reflect the behaviour of the version being released
- confirm branch protections and CI are active
- confirm remote config public key is correct for that release
- confirm no development keys or test endpoints remain in code or docs
- confirm `readme.txt` sections reflect actual shipped behaviour
- confirm `.wordpress-org/assets/` artwork is current and matches the listing
- confirm repository secrets `SVN_USERNAME` and `SVN_PASSWORD` exist before tagging
- package from a clean checkout or CI workspace
- test installation from the packaged zip
- confirm the shared Pages update manifest points at the intended stable ZIP after the tag workflow completes

## Rollback planning

If a bad release ships:

- identify whether the issue is documentation, runtime, or migration related
- prepare a fixed patch release immediately if practical
- if WordPress.org deployment must be rolled back, restore the prior stable tag and trunk contents in SVN
- document the incident in changelog notes or internal ops records as appropriate
