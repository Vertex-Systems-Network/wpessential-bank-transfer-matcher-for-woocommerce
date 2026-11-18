# Phase 2 order administration slice

The Free WooCommerce submenu now lists native BACS orders with `on-hold` and `pending` status by default. The server-side status filter narrows to either state, and the table paginates at 20 orders per page. It uses WooCommerce order objects and edit URLs in both HPOS and classic storage. It never issues direct `wp_posts` order queries.

The table shows date, customer name, immutable payment reference, total, verified manual allocations, outstanding amount, customer claim status and reconciliation state. All amounts are rendered from exact integer minor units and include the currency code. A paid order whose allocation total disagrees with the order total is flagged as an exception if viewed in the native order panel. If a monetary snapshot cannot be calculated safely, the row shows an explicit error and links to the order instead of a misleading number.

The read-only native WooCommerce order panel appears on BACS orders for users with `wbm_view`. It shows reference, exact balance, claim status, evidence count and a link to the Matcher order list. Non-BACS orders do not show the panel. The private receipt download link remains separately guarded by `wbm_review_claim`.

Search, broader filters, inline action forms and the detail drawer from the full UI inventory remain T012 work. This slice only exposes working controls; no bulk payment or decorative action buttons are present.
