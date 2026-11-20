# P8-T006 — Real Classic and Checkout Blocks BACS + exact PHP/WooCommerce matrix

**Accepted: VERIFIED at exact source SHA `ce5d78392d8b0f0cd8042aa4a55e4b45ce3bf50a`, 2026-11-21.** This verifies disposable WordPress/WooCommerce fixtures, not an external payment or a production checkout rollout.

## Exact GitHub Actions evidence

- [Real WordPress/Chromium, run 44829621375](https://github.com/Vertex-Systems-Network/wpessential-bank-transfer-matcher-for-woocommerce/actions/runs/44829621375), **SUCCESS**, artifact **14673854609**. The `Actual classic browser and Checkout Blocks Store API synthetic guest BACS checkout` step, `Verify actual checkout orders unpaid and source-referenced in native WooCommerce`, LTR+Arabic RTL visual/menu/owner-finance-guest usability, private HTTP and CSRF denial checks, and final P7 synthetic unpaid-state invariant all completed successfully.
- Foundation/Playground/native WordPress 7.0.8, WooCommerce 11.3.0, PHP 8.3, MariaDB 11.4: [44829621364](https://github.com/Vertex-Systems-Network/wpessential-bank-transfer-matcher-for-woocommerce/actions/runs/44829621364) and [44829632822](https://github.com/Vertex-Systems-Network/wpessential-bank-transfer-matcher-for-woocommerce/actions/runs/44829632822), **both SUCCESS** (4 native/Foundation jobs).
- [Four-cell exact-version matrix run 44829621415](https://github.com/Vertex-Systems-Network/wpessential-bank-transfer-matcher-for-woocommerce/actions/runs/44829621415), **4/4 SUCCESS** on actual installed packaged Free+Pro, WordPress 7.0.8, MariaDB 11.4:

| PHP | WooCommerce | Actual Free+Pro install / HPOS / classic WC CRUD / reference hooks |
| --- | --- | --- |
| 8.3 | 10.8.0 | PASS |
| 8.3 | 11.3.0 | PASS |
| 8.4 | 10.8.0 | PASS |
| 8.4 | 11.3.0 | PASS |

Nine exact-commit GitHub check runs completed with conclusion `success`; no incomplete checks or non-green jobs when accepted. The matrix tests installed version, public WC HPOS feature compatibility, both Woo order primary data stores and reference hook dispatch; **the complete browser checkout is executed under the pinned WC 11.3.0/PHP 8.3 stack**, not once per matrix cell.

## Real checkout behavior, not mocked hooks

1. `tests/browser/p8-checkout-seed.php` creates one published **synthetic virtual WC product** and a distinct Classic shortcode checkout page without changing configured Woo checkout settings. It reuses the **actual configured WooCommerce Checkout Block page**, verifying serialized `woocommerce/checkout` block presence. P7 screenshot/guest order-received URLs are unchanged.
2. `tests/browser/p8-real-checkout-http.mjs` uses Playwright Chromium as an **anonymous shopper**, opens a true cart, fills the actual Classic billing form, selects BACS and clicks Woo `#place_order`, follows the real Woo order-received redirect, and checks the plugin-generated payment reference and unsubmitted customer claim form.
3. A second anonymous browser session actually renders the installed Checkout Block page. Separately, Node issues **real Woo Store API HTTP** `GET /wc/store/v1/cart`, an authenticated-by-cart-token `POST /cart/add-item` with the same synthetic product, then `POST /checkout` with offline `payment_method: bacs`. Valid HTTP success codes include 200 and 201, but the test insists the cart actually contains the desired item, Woo returned an order ID and same-origin order-received redirect, and the reference/claim form are present. **The Block's on-screen Pay button itself was not clicked**; its server-side checkout path was exercised via genuine Store API and Block frontend rendered separately. This distinction must be maintained in any release claim.
4. `tests/browser/p8-checkout-integrity.php` reads **both real WooCommerce orders** from the database. It verifies two unique plugin-issued immutable payment references, BACS payment method, guest customer ID 0, one expected line item, total USD 12.50, order **on-hold/unpaid**, null paid date, and **no customer claim submission**. No real bank funds, settlement, order marking-paid, or authorized reconciliation POST performed.

## Regressions discovered by real tests and corrected

- The installed WooCommerce checkout page can be **Blocks**; assuming it contains `[woocommerce_checkout]` led to a false fixture failure. Added an isolated Classic shortcode page without modifying the installed canonical Checkout Block page.
- Calling `do_blocks()` in WP-CLI without frontend context does not prove real Checkout Block rendering. Replaced with saved Block AST inspection in seed and actual Chromium render on canonical checkout page.
- A noncanonical extra Checkout Block page did not render the checkout root. Use the existing canonical Woo checkout for frontend assertion.
- The real Woo Store API returned a **successful HTTP 2xx (including 201 Created)** for `/cart/add-item`; accepting **200 only** falsely rejected a correctly populated cart. Corrected to accept all 2xx while strictly asserting actual cart contents and persisted order data.

## Limits and pending release work

Compatibility coverage **does not establish all WooCommerce versions between 10.8.0 and 11.3.0**, PHP 8.5, checkout with every third-party payment gateway, block frontend click submission across browsers, or fully localized checkout translations. The project uses synthetic BACS only. P8-T007–T009 and P9 remain gates; do not publish either plugin or certify production readiness.
