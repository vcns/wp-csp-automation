# Testing and Quality

## Objective

This document defines the expected validation baseline for development, review, and release.

## Quality gates

Every meaningful change should be validated at four levels where applicable:

- syntax correctness
- static analysis and coding standards
- behaviour verification in WordPress
- security-focused regression checks

## Local validation baseline

Before opening a pull request, maintainers should aim to validate:

- PHP syntax for changed files
- plugin activation on a clean WordPress install
- admin page load without fatal errors or warnings
- relevant REST endpoint behaviour
- any changed scan, policy, or entitlement paths

## Recommended CI pipeline

The repository should enforce these checks on pull requests:

### Fast checks

- PHP syntax lint
- PHPCS with WordPress standards
- PHPStan at a stable level for the codebase
- secret scanning
- dependency or advisory scanning where Composer dependencies exist

### Integration checks

- boot WordPress in a disposable environment
- activate the plugin
- verify custom tables are created
- verify admin pages load
- verify REST routes register and respond with expected status codes

### Security checks

- CodeQL for PHP
- Semgrep or equivalent SAST rules for PHP and WordPress anti-patterns
- ZAP baseline or similar DAST against a disposable test site

## Manual test matrix

### Activation and uninstall

- activate plugin on a clean site
- confirm all tables exist
- confirm default options are seeded
- deactivate and confirm cron event is removed
- uninstall and confirm tables and options are removed

### CSP runtime

- verify nonce appears on expected script tags
- verify CSP header exists for frontend, admin, login, and API surfaces as configured
- verify report-only mode does not break page rendering
- verify enforce mode is blocked when no approvals exist
- verify approved sources and active hashes appear in the emitted header

### Discovery and inventory

- run manual scan
- verify discovered sources are classified into expected directives
- verify same-origin assets are excluded
- verify approval and deny actions persist correctly

### Violation reporting

- submit both legacy and Reporting API payloads
- verify rows are created or deduplicated correctly
- verify per-surface rate limiting works

### Premium flow

- refresh remote config successfully
- create Stripe Checkout Session in test mode
- complete a successful test payment
- verify entitlement is granted only after webhook delivery
- resend the same webhook and confirm idempotency
- simulate async failure and confirm no entitlement is granted

## Release validation checklist

Before tagging a release:

- update version in `wp-csp-automation.php`
- update `readme.txt` stable tag if needed
- update `CHANGELOG.md`
- review public docs for accuracy
- run full CI pipeline on the release branch
- perform one clean-install smoke test from the packaged artifact
- confirm no development-only files are shipped unintentionally

## Test data hygiene

- do not commit real API keys, webhook secrets, or signing keys
- use Stripe test keys in development only
- do not store customer-identifying data in fixture payloads if avoidable

## Defect triage priorities

Highest severity:

- privilege escalation
- unauthenticated state changes
- broken webhook verification
- CSP header corruption that can break admin recovery
- entitlement grant without verified payment

High severity:

- enforce/report-only mode logic errors
- broken discovery classification
- persistent data loss on upgrade or uninstall
- invalid remote config acceptance

Medium severity:

- admin UI defects without security impact
- false-positive violation ingestion
- noisy but non-breaking scans

## Definition of done

A change is ready to merge when:

- the behaviour is implemented
- affected docs are updated
- relevant validation has been run
- known risks are explicit in the PR
- no unsupported shortcuts were introduced around security-critical flows
