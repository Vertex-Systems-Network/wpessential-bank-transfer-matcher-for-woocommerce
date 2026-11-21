# P9 compatibility gap — real latest official patch tests

The existing historical verified matrix uses WordPress 7.0.8 and WooCommerce 10.8.0/11.3.0. WordPress.org Plugin Check 2.2.0 flagged `outdated_tested_upto_header` in the Free `readme.txt`, which still honestly says `Tested up to: 7.0`. **Do not change that statement to 7.1 until a real WordPress 7.1.x native test succeeds.**

This dedicated workflow `.github/workflows/p9-latest-version-native.yml` installs **real WordPress 7.1.5, WooCommerce 11.3.1, PHP 8.3, MariaDB 11.4** on a disposable site with both exact-built Free and Pro ZIPs, exercises `scripts/test-native-install.sh` (activation, missing dependency, HPOS/classic and finance tests) and emits an artifact **only on successful completion**.

Potential blockers: version download unavailable, plugin incompatibility or real test failure. Do not assume passing results merely from creating workflow. Even if successful, the latest patch verifies one runtime stack, not every PHP/WC/storage/browser combination. Canonical `docs/source/product-metadata.json` may only be updated after actual successful test, retaining its docs generator/no-drift gates. This workflow never processes real bank details, payments or merchant stores, and never submits or publishes.
