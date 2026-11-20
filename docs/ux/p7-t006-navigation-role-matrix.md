# P7-T006 — Free/Pro navigation and permission matrix

Status: **implemented on a corrective branch; exact-source CI and browser confirmation pending**.

## Why a correction is required

PR #13 was merged to `main` on 2026-11-20, even though `.ai/tasks.json` still tracks **P7-T006 in progress** and **P7-T007 / T008 planned**. Merging a PR does not establish test evidence for unfinished tasks.

Six Pro finance screens registered WooCommerce submenu entries using only `wbm_import` or `wbm_manage_bank_accounts`, whereas their actual render handlers require both `wbm_import` and Free `wbm_approve`, sometimes plus `wbm_manage_bank_accounts`. A partial-role user could see a menu destination and receive a 403 after clicking it.

The corrected `menu()` methods require the same composite capabilities as the real screen. This changes only navigation visibility. Render, finance mutations, REST access and nonce checks retain their existing stricter enforcement.

## Regression evidence contract

`tests/integration/p7-menu-role-matrix.php` invokes actual WordPress `add_submenu_page()` and uses disposable synthetic users with `wbm_view`, `wbm_import`, `wbm_approve` and `wbm_manage_bank_accounts` combinations. Four actor cases are run in three separately bootstrapped environments:
- no Free: no matcher submenu
- Free-only: one accessible Free matcher menu
- Free+Pro: matching read-only review, importer screens, finance-only and manager-only screens, with no duplicate or forbidden menu slug

`scripts/test-native-install.sh` invokes all three modes against WordPress/WooCommerce/MariaDB in GitHub Actions. No live bank transaction, customer order mutation or financial approval is performed by these navigation checks.

**Acceptance still pending:** complete exact-head Foundation/native/Playground CI and actual browser/keyboard/mobile/Arabic RTL navigation; subsequently finish T007 visual rubric and T008 store-owner/finance-operator/customer usability walkthrough before promoting P7 to done or starting P8.
