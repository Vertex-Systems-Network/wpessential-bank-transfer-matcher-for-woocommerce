# Free overview and audit timeline

The Matcher submenu shows four real, read-only indicators:

1. Eligible native BACS orders in `pending` or `on-hold`, counted by WooCommerce order query.
2. Customer claims in `submitted` or `under_review`.
3. Customer claim verification events created in the last 30 UTC days. This is a claim count, not a paid-order count.
4. Outstanding amount **on the visible page only**, grouped by currency and rendered from integer minor units. This label prevents a paginated sample from appearing to be a store-wide financial total. If an order summary is invalid, the view warns that the page total excludes it.

The native WooCommerce BACS order panel shows the latest ten reconciliation events. It reads customer claim submission, manual evidence, allocations, claim decisions and completed payment intent. Each entry shows action, actor and local display time; private reviewer comments, bank notes and attachment bytes are excluded. The activity is read-only, and a claim verification event does not mean WooCommerce payment completion.

Native integration tests exercise both classic and HPOS order storage with synthetic USD/EUR orders, claim statuses, exact balances and private-note exclusion. Browser visual and accessibility checks remain in Phase 7.
