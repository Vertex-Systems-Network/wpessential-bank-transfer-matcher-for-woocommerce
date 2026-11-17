# Payment references

Free issues a reference at WooCommerce checkout for a saved order using the native BACS gateway. Each reference uses the `WBM-` prefix and a random suffix. It is reserved against the internal order ID in `{prefix}wbm_payment_references`. This table has unique constraints on both order IDs and reference strings. Duplicate candidates trigger a bounded retry. An existing reference remains authoritative even if the displayed order number changes.

The first eligible issuance provisions the table through the migration runner. Reference lookup does not provision the table. Migration or reservation failures are reported to the WooCommerce logger without adding order or customer details. This checkout foundation leaves existing orders untouched. Presentation, administrator controls, recovery and legacy backfill belong to later tasks.

Native integration tests cover the registered classic and Blocks actions, forced reference collisions, custom display numbers, BACS eligibility and repeat issuance in both HPOS and classic storage. They dispatch checkout hooks directly. Full browser checkout and supported storage declarations remain separate gates.

## Native gateway settings

Free checks WooCommerce's registered gateway list for enabled methods. Reference issuance requires native BACS to be enabled and selected for the order. Other enabled gateways remain outside reference issuance. The integration reads the native BACS instructions setting and leaves WooCommerce's instructions and bank-account options intact. Customer-facing rendering is handled separately.

## Customer presentation

Free renders the stored reference in classic Thank You, WooCommerce's Order Confirmation Additional Information block, the owner's My Account order view, and the customer on-hold email in HTML and plain text. The confirmation requires a valid order key and, for a registered order, the owning customer. Guest confirmation also remains subject to WooCommerce's own verification before it invokes the hook. Bank account information stays in WooCommerce's native confirmation and email output. My Account shows the native transfer instructions while the order awaits payment.
