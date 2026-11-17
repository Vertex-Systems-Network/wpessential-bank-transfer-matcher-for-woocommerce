# Acceptance and verification plan

### 14.1 Test layers

- **Unit:** references, validation, money arithmetic, state machines, match ranking/explanations, fingerprints, allocation invariants, capability policies.
- **Property-based/edge:** random order amounts, currency exponents, allocation lists; assert sums/replay invariants.
- **Database integration:** migrations, unique indexes, transaction locking/idempotency, journal consistency, rollback/retry.
- **WooCommerce integration:** BACS On hold behavior, HPOS enabled/disabled, paid lifecycle/virtual products, cancelled/refunded orders, native emails, legacy/classic checkout and Blocks where supported.
- **WP-admin E2E:** every UI-001 to UI-014 critical path; filters, search, sort, pagination, buttons, modal validation, permission error, settings saved/reloaded.
- **Customer E2E:** checkout to reference display, order page, guest claim, secure file access, claim review and notifications.
- **Import fixture suite:** every scenario in Section 7.6; CSV profiles; large inputs and invalid rows.
- **Security:** WP-style auth/capability, XSS/CSRF/XXE/CSV injection, upload privacy, data leakage, arbitrary file requests.
- **Compatibility:** minimum and current tested WP/WC/PHP matrix, multiple popular themes, multisite policy, other plugins if available.
- **Performance:** 1k/10k/50k statement rows under bounded memory; exact thresholds measured and recorded on representative local environment, not invented guarantees.
- **Installation/release:** fresh install Free alone, Free+Pro, Pro missing Free, deactivate/re-activate each, upgrade/downgrade safe behavior, no data loss, separate ZIP validation.
- **Visual:** capture screenshots and test 1440px/1024px/375px, dark admin color schemes, RTL sample, keyboard navigation.

### 14.2 Mandatory failure tests

- Same transaction approved in two concurrent browser sessions; only one succeeds, other sees conflict.
- Partial allocated total never triggers payment_complete.
- Duplicate statement reimport produces zero new credits and zero additional paid transitions.
- Bank credit allocated to one order cannot be allocated beyond remainder to another.
- Payment proof claim cannot bypass bank verification.
- User with viewer capability cannot download customer receipt unless explicitly permitted.
- Pro deactivation does not break Free admin or destroy ledger.
- Missing Free disables Pro operations safely.
- 500 response during import shows resumable failed state, not successful status.
- New order total after approval generates appropriate exception; never silently rewrites ledger.
- WC payment transition failure reports partial workflow safely with recoverable job/event; never duplicates payment completion.

### 14.3 Definition of Done per task

A task becomes `verified` only when: functional code exists; all acceptance clauses met; relevant automated tests pass; UI relevant state/screens tested; accessibility, security and performance impacts checked; documentation updated; no newly introduced critical regressions; evidence logs/screenshots/commit recorded; Free/Pro boundary intact. A green build alone is not enough when acceptance requires actual WP/WC integration.

### 14.4 Overall release gates

Release candidate must pass: security gate, financial invariants gate, functional workflows (Free and Pro), **independent Composer/autoload gate C1**, **WordPress.org Free gate WORG**, **Woo Marketplace Pro gate WOO**, **documentation generator gate DOC**, **Playground + MySQL gate PG**, packaging/install gate, UX coverage gate, source compliance, privacy documentation, rollback/migration gate and honest change log. If any gate fails, label package **release candidate — not production ready** and continue fixing where possible. Do not ship known financial-correctness blockers.

---

See traceability.csv for pending evidence.
