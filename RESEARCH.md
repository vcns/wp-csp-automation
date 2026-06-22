# CSP RFC Standards, Directive Inventory, and Requirements-Specification Review

## TL;DR
- **There is no single RFC that defines CSP.** CSP is a W3C specification (CSP Level 2 is a 2015 W3C Recommendation; **CSP Level 3 remains a W3C Working Draft, dated 5 May 2026**, edited by Mike West and Antonio Sartori). The IETF's role is narrow and supporting: **RFC 7762** (Informational) created the IANA directives registry, **RFC 7034** (Informational) documents the X-Frame-Options header that CSP's `frame-ancestors` supersedes, and CSP normatively depends on infrastructure RFCs - **RFC 6454** (Origin), **RFC 9110** (HTTP Semantics, STD 97), **RFC 5234** (ABNF, STD 68), **RFC 9651** (Structured Fields, which **obsoletes RFC 8941**), and BCP 14 (RFC 2119/8174).
- **The current full directive set** spans CSP2 + CSP3 + adjunct specs across five families (fetch, document, navigation, reporting, Trusted Types) plus standalone directives (`upgrade-insecure-requests`). Several CSP2/early-CSP3 items are now deprecated, obsolete, removed, or at-risk: `report-uri`, `plugin-types`, `block-all-mixed-content`, `navigate-to`, `prefetch-src`, and (effectively) `child-src`.
- **The v0.2 spec is technically strong but has fixable gaps:** it cites no standards at all, omits Trusted Types and `upgrade-insecure-requests`, frames the nonce-entropy requirement imprecisely, references the deprecated `Report-To` framing loosely, and is missing several non-functional and acceptance criteria. Eleven specific numbered fixes are below.

## Key Findings

### 1. The RFC landscape for CSP

CSP is **not** an IETF Standards-Track protocol - it is owned by the W3C Web Application Security Working Group. The IETF documents are supporting or adjacent:

| RFC | Title | Date | Status | Relevance to CSP |
|-----|-------|------|--------|------------------|
| **7762** | Initial Assignment for the Content Security Policy Directives Registry | Jan 2016 | **Informational** | The closest thing to an IETF CSP document. Establishes the IANA "Content Security Policy Directives" registry and populates it with the CSP2 directives. Registration policy: "Specification Required." Author: M. West (Google). |
| **7034** | HTTP Header Field X-Frame-Options | Oct 2013 | **Informational** | Documents X-Frame-Options, the anti-clickjacking header that CSP's `frame-ancestors` directive supersedes/obsoletes. Authors: Ross & Gondrom. |
| **6454** | The Web Origin Concept | Dec 2011 | **Proposed Standard** | Defines "origin" and origin serialization - the foundation of CSP source-expression matching. Author: A. Barth. |
| **9110** | HTTP Semantics | Jun 2022 | **Internet Standard (STD 97)** | Defines HTTP field/header semantics; CSP3's header ABNF references RFC 9110 §5.6.1 for its `#`-list rule. |
| **5234** | Augmented BNF for Syntax Specifications: ABNF | Jan 2008 | **Internet Standard (STD 68)** | The grammar notation used throughout all CSP specifications. |
| **9651** | Structured Field Values for HTTP | **Sep 2024** | **Proposed Standard** | **Obsoletes RFC 8941.** The `Reporting-Endpoints` header is a Structured Fields Dictionary; this is the current normative reference. Authors: Nottingham & Kamp. |
| **8941** | Structured Field Values for HTTP | Feb 2021 | **Proposed Standard (now obsolete)** | Obsoleted by RFC 9651. Still cited by older Reporting-API drafts; cite 9651 going forward. |
| **2119 / 8174** | Key words for requirement levels | 1997 / 2017 | **BCP 14** | Normative-language conventions used by every CSP spec. |
| **6797** | HTTP Strict Transport Security (HSTS) | Nov 2012 | **Proposed Standard** | Complementary to `upgrade-insecure-requests`; the upgrade spec explicitly states it does **not** replace HSTS. |

Verified from the authoritative source: the IANA **"Content Security Policy Directives" registry** was created **2015-11-24** and was **last updated 2023-02-03**, with registration procedure **"Specification Required"** and designated expert **Mike West** (reference [RFC7762]). It still lists only the **16 original CSP2 directives**: `base-uri`, `child-src`, `connect-src`, `default-src`, `font-src`, `form-action`, `frame-ancestors`, `frame-src`, `img-src`, `media-src`, `object-src`, `plugin-types`, `report-uri`, `sandbox`, `script-src`, `style-src`. Newer CSP3 directives (`worker-src`, `manifest-src`, `script-src-elem/attr`, `style-src-elem/attr`, etc.) are **not** reflected in the registry - it lags the living standard and should not be treated as the authoritative directive list.

The W3C documents that matter:
- **CSP Level 2** - W3C **Recommendation** (development concluded 2014, published 2015). The only formally "Recommendation"-status CSP version. W3C advises implementers to base work on Level 3.
- **CSP Level 3** - W3C **Working Draft**, latest published version 5 May 2026 (`WD-CSP3-20260505`). Still not a Recommendation, but widely implemented in modern browsers.
- **Adjunct W3C specs delivered through the CSP header or closely tied to it:** Trusted Types; Upgrade Insecure Requests; Mixed Content; CSP: Embedded Enforcement (the `csp` iframe attribute, `Sec-Required-CSP` request header, `Allow-CSP-From` response header); and the Reporting API (which defines `Reporting-Endpoints`).

### 2. Full CSP directive inventory

**Fetch directives** - control where resource types may load; every unset fetch directive falls back to `default-src` (no inheritance for set directives):

| Directive | Controls | Valid sources | Support / status |
|-----------|----------|---------------|------------------|
| `default-src` | Fallback for all fetch directives | source list | CSP1, universal |
| `script-src` | JavaScript execution/loading | source list, `'self'`, `'unsafe-inline'`, `'unsafe-eval'`, nonces, hashes, `'strict-dynamic'`, `'unsafe-hashes'`, `'report-sample'` | CSP1, universal (nonces/hashes CSP2) |
| `script-src-elem` | `<script>` elements only (not inline handlers) | as script-src | CSP3 - Chrome/Firefox; **not Safari** (use script-src for portability) |
| `script-src-attr` | Inline event-handler attributes (onclick, etc.) | as script-src | CSP3 - Chrome/Firefox; not Safari |
| `style-src` | Stylesheet/CSS sources | source list, nonces, hashes, `'unsafe-inline'` | CSP1, universal |
| `style-src-elem` | `<style>` / `<link rel=stylesheet>` | as style-src | CSP3 |
| `style-src-attr` | Inline `style=` attributes | as style-src | CSP3 |
| `img-src`, `font-src`, `connect-src`, `media-src`, `object-src` | Images; fonts; fetch/XHR/WebSocket/EventSource/`<a ping>`; audio/video; `<object>`/`<embed>` | source list | CSP1, universal |
| `frame-src` | Frame/iframe sources | source list | Deprecated in CSP2, **un-deprecated in CSP3**; falls back to child-src → default-src |
| `child-src` | Workers + nested browsing contexts | source list | CSP2; fallback for frame-src/worker-src; **legacy** (Firefox warns "child-src has been deprecated") |
| `worker-src` | Worker / SharedWorker / ServiceWorker | source list | CSP3; historically unsupported in Safari (falls back to child-src → script-src → default-src) |
| `manifest-src` | Web app manifest | source list | CSP3 |
| `fenced-frame-src` | `<fencedframe>` sources | source list | Experimental (privacy-focused) |
| `prefetch-src` | Prefetch/prerender resources | source list | **Deprecated / never officially shipped**; Chromium intent-to-remove; MDN marks deprecated and non-standard - do not emit |

**Document directives** - govern document/worker properties:
- `base-uri` - restricts `<base>` element URLs (prevents base-tag hijacking). CSP2. **Does not** fall back to default-src.
- `sandbox` - applies iframe-style sandbox flags (`allow-forms`, `allow-scripts`, etc.). CSP1. **HTTP-header-only** (ignored in `<meta>`); ignored by Content-Security-Policy-Report-Only.
- `plugin-types` - **deprecated/removed**; restricted plugin MIME types - do not emit.

**Navigation directives** - govern where a document can navigate or submit:
- `form-action` - restricts `<form>` submission targets. CSP2. Does not fall back to default-src.
- `frame-ancestors` - valid parents that may embed the page; **supersedes X-Frame-Options (RFC 7034)** and is ignored in `<meta>`. CSP2. Does not fall back to default-src.
- `navigate-to` - **removed from the CSP3 spec** (was at-risk; restricted navigation targets) - do not emit.

**Reporting directives** - inert on their own; depend on other directives:
- `report-uri` - **deprecated in CSP3** in favor of `report-to`; POSTs a `application/csp-report` JSON document. Still the **most broadly supported** in practice (works in browsers where `report-to` does not, historically Firefox), so it remains a necessary legacy fallback.
- `report-to` - CSP3; references an endpoint **name** defined by the `Reporting-Endpoints` HTTP response header (a Structured Fields Dictionary per RFC 9651). Uses the Reporting API; payload `application/reports+json`, an array whose relevant entries have `type: "csp-violation"`.

**Trusted Types directives** (W3C Trusted Types spec, delivered via the CSP header; aimed at DOM-based XSS):
- `require-trusted-types-for 'script'` - forces non-spoofable, typed values into DOM XSS sinks (e.g., `innerHTML`, `document.write`).
- `trusted-types` - allowlists Trusted Types policy names created via `trustedTypes.createPolicy()`; supports `'none'`, `'allow-duplicates'`, and the `'trusted-types-eval'` relaxation keyword.
- **Browser-support caveat (conflicting sources):** MDN's directive pages state these "work across the latest devices and browser versions" as of **February 2026**, but web-features/Baseline data projects "widely available" only around **August 2028**, and caniuse historically shows native support limited to **Chromium/Chrome/Edge 83+** (with a W3C polyfill/"tinyfill" for other engines). Treat Trusted Types as **Chromium-strong, not yet universal**, and deploy in report-only first.

**Standalone / adjacent directives delivered through the CSP header:**
- `upgrade-insecure-requests` - auto-upgrades `http→https` for subresource requests, same-origin navigation, and form submissions. Own W3C spec; evaluated **before** `block-all-mixed-content`. Does **not** replace HSTS (RFC 6797) for top-level navigation.
- `block-all-mixed-content` - **obsolete** (MDN); superseded by default browser mixed-content auto-upgrade/blocking. If `upgrade-insecure-requests` is set, this is a no-op - do not emit in new policies.
- `reflected-xss`, `referrer` - proposed/legacy CSP3-era items (`reflected-xss` was meant to supplant `X-XSS-Protection`); not part of stable enforcement and should not be relied upon.

**Source-expression keywords** (the "valid source expressions" for `*-src` directives): `'none'` (must be the sole value), `'self'`, `'unsafe-inline'`, `'unsafe-eval'`, `'strict-dynamic'`, `'unsafe-hashes'`, `'report-sample'`, `'nonce-<base64>'`, hash sources `'sha256-/sha384-/sha512-<base64>'` (base64 or base64url accepted for hashes; nonces are strict matches), scheme-sources (`https:`, `data:`, `blob:`), and host-sources with optional wildcards (e.g., `*.example.com`, `mail.example.com:443`). Internationalized domains must be Punycode-encoded; only `127.0.0.1` matches among IP literals.

### 3. Spec review - gaps and inaccuracies

**Standards citations are entirely absent.** A v0.2 "hardened" FRS for a CSP product cites no W3C or IETF document. This is the single largest documentation gap; it makes "current CSP standards landscape" claims unverifiable and the directive list (§4.4) un-anchored.

**Nonce-entropy framing (§4.1).** The spec mandates `random_bytes(32)` Base64-encoded. This is *more than adequate* (256 bits), but the requirement is phrased as an implementation detail rather than against the standard. W3C CSP3 requires that a nonce **"be generated using a cryptographically secure pseudorandom number generator (CSPRNG)"** and **"should contain at least 128 bits of entropy and must be base64-encoded."** The spec should state the **CSPRNG requirement and the 128-bit entropy floor** as the normative requirement, with 256 bits as an acceptable implementation choice.

**Directive list (§4.4) is incomplete and partially outdated.** It omits Trusted Types (`require-trusted-types-for`, `trusted-types`) and `upgrade-insecure-requests`, and it does not flag the directives that must **not** be emitted (`plugin-types`, `block-all-mixed-content`, `navigate-to`, `prefetch-src`). It correctly includes the granular `script-src-elem/attr` and `style-src-elem/attr` but should note their lack of Safari support and the need to keep `script-src`/`style-src` as the portable fallback.

**Reporting framing (§4.8/§4.13).** The spec lists `report-to`, `report-uri`, and `Reporting-Endpoints` - good. But it should make explicit that **`Report-To` (the JSON header) is deprecated in favor of `Reporting-Endpoints`** (Structured Fields, RFC 9651), and that `report-uri` remains a required legacy fallback because `report-to`/Reporting-API support is still uneven. Report ingestion (§4.13) correctly accepts both payloads but should pin the field schemas: legacy `csp-report` keys (`document-uri`, `violated-directive`, `blocked-uri`, `effective-directive`, `script-sample`, `line-number`, `column-number`, `disposition`, `status-code`) vs. Reporting-API `body` keys (`documentURL`, `effectiveDirective`, `blockedURL`, `sample`, `lineNumber`, `columnNumber`, `disposition`, `statusCode`, `originalPolicy`). The `sample` field only populates when `'report-sample'` is present.

**WordPress platform reality (§4.2/§4.3).** The strict-CSP approach is sound and the use of `wp_script_attributes`/`wp_inline_script_attributes` is the correct WordPress-native path (added in WP 5.7 specifically for CSP, per Make/Core ticket #39941). But the official "Strict CSP" plugin and core work confirm material limitations the spec should acknowledge: (a) WordPress **cannot yet apply strict CSP to wp-admin** (Trac #59446) - so §4.3's admin profile is best-effort; (b) some bundled core themes still hardcode `<script>` tags (#63806), which strict nonce-based CSP will block; and (c) scripts must be emitted via the WP script APIs (`wp_enqueue_script`, `wp_add_inline_script`, `wp_print_inline_script_tag`, `wp_enqueue_script_module`) or they will be blocked. These belong in a "Known Constraints" subsection.

## Details

The CSP delivery model the spec automates is: a `Content-Security-Policy` header (enforcement) and/or `Content-Security-Policy-Report-Only` header (monitoring), each carrying a `;`-delimited directive set; multiple headers combine to the **most restrictive** union (adding a second policy can only further restrict). `<meta http-equiv>` delivery is supported but cannot carry `frame-ancestors`, `sandbox`, `report-uri`, or the report-only header - relevant because the spec's surface profiles (§4.3) should prefer header delivery and never rely on meta for those directives.

`'strict-dynamic'` (CSP3) is the key to the spec's nonce/hash strategy (§4.4): a nonce- or hash-trusted script may then load further scripts without each needing its own nonce, and host-based allowlists are **ignored** when `'strict-dynamic'` is present - which is exactly the "minimize allowlists" objective in §3. The spec's optional `'strict-dynamic'` support (§4.4) is correct; it should document that enabling it neutralizes host allowlists for `script-src`, and that `'unsafe-inline'` is ignored when a nonce or hash is present (a useful backwards-compatibility layering).

On hashing (§4.5): the spec's SHA-256 + Base64 approach matches CSP hash-source syntax (`'sha256-…'`), and CSP3 also permits SHA-384/512 and base64url. The canonicalization concern the spec raises is real - CSP hashes are byte-exact, so any whitespace/formatting change invalidates the hash, which is why the spec's deterministic-canonicalization requirement is well-founded.

## Recommendations

Staged and prioritized. **Stage 1 (correctness, before any further build work):**

**R1 - Add a "Standards & References" section.** Cite CSP Level 2 (W3C Recommendation), CSP Level 3 (W3C Working Draft, 5 May 2026), Trusted Types, Upgrade Insecure Requests, Mixed Content, CSP: Embedded Enforcement, and the Reporting API; plus RFC 7762, RFC 7034, RFC 6454, RFC 9110 (STD 97), RFC 5234 (STD 68), RFC 9651 (note it obsoletes RFC 8941), RFC 6797, and BCP 14. State explicitly that CSP is W3C-governed and that the IANA registry (last updated 2023-02-03) is not authoritative for the directive list.

**R2 - Correct the Structured Fields reference.** Specify that `Reporting-Endpoints` is a Structured Fields **Dictionary per RFC 9651** (which obsoletes RFC 8941). Update §4.8.

**R3 - Fix the nonce requirement (§4.1).** Require a **CSPRNG** and a **≥128-bit entropy floor** as the normative requirement; keep `random_bytes(32)` (256 bits) as an acceptable implementation. Also require constant-time / per-response uniqueness as already stated.

**R4 - Mark non-emittable directives.** Instruct the builder to **never emit by default**: `plugin-types`, `block-all-mixed-content`, `navigate-to`, `prefetch-src`. Flag `child-src` as legacy (kept only for Safari worker fallback). Update §4.4.

**Stage 2 (feature completeness):**

**R5 - Add Trusted Types support.** Add `require-trusted-types-for 'script'` and `trusted-types <policy-list>` to the builder (§4.4), defaulting to **report-only** rollout given uneven cross-browser support (Chromium-strong; Baseline/web-features projects broad availability only ~2028; MDN claims Feb 2026 - verify against MDN Baseline at build time). This directly advances the §3 DOM-XSS objective.

**R6 - Add `upgrade-insecure-requests`.** Support it as an optional directive (§4.4) and document that it does **not** replace HSTS (RFC 6797) and is evaluated before the obsolete `block-all-mixed-content`. Do not offer `block-all-mixed-content`.

**R7 - Pin report schemas (§4.13).** Document the dual field maps: legacy `csp-report` keys vs. Reporting-API `body` keys; store a normalized internal schema with both `effective-directive`/`effectiveDirective` and `blocked-uri`/`blockedURL` mapped to one canonical column. Note `sample` only populates with `'report-sample'`.

**R8 - Clarify reporting-header deprecation (§4.8).** State that `Report-To` (JSON header) is deprecated in favor of `Reporting-Endpoints`; keep `report-uri` as a required legacy fallback because `report-to` support is still incomplete.

**R9 - Add a "Known Platform Constraints" subsection.** wp-admin cannot yet receive strict CSP (Trac #59446); some core themes hardcode `<script>` (#63806); scripts must route through WP script APIs to be nonced. Treat §4.3's admin profile as best-effort and surface a dashboard warning when un-nonceable inline scripts are detected.

**Stage 3 (non-functional and acceptance hardening):**

**R10 - Add missing non-functional requirements (§5).** Capability/permission model (which roles may approve source/override exceptions, separate from `manage_options`); immutable/append-only audit logging; a data-retention and purge policy for high-volume report tables; explicit rate-limit/abuse protections on the public `/report` endpoint (CSP reports are spoofable by clients - a documented threat); dashboard accessibility and i18n; and a multisite network-vs-site policy precedence rule.

**R11 - Add missing acceptance criteria (§6).** (a) A Trusted Types report-only policy can be emitted and its violations ingested; (b) `upgrade-insecure-requests` can be enabled and verified without enabling `block-all-mixed-content`; (c) `'report-sample'` behavior is verified (sample present for inline violations only); (d) the builder never emits deprecated/removed directives; (e) emitted policies validate against an external checker (e.g., Google CSP Evaluator) with no `'unsafe-inline'`/`'unsafe-eval'` in enforced mode.

**Benchmarks that would change these recommendations:** If Trusted Types reaches cross-browser Baseline "widely available" status (verify on MDN/web-features), promote R5 from report-only-default to enforce-eligible. If the IANA registry is updated to include CSP3 directives, R1's "registry is not authoritative" caveat can be softened. If WordPress core ships wp-admin CSP support (resolving Trac #59446), R9's admin best-effort caveat can be removed and §4.3's admin profile promoted to a first-class enforcement surface.

## Caveats
- **CSP Level 3 is still a Working Draft.** Directive availability is browser-dependent; verify each directive against MDN/caniuse at build time rather than treating the spec as uniformly implemented.
- **Trusted Types availability is genuinely contested across sources** (MDN: Feb 2026 "works across latest browsers"; web-features/Baseline: ~Aug 2028 widely available; caniuse: Chromium 83+ only). The report reflects this conflict rather than resolving it; the plugin should gate Trusted Types behind report-only by default and re-check Baseline at release.
- **The IANA registry lags the living standard** (last updated 2023-02-03 with only 16 CSP2 directives); it is authoritative for *registration policy* but not for the *current* directive set.
- **CSP violation reports are client-generated and therefore spoofable** (a documented 2018 finding); the spec's deduplication/rate-limiting (§4.13) is necessary but cannot make report data fully trustworthy - treat it as signal, not ground truth.
- RFC maturity nuance: among the cited Standards-Track RFCs, only **9110 (STD 97)** and **5234 (STD 68)** are full Internet Standards; **6454, 6797, 8941, and 9651** are at the **Proposed Standard** level. **7762 and 7034 are Informational**, not standards.