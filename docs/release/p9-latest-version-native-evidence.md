# P9 compatibility gap — real latest official patch tests

The existing historical verified matrix uses WordPress 7.0.8 and WooCommerce 10.8.0/11.3.0. WordPress.org Plugin Check 2.2.0 flagged `outdated_tested_upto_header` in the Free `readme.txt`, which still honestly says `Tested up to: 7.0`. **Do not change that statement to 7.1 until a real WordPress 7.1.x native test succeeds.**

This dedicated workflow `.github/workflows/p9-latest-version-native.yml` installs **real WordPress 7.1.5, WooCommerce 11.3.1, PHP 8.3, MariaDB 11.4** on a disposable site with both exact-built Free and Pro ZIPs, exercises `scripts/test-native-install.sh` (activation, missing dependency, HPOS/classic and finance tests) and emits an artifact **only on successful completion**.

Potential blockers: version download unavailable, plugin incompatibility or real test failure. Do not assume passing results merely from creating workflow. Even if successful, the latest patch verifies one runtime stack, not every PHP/WC/storage/browser combination. Canonical `docs/source/product-metadata.json` may only be updated after actual successful test, retaining its docs generator/no-drift gates. This workflow never processes real bank details, payments or merchant stores, and never submits or publishes.

## Compatibility fixture correction after genuine test failure

The first exact-source test run [44847940952](https://github.com/Vertex-Systems-Network/wpessential-bank-transfer-matcher-for-woocommerce/actions/runs/44847940952) failed at the old P8 header audit, **not** at real payment functionality: it still required a `Tested up to: 7.0` field in the main PHP plugin file, which official WordPress.org Plugin Check correctly rejects. Its upper WooCommerce bound also disallowed 11.3.1 even though 11.3.1 was deliberately being tested. That was a validation-harness defect, not evidence of actual support.

The strict P8 test now ensures **no** unsupported main-PHP `Tested up to` header and retains the official Woo `WC tested up to: 11.3.0` boundary; it does not silently claim a newer tested release. The explicitly isolated candidate-only `WBM_P9_LATEST_PATCH_PROBE=1` path requires the actual installed WordPress to be exactly 7.1.5 and WooCommerce exactly 11.3.1, then exercises the same installed Free+Pro HPOS/classic WooCommerce CRUD and financial suite. Only a real green re-run permits later canonical readme/version claim changes. No production release or merchant data involved.

## Verified latest compatibility claim update

Real disposable native run [44848601239](https://github.com/Vertex-Systems-Network/wpessential-bank-transfer-matcher-for-woocommerce/actions/runs/44848601239) passed **WordPress 7.1.5, WooCommerce 11.3.1, PHP 8.3, MariaDB 11.4** with independently packaged Free/Pro, both HPOS/classic, and finance regression suite. The authentic Actions artifact **14684512231** was downloaded and inspected. This enables a narrowly supported canonical `Tested up to: 7.1` and `WC tested up to: 11.3.1` claim in generated Free readme and both plugin headers; does not imply PHP 8.4 or broader version combinations tested for the new WordPress patch. Previous WP 7.0.8 x Woo 10.8/11.3 four-cell matrix remains separate historical proof. Following the code/docs changes, fresh exact-head matrix, Plugin Check, generator drift and latest-patch tests must pass before a merged release gate.
