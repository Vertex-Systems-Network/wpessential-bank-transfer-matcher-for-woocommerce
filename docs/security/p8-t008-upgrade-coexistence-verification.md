# P8-T008 — Real in-place WooCommerce upgrade and plugin coexistence acceptance

**VERIFIED at exact code SHA `680602d833018fda5ebfaaa62bd0f1c95e1bb355`, 2026-11-21.** This is a two-mode synthetic compatibility/regression test, not an assurance about all merchant-installed third-party plugins.

## Exact-head GitHub proof

All **12/12** GitHub checks have completed with `success` at this SHA:
- [Real WooCommerce 10.8.0 → 11.3.0 same-store upgrade and independent Free/Pro ZIP reinstalls, run 44832609979](https://github.com/Vertex-Systems-Network/wpessential-bank-transfer-matcher-for-woocommerce/actions/runs/44832609979): **HPOS and Classic both SUCCESS**. Separate JSON evidence artifacts: **14676258401** (HPOS) and **14676621621** (Classic).
- [Foundation/Playground/native WP/Woo/MariaDB 44832609976](https://github.com/Vertex-Systems-Network/wpessential-bank-transfer-matcher-for-woocommerce/actions/runs/44832609976) and [44832619094](https://github.com/Vertex-Systems-Network/wpessential-bank-transfer-matcher-for-woocommerce/actions/runs/44832619094): two foundation jobs and two MariaDB jobs, all **SUCCESS**.
- [Actual Chromium LTR+Arabic RTL + Classic BACS guest browser/Store API backend checkout + privacy/CSRF check 44832609830](https://github.com/Vertex-Systems-Network/wpessential-bank-transfer-matcher-for-woocommerce/actions/runs/44832609830): **SUCCESS**, screenshot/report artifact **14676089619**.
- [PHP8.3/8.4 × WC10.8.0/11.3.0 version matrix 44832609858](https://github.com/Vertex-Systems-Network/wpessential-bank-transfer-matcher-for-woocommerce/actions/runs/44832609858): **4/4 SUCCESS** on actual installed packaged Free+Pro with HPOS+Classic order CRUD.
- [Native 1k/10k synthetic import, 50k bounded rejection 44832610042](https://github.com/Vertex-Systems-Network/wpessential-bank-transfer-matcher-for-woocommerce/actions/runs/44832610042): **SUCCESS**.

## Actual state carried through upgrade

`tests/integration/p8-upgrade-coexistence.php` is executed in each of the two primary order storage modes by `.github/workflows/p8-upgrade-coexistence.yml`.

1. Install actual WP 7.0.8, WC 10.8.0, independently packaged Free and Pro, and a **separate benign CI-generated WordPress plugin** that registers a custom `woocommerce_order_number` prefix. It lives only in the disposable test site; not in either release ZIP.
2. Verify Pro cannot activate with a missing required Free edition (real WordPress plugin dependency resolution). The paired runtime exposes two independent Composer autoloaders.
3. Create an actual **synthetic unpaid guest BACS order** (USD 45.00), reserve its immutable Free payment reference, submit an explicitly **unverified** claim, and save a masked Pro bank account. Assert that the harmless companion's `EXT-` order number remains visible.
4. Deactivate Pro and verify the same Free reference and unverified claim still work with the companion plugin. Reactivate Pro.
5. Upgrade WooCommerce to exactly **11.3.0 in place** and force-reinstall independently built Free and Pro ZIPs, preserving the same database and order.
6. On two **independent WP-CLI processes**, assert same order is still `on-hold`/unpaid with null paid date, same billing identity and amount, unchanged immutable reference, same unverified claim (including date, sender and note), the same single Pro masked bank metadata row, expected migration schema checkpoints, and no duplicate claim/reference rows. Verify both plugin loaders and third-party filter still active. Clean up only synthetic rows.

Sample real native logs for HPOS:
- `PASS P8-T008 seeded same-store synthetic BACS order, immutable reference, unverified claim, bank account and third-party order-number filter in hpos`
- `PASS P8-T008 Pro deactivation preserves Free reference and unverified claim with companion order-number plugin in hpos`
- `PASS P8-T008 real Woo 10.8.0 to 11.3.0 upgrade, Free/Pro ZIP reinstall, companion coexistence, persisted unpaid BACS ref/claim/bank metadata and migration checkpoints in hpos`
- `PASS P8-T008 synthetic upgrade fixture cleanup in hpos`

## Remaining risk and limits

This tests one specifically identified harmless third-party plugin (custom order-number filter), **not all plugin ecosystems or race conditions during a live upgrade**. The CI environment has WP7.0.8, PHP8.3, MariaDB11.4; more PHP/WC combinations are covered by the separate version matrix, not this in-place upgrade. Rollback after an interrupted WP plugin update, actual production backups, arbitrary dependency activation cycles or high-traffic concurrent checkout require merchant-staging verification. No real deposits, bank API calls, customer private data, production deployment or live payment transitions occurred.

**P8-T009** must still close final security, correctness, reliability/performance findings; P9 release candidate work remains pending. No marketplace publishing authorized.
