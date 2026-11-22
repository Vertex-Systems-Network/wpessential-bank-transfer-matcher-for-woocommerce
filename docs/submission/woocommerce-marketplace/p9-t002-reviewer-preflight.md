# Woo Marketplace Pro reviewer preflight — P9-T002

**Development draft (2026-11-21). Not a submission or approval.** Source baseline: `a515d8f6ac98a2fe94ebedb35b3f2e70b9164501`. Edition: `wpessential-bank-transfer-matcher-pro` version **0.1.0**, a separately packaged add-on to the Free `wpessential-bank-transfer-matcher`.

**Evidence boundaries:** GitHub Actions foundation, native WordPress/WooCommerce/MariaDB, real browser, performance and upgrade checks are repository tests, **not Woo QIT**. No vendor account, Woo managed QIT run, license/update backend, official Marketplace acceptance or publication is evidenced here. Actual QIT belongs to P9-T010, official WordPress.org checks to P9-T009. See the machine-verifiable [QIT ledger](p9-t002-qit-matrix.json).

## Merchant and reviewer installation

1. On a **disposable WordPress site** use only synthetic orders and bank rows. Current actual plugin headers: WordPress **7.0+**, PHP **8.3+**, WooCommerce **10.8+**; PHP 8.3/8.4 × WC 10.8.0/11.3.0 on WP 7.0.8 were tested, not every intervening/future combination. Verify the versions against the final package headers before listing.
2. Install and activate WooCommerce. Upload the separate self-contained **Free** ZIP and activate it before uploading/activating **Pro**; Pro checks active Free API `>=1.0.0 <2.0.0`, uses its own Composer autoloader and fails gracefully with an administrator notice if dependencies are absent/incompatible. Native dependency and same-store ZIP reinstall evidence: [P8-T008](../../security/p8-t008-upgrade-coexistence-verification.md). Verify final optimized release archives in P9-T004/T013.
3. WooCommerce → Settings → Payments → **Direct bank transfer (BACS)**: enable BACS with *fictional* receiving details. The plugins supplement WooCommerce's native offline gateway; they do **not** redirect money, replace account settings or require a bank API.
4. Create a synthetic virtual product and anonymous BACS order, check the immutable payment reference and explicitly **unverified** claim form on order received / account views. Real browser Classic checkout and genuine Checkout Blocks Store API checkout were exercised [P8-T006](../../security/p8-t006-checkout-compatibility.md); the **visible Checkout Block Pay button itself was not clicked** and remains a separate manual E2E gate.
5. Admin under WooCommerce → **Bank Transfer Matcher**: inspect eligible orders, claims, manual evidence, independent allocations and deliberate authorized payment completion. Pro menus (subject to actual role gates): **Matcher bank accounts**, **Statement mappings**, **Import statements**, **Bank transactions**, **Bank review queue**, **Finance allocations**, **Bank finance dashboard**, **Finance reports**, **Finance notifications**, **Bank operations**, **Bank data retention**. Actual UI role/RTL/375px evidence: [P7-T008](../../ux/p7-t008-three-persona-usability.md).
6. Pro flow: create masked fictional receiving-account metadata, define strict CSV date/amount/currency/reference profile, run dry-run preview, review row-level errors, then commit a **synthetic** source to private staged import. Review deterministic matching suggestions and exceptions; no import, customer claim, amount similarity, or report marks orders paid. Test duplicated CSV/source, malformed header, overpayment, mismatched currency and non-matching reference; failures should remain reviewable.
7. Test *disabled Pro* with Free still active, *missing/incompatible Free*, BACS disabled, non-BACS orders, wrong role, stale nonce, stale paid/cancelled order, failed/retried jobs and private/expired bank or receipt URLs. Verify no privileged Pro screen/route becomes available when its prerequisites fail.
8. Use the [Pro finance operating guide](../../operations/PRO-FINANCE-ADMIN-GUIDE.md), [incident/recovery](../../operations/PRO-INCIDENT-RUNBOOKS.md), [schema upgrades and backups](../../database/migrations.md), [Free capability API](../../api/access.md), and [14-screen source-backed contract](../../ux/p7-source-backed-screen-contracts.md). Do not attach actual statement or customer data to reviewer tickets.

## Security, privacy and business invariants

- A **customer claim or screenshot is never bank evidence**. A merchant-supplied imported statement is not verified live bank clearance. Human authorized approval and independent confirmed source, exact minor-unit amount/currency/account, audit and race-safe source/order locking are required for eligible payment completion; partial funds must not fully pay an order. The plugins never initiate refunds, transfers, chargebacks or payouts.
- Free permissions: `wbm_view`, `wbm_review_claim`, `wbm_approve`. Pro adds `wbm_import` and `wbm_manage_bank_accounts`; shop managers may import but cannot manage bank accounts by default. Sensitive operations/notifications/retention require composite gates + valid nonce and actor audit. See [P8 security closure](../../security/p8-t009-final-security-closure.md) and [private source audit](../../security/p8-t002-private-receipt-statement-audit.md).
- Private CSV staging outside the public document root, receipt access control, CSV formula neutralization, bounded import and exports, cleanup/retention opt-in: source-backed assertions, **not** proof on arbitrary merchant hosting. Live backups, data-protection policy and support arrangements require publisher review.
- **Commercial:** Master spec's proposed **$79/year one-site price is not approved**. Confirm current Woo revenue-share and price policy with the Marketplace vendor, legal developer identity/website, support channel, refunds, trademark and listing claims. Do not invent business IDs, support email, reseller relationships, live bank integrations or customer testimonials.
- **Freemium:** Woo submission guidance says Free and paid forms require **two separate Marketplace submissions**, later combined into one listing; a WordPress.org Free directory submission alone is not Woo Marketplace approval.
- **License/update:** Both plugin PHP headers declare `GPL-2.0-or-later`, and Pro requires Free. No functional Woo Marketplace entitlement, active license screen, external auto-update integration, vendor API credentials or approved pricing is established by this evidence. Treat as publisher/reviewer gap rather than presenting a working license feature.
- **Header:** Do **not** manually add Woo Marketplace's `Woo` plugin header to an unsubmitted package; Woo's extension guidance states that Marketplace adds it after deployment.

## Source-backed release gate matrix

| Review dimension | Existing, non-QIT source evidence | Not yet independently verified |
| --- | --- | --- |
| Activation, Free dependency, WC upgrade | [P8-T008 native HPOS/classic same-store upgrade](../../security/p8-t008-upgrade-coexistence-verification.md) | Final release ZIP + official QIT activation |
| API/security/CSRF/SQL/XSS/CSV/XML/privacy | [P8-T003 real native adversarial checks](../../security/p8-t003-adversarial-verification.md); [P8-T009 closure](../../security/p8-t009-final-security-closure.md) | QIT Woo API, security, malware and reviewer security checks |
| Checkout and PHP versions | [P8-T006 Classic browser, Blocks Store API, four-cell PHP/WC matrix](../../security/p8-t006-checkout-compatibility.md) | Actual QIT E2E/PHP compatibility and clicked Block Pay button |
| HPOS and Classic CRUD | [P8-T005 official plugin compatibility + native DB](../../security/p8-t005-hpos-compatibility.md) | Final release archive tests on two latest major supported families |
| Browser UX, keyboard, Arabic RTL | [P7-T008 three persona, actual screenshots](../../ux/p7-t008-three-persona-usability.md) | Independent accessible UX review including screen readers |
| Synthetic bank import load | [P8-T007 1k/10k and 50k bounded rejection](../../security/p8-t007-synthetic-load-benchmarks.md) | Actual QIT/performance/reviewer environment on final artifact |

## Official policy reviewed 2026-11-21

- [Submitting your product — business, development, UX and QIT checklist](https://developer.woocommerce.com/docs/woo-marketplace/submitting-your-product)
- [Marketplace vendor application and submission journey](https://developer.woocommerce.com/docs/woo-marketplace/getting-started)
- [Freemium and monetization expectations](https://developer.woocommerce.com/docs/woo-marketplace/monetization-expectations)
- [Woo extension development, HPOS and no unauthorized telemetry](https://developer.woocommerce.com/docs/extensions/best-practices-extensions/extension-development-best-practices)
- [Woo extension plugin headers (Woo header prohibition before deployment)](https://developer.woocommerce.com/docs/extensions/getting-started-extensions/how-to-design-a-simple-extension)
- [QIT managed suites and custom E2E](https://developer.woocommerce.com/docs/extensions/getting-started-extensions/)

## Unresolved release ownership

| Gate | Status | Responsible checkpoint |
| --- | --- | --- |
| True reviewer-ready business identity, support, approved price, partner/vendor account | **NOT VERIFIED** | Publisher / P9-T002 / P9-T010 |
| Official QIT Activation, Woo API, E2E, Security, Malware, PHP Compatibility and Validation | **NOT RUN** | P9-T010 (requires available vendor access) |
| Release ZIPs, license notice/vendor audit, actual screenshot set, checksum manifest | **PENDING** | P9-T003/004/006/013 |
| Final Playground, MariaDB/HPOS/Classic clean install and upgrade on exact archives | **PENDING** | P9-T005/011/013 |
| Official WordPress.org Plugin Check/readme online validator | **NOT RUN** | P9-T009 |
| Public release, SVN upload, merchant deploy or live payment/reconciliation | **NOT AUTHORIZED** | Explicit publisher authorization |

Do **not** change a gate to complete from a GitHub run alone. Complete P9-T002 only after its evidence/instructions are reviewed and code-level consistency tests pass, then advance without claiming QIT results.
