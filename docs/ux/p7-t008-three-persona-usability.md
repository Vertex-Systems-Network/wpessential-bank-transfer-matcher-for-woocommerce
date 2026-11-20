# P7-T008 — Actual WooCommerce three-persona usability and financial-state preservation

**Status: VERIFIED** against exact source SHA `a1103c4d416f53cb472cff1ebae627256747f431` on 2026-11-20. Real WordPress 7.0.8 / WooCommerce 11.3.0, independently built Free+Pro ZIPs and isolated synthetic MariaDB `wbm_ci`; no merchant bank/customer data. Browser Actions [44715095307](https://github.com/Vertex-Systems-Network/wpessential-bank-transfer-matcher-for-woocommerce/actions/runs/44715095307) **SUCCESS**, evidence artifact **14618693822**. Foundation + MariaDB + WordPress Playground [44715095410](https://github.com/Vertex-Systems-Network/wpessential-bank-transfer-matcher-for-woocommerce/actions/runs/44715095410) and [44715103038](https://github.com/Vertex-Systems-Network/wpessential-bank-transfer-matcher-for-woocommerce/actions/runs/44715103038) both **SUCCESS**.

## Source-backed browser tasks

`tests/browser/p7-usability.mjs` tests **three distinct user sessions** with only a synthetic CI store owner, a genuine non-admin WooCommerce shop manager and an anonymous guest. All **five task goals in each of LTR and actual Arabic WordPress RTL** passed, for ten total task walkthroughs and twelve screenshots. Actual WordPress login, WooCommerce hooks, admin submenu pages, GET filter submissions and guest checkout order-received endpoints are used; no Figma mock pages or fabricated API responses.

| Persona / tasks | Observed source-backed acceptance |
| --- | --- |
| Store owner: find claimed BACS order and investigate | Real Free order row `Review` navigates to WooCommerce reconciliation; unverified customer claim, merchant bank evidence, verified allocation, outstanding balance and explicit no-auto-paid warnings displayed |
| Store owner: navigate admin, finance, mobile order filters | Native finance dashboard / operations / Free settings accessible; admin has actual save capability; 375px order status filter preserves `on-hold` |
| Finance shop manager: priority-filter queue and inspect a case | Authenticated real shop manager, high priority GET filter persisted, seed case opens bank evidence/order comparison/decision context without allocating money |
| Finance shop manager: view masked ledger, roles and mobile queue | Imported source credited but original raw synthetic reference not displayed, allocation ledger accessible, Free settings read-only, three manager-only Pro screens absent from visible menu |
| Guest customer: read reference and inspect unclaimed form | Separate **genuine guest** WooCommerce BACS on-hold order with order key; order-received page shows exact payment reference, mandatory billing email/date/amount/acknowledgment and unverified claim warning; blank form fails built-in browser validation; **nothing submitted** |

## Real UI and screenshots

The successful artifact `p7-real-wordpress-visual-evidence` (ID **14618693822**) contains **131 actual screenshots**: 59 baseline LTR + 56 Arabic RTL UI captures, 4 menu navigation captures, and 6 persona screenshots per locale. The six JSON reports (`p7-report-*.json`, `p7-navigation-*.json`, `p7-usability-*.json`) all record **zero issues**. Source-backed 14 UI IDs, 1440/1024/375, forced-colors, keyboard table focus, first-header visibility and real mobile navigation passed.

Before: prior guest screenshot in artifact **14618228334** had narrow inline text/date inputs and a small submit target at 375px. After: Free-only `assets/customer-claims.css` is enqueued solely on actual WooCommerce order-received / My Account pages and scopes all styling to `.wbm-payment-claim`, without overriding the merchant theme. Real `p7-usability-ltr-guest-claim-form-375.png` and `p7-usability-rtl-guest-claim-form-375.png` in artifact **14618693822** show stacked 100%-width fields and submit button. Browser DOM geometry explicitly confirms each visible field is >=85% of form width and >=43px tall, all fields are within bounds, and submit button is >=85% of width and >=43px tall. Both writing directions pass.

## Security and no-money-mutation evidence

- The only browser POST is normal WordPress login. Browser request guards fail on unexpected POST to financial `admin-post.php` or `wpessential-wbm` REST routes.
- `tests/browser/p7-usability-integrity.php` runs **after both LTR/RTL browser walkthroughs**, against actual `WC_Order` objects and the claim/reference repositories. Both seeded BACS orders stay `on-hold`, `is_paid() === false`, have no paid date, have original references, the originally claimed order retains its claim, and the separate guest order remains **unclaimed**. Integrity step in browser Actions 44715095307 **SUCCESS**.
- WooCommerce's modern fresh-store **Coming Soon** option initially masked the anonymous checkout page in CI; only the disposable localhost fixture sets `woocommerce_coming_soon=no`. There is **no change to merchant store visibility or production settings**. Relevant official documentation: https://developer.woocommerce.com/2025/01/17/developer-advisory-coming-soon-mode-by-default/ .
- Initial post-login wp-admin landing was not guaranteed to be an actual matcher screen (WooCommerce setup/navigation interstitial), so test users navigate the genuine Free matcher route and wait for its real admin menu before assessing discoverability. Both real live states now verified.

## Evidence limits / remaining release gates

A read-only usability walkthrough does **not** prove successful financial approval, live bank clearance, form POST edge cases, uploads, or real users completing these tasks. No bank/refund/merchant payment movement was attempted or permitted. Also **not certified:** WCAG 2.2 AA by a human screen-reader practitioner, systematic per-theme contrast/audit, complete plugin Arabic translations (LTR/RTL direction is real; plugin English text remains English). These remain explicit P8/P9 review/release constraints, not silently declared compliant.

**P7-T008 acceptance:** actual store-owner, finance operator and customer task walkthrough verified, safe fallback states shown, real mobile/RTL accessibility assertions passed, no financial mutation. P7 phase closure still requires latest phase head CI and policy/review gates before PR #14 is merged. Main issue #7 remains open until full release-candidate acceptance.
