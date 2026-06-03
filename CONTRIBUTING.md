# Contributing

## Scope

This repository contains a WordPress plugin that automates Content Security Policy rollout and premium entitlement handling. Contributions must preserve WordPress coding compatibility, strict CSP semantics, and the plugin's security model.

## Ground rules

- Target WordPress 6.4+ and PHP 8.1+.
- Preserve GPL-2.0-or-later compatibility for all contributed code and assets.
- Do not introduce telemetry, remote kill switches, or background data collection.
- Do not add secrets, live Stripe credentials, private keys, or production endpoints to the repository.
- Keep premium gating local to the entitlement database; do not add per-request remote licence validation.
- Use the WordPress HTTP API for outbound HTTP and the WordPress Settings, REST, Cron, and capability APIs where applicable.

## Branching and changes

- Work in a dedicated feature or fix branch.
- Keep each change focused on one behaviour or concern.
- Avoid unrelated refactors in the same patch.
- When changing database schema, update activation logic and document the migration in the changelog.
- When changing admin behaviour, update the user-facing docs and screenshots list in `readme.txt` if needed.

## Coding expectations

- Use `declare(strict_types=1);` in PHP files.
- Follow the existing namespace and autoload structure under `WP_CSP\`.
- Prefer small methods with single-purpose responsibilities.
- Escape output late and validate input early.
- Use `hash_equals()` for secret comparisons.
- Do not depend on the Stripe PHP SDK unless there is a clear operational reason and it is explicitly approved.

## Test checklist before opening a PR

- Activate the plugin on a clean WordPress 6.4+ install.
- Confirm the plugin creates all custom tables on activation.
- Confirm the admin pages load without notices.
- Run at least one scan and verify source inventory rows are created.
- Verify report-only mode emits the expected header.
- Verify enforce mode remains blocked when no approved sources or hashes exist.
- Verify webhook handling is idempotent for duplicate Stripe events.
- Verify uninstall removes custom tables and `wp_csp_*` options.

## Pull requests

Each PR should include:

- A short problem statement.
- The chosen implementation approach.
- Any schema, API, or admin UI impacts.
- Manual validation steps.
- Screenshots for admin UI changes.

## Documentation duties

Update the relevant docs whenever you change:

- Plugin behaviour visible to site owners.
- Stripe setup or payment flow.
- DNS config schema or signature handling.
- Release or packaging steps.
- Minimum supported WordPress or PHP versions.

## Security issues

Do not file public issues for suspected vulnerabilities. Follow the process in `SECURITY.md`.
