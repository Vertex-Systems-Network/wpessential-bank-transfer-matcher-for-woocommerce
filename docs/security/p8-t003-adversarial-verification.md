# P8-T003 — Actual SQL/XSS/CSRF/XXE/CSV adversarial regression

**Accepted: VERIFIED at exact source SHA `44f569439ecbf9c1969111357d356dfb14180d46`**, 2026-11-21. This is reproducible synthetic regression evidence, **not** an exhaustive penetration test or a release-security certification.

## Exact-head evidence
- [Foundation + native WordPress/WooCommerce/MariaDB CI 44817993455](https://github.com/Vertex-Systems-Network/wpessential-bank-transfer-matcher-for-woocommerce/actions/runs/44817993455): both jobs **SUCCESS**.
- [Foundation + native WP/WC/MariaDB 44817986338](https://github.com/Vertex-Systems-Network/wpessential-bank-transfer-matcher-for-woocommerce/actions/runs/44817986338): both jobs **SUCCESS**.
- [Chromium browser on genuine WP/WC HTTP 44817986325](https://github.com/Vertex-Systems-Network/wpessential-bank-transfer-matcher-for-woocommerce/actions/runs/44817986325): **SUCCESS**, evidence artifact **14670457940**. Five exact-SHA GitHub checks all completed with success.
- Native test output: `PASS P8-T003 actual WordPress/MariaDB SQL-literal, stored HTML escaping, finance CSV formula neutralization/redaction, malformed CSV, no-XXE and receipt MIME: 20 assertions; no paid-order mutation`.
- Browser output: `PASS P8-T003 six real HTTP invalid/missing nonce CSRF denials on actual WP admin-post / REST; no authorized finance POST`. The downstream real LTR+Arabic RTL UI, role flows, private HTTP and synthetic unpaid BACS database integrity also pass.

## Source and threat coverage

| Threat | Genuine boundary and test result |
| --- | --- |
| SQL-like input | Legitimate `BankAccountRepository::create()` stores malicious-looking quoted SQL text as literal nickname; `all()` reflects only the intended new row; schema remains intact. No raw injected SQL is executed |
| Stored HTML/XSS | The actual `BankAccountsPage::render()` HTML contains escaped hostile `<img onerror=...>` bank metadata, never the raw executable tag |
| Spreadsheet formula injection | Real allowlisted `ReportCsvEncoder::encode()` escapes `=`, `+`, `-`, `@` and Unicode/control whitespace-prefixed formula cells; rejects NUL; excludes nonallowlisted raw bank metadata. Re-parsed CSV field is apostrophe-neutralized |
| Invalid CSV input | Actual `CsvParser` preserves inert quoted source formula text while rejecting NUL, invalid UTF-8 and oversized cells |
| XML XXE/DOCTYPE | Genuine `Camt053Parser` accepts valid versioned CAMT fixture but denies external/internal entity DTD markers, including mixed-case declarations; no external files are read |
| Spoofed uploaded receipt | Genuine `ClaimAttachment::validate()` rejects SVG/HTML/script content masquerading as PDF/PNG |
| Authenticated CSRF/admin-post | Genuine logged-in WordPress browser makes 5 synthetic unsafe admin POST attempts using invalid nonce; every actual handler returns 403, no bank account row appears |
| Authenticated REST CSRF | The sixth synthetic attempt targets the sensitive payment-finalize REST route **without WordPress REST cookie nonce**; returns 401/403 and never authorizes a payment action |
| Final financial invariant | Native WooCommerce `p7-usability-integrity.php` confirms synthetic orders remain on-hold/unpaid and guest claim unsubmitted after browser requests |

Test sources: `tests/integration/p8-adversarial-inputs.php`, `tests/browser/p8-csrf-http.mjs`, `.github/workflows/p7-browser-qa.yml`, `scripts/test-native-install.sh`, plus the preexisting release parser/CSV tests.

## CI tooling incident addressed

Several earlier test runs had **`wp: command not found`** because optional `wp-cli` installation by `shivammathur/setup-php@v2` printed a failure while the setup action itself reported success. This was runner bootstrap, **not** a detected plugin SQL/XSS defect. `scripts/ensure-wp-cli-ci.sh` now verifies an existing executable and, if missing, installs official WP-CLI **2.12.0** from the release with GitHub-published exact SHA-256 `ce34ddd838f7351d6759068d09793f26755463b4a4610a5a5c0a97b68220d85c`, failing closed if checksum differs. It runs only on disposable CI, never inside distributed plugin ZIPs.

## Residual gates

The positive assertions are scoped: this is not exhaustive SQL fuzzer coverage, manual browser XSS/DOM pen testing for every finance surface, a CSRF probe of every registered action, arbitrary MIME polyglot resistance certification, or proof against future CVEs. Follow-up security gates P8-T004–T009 and marketplace/release validation P9 remain mandatory. No production statement, customer receipt, real payment, authorized finance POST, merchant setting or published ZIP was changed.
