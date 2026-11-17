# Payment references

Free issues a reference at WooCommerce checkout for a saved order using the native BACS gateway. Each reference uses the `WBM-` prefix and a random suffix. It is reserved against the internal order ID in `{prefix}wbm_payment_references`. This table has unique constraints on both order IDs and reference strings. Duplicate candidates trigger a bounded retry. An existing reference remains authoritative even if the displayed order number changes.

The first reference lookup or issuance provisions the table through the migration runner. Migration or reservation failures are reported to the WooCommerce logger without adding order or customer details. This checkout foundation leaves existing orders untouched. Presentation, administrator controls, recovery and legacy backfill belong to later tasks.

Native integration tests cover the registered classic and Blocks actions, forced reference collisions, custom display numbers, BACS eligibility and repeat issuance in both HPOS and classic storage. They dispatch checkout hooks directly. Full browser checkout and supported storage declarations remain separate gates.
