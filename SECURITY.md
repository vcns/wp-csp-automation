# Security Policy

## Supported versions

| Version | Supported |
| --- | --- |
| 0.2.x | Yes |

## Reporting a vulnerability

Do not disclose suspected vulnerabilities in a public GitHub issue, pull request, or WordPress.org support thread.

Send a private report to `security@wp-csp-automation.dev` with:

- A concise description of the issue.
- Impact and attack preconditions.
- Reproduction steps.
- A proof of concept or request sample if relevant.
- The affected plugin version and WordPress version.

## Response targets

- Initial acknowledgement: within 3 business days.
- Triage decision: within 7 business days.
- Status update cadence: at least every 7 business days while the report remains open.
- Fix release target for confirmed high-severity issues: as quickly as practical, normally within 30 days.

## Preferred report quality

Helpful reports include:

- Exact request or payload examples.
- Relevant plugin settings or environment assumptions.
- Whether the issue requires admin access, editor access, or no authentication.
- Whether the issue depends on a particular caching, security, or e-commerce plugin.

## Safe-harbor expectations

Good-faith security research is welcome provided you:

- Avoid privacy violations, destructive testing, service interruption, or data exfiltration.
- Limit testing to environments you own or are explicitly authorised to assess.
- Give a reasonable opportunity to remediate before public disclosure.

## Security design notes

Current design assumptions for this plugin:

- The Stripe webhook verification secret is stored only in WordPress options and never published in client-side code. Stripe API secret keys are held exclusively in the Cloudflare Worker as Worker secrets and are never transmitted to or stored by the WordPress plugin.
- Premium entitlement decisions are made locally from database state and verified Stripe webhook events.
- Remote product configuration must never contain secrets.
- Signed remote configuration uses Ed25519 signatures and should be verified with libsodium whenever available.
- Enforce-mode CSP rollout is intentionally gated behind an approval workflow to reduce lockout risk.

## Non-vulnerability reports

The following generally do not qualify as security issues by themselves:

- Missing best-practice headers unrelated to this plugin's execution path.
- Abuse requiring direct admin access to the target WordPress install.
- Issues caused solely by unsupported WordPress, PHP, or host configurations.
- Requests to support older, end-of-life PHP versions.
