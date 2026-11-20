# P8-T005 — WooCommerce HPOS and classic order storage verification

**VERIFIED** on 2026-11-21 at exact source SHA `6ffc566738e24c3fb0083763493319823a3f2271`, with 5/5 successful checks:
- Native WordPress 7.0.8 / WooCommerce **11.3.0** / PHP 8.3 / MariaDB 11.4 + Playground: [44820665254](https://github.com/Vertex-Systems-Network/wpessential-bank-transfer-matcher-for-woocommerce/actions/runs/44820665254) and [44820655490](https://github.com/Vertex-Systems-Network/wpessential-bank-transfer-matcher-for-woocommerce/actions/runs/44820655490), **SUCCESS**.
- Real Chromium LTR/Arabic RTL role navigation, private HTTP/no-CSRF and unpaid BACS integrity: [44820655390](https://github.com/Vertex-Systems-Network/wpessential-bank-transfer-matcher-for-woocommerce/actions/runs/44820655390), **SUCCESS**, artifact **14671987232**.
- Actual installed Free and Pro plugins independently register official `before_woocommerce_init` `FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true)`, including frontend sessions. Native `FeaturesUtil::get_compatible_features_for_plugin` confirms each plugin as compatible.
- `tests/integration/p8-hpos-plugin-compat.php` uses real `OrderUtil::custom_orders_table_usage_is_enabled`, real WordPress packaged plugin metadata and actual WC CRUD (create, persist/reload BACS `on-hold` with order meta and amount, delete) **in both primary storage modes**, without any order payment completion.
- Main plugin headers and generated Free `readme.txt` align `Tested up to: 7.0`, `WC tested up to: 11.3.0`; product metadata `tested_wp`/`tested_wc` dynamically validated for ordering against `requires_wp`/`requires_wc`, not hardcoded future release tuples.
- Existing synthetic Free E2E and Pro payment finalizer suite already runs both modes. Direct order postmeta manipulations are not needed.

**Scope:** The only installed WooCommerce release verified here is 11.3.0; minimum WC 10.8, alternate PHP releases and real Checkout Blocks checkout are **NOT** verified by this task. P8-T006 must evaluate those honestly, including actual Store API and BACS confirmation. Not a public plugin release or a production bank/payment test.

WooCommerce official references:
https://woocommerce.github.io/code-reference/files/woocommerce-src-utilities-featuresutil.html
https://developer.woocommerce.com/docs/contribution/contributing/version-support-policy/
