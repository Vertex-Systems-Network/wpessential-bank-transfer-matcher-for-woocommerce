# ADR-0013 — P6-T002 source-backed read-only report providers

Status: initial allocation-audit and import-batch providers committed; native verification pending.

Report readers require both Free approval and Pro import finance capabilities. SQL table and field names are selected from exact enumerated report types, never interpolated from request input. User-supplied date bounds, bank account IDs, currency, state and before-ID cursor are validated in ReportDefinition, then bound through prepared SQL. Results are ordered by descending immutable IDs with a maximum of 501 fetched records, and return a stable next-before-ID when another page exists.

Allocation-audit report rows expose only validated order/source/account IDs, exact minor amounts, currencies, precision, state and UTC creation time. Import-batch rows expose IDs, currency, status and verified row/warning/error **counts**, never private mapping profiles, filenames, bank account numbers or source references. Batch counts are not money and therefore contribute zero to financial totals. Both providers use PerCurrencyTotals to segregate page totals by currency+exponent+state and reject malformed DB money or PHP integer overflow. The output explicitly declares payment_authorized=false and ledger_mutated=false and includes a generated-at UTC timestamp. This is not an immutable financial snapshot and must never complete or approve WooCommerce payments.

The initial native disposable database fixture verifies filtered audit/statement rows, per-account and per-currency isolation, proposed vs approved segregation, descending cursor pagination, accurate import progress counts and denial to an unauthenticated actor.

P6-T002 remains incomplete until all outstanding BACS orders, unmatched bank rows, partial-funding and exception aging report families are available and verified through native WC CRUD/Pro data. Never wire report queries to a customer route, action hooks, refund or live bank connection.
