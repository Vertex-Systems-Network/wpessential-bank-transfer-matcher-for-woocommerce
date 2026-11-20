# ADR-0013 — P6-T002 source-backed read-only report providers

Status: initial allocation-audit and import-batch providers committed; native verification pending.

Report readers require both Free approval and Pro import finance capabilities. SQL table and field names are selected from exact enumerated report types, never interpolated from request input. User-supplied date bounds, bank account IDs, currency, state and before-ID cursor are validated in ReportDefinition, then bound through prepared SQL. Results are ordered by descending immutable IDs with a maximum of 501 fetched records, and return a stable next-before-ID when another page exists.

Allocation-audit report rows expose only validated order/source/account IDs, exact minor amounts, currencies, precision, state and UTC creation time. Import-batch rows expose IDs, currency, status and verified row/warning/error **counts**, never private mapping profiles, filenames, bank account numbers or source references. Batch counts are not money and therefore contribute zero to financial totals. Both providers use PerCurrencyTotals to segregate page totals by currency+exponent+state and reject malformed DB money or PHP integer overflow. The output explicitly declares payment_authorized=false and ledger_mutated=false and includes a generated-at UTC timestamp. This is not an immutable financial snapshot and must never complete or approve WooCommerce payments.

The initial native disposable database fixture verifies filtered audit/statement rows, per-account and per-currency isolation, proposed vs approved segregation, descending cursor pagination, accurate import progress counts and denial to an unauthenticated actor.

P6-T002 remains incomplete until all outstanding BACS orders, unmatched bank rows, partial-funding and exception aging report families are available and verified through native WC CRUD/Pro data. Never wire report queries to a customer route, action hooks, refund or live bank connection.


## HPOS-safe order funding reports (CI pending)

Outstanding BACS and partial-funding reports use WooCommerce CRUD, not \`wp_posts\` or the HPOS-specific order SQL tables. They read exact WooCommerce order totals in minor units and subtract only Free manual allocations and Pro allocations in approved/committed state. Unreviewed proposed money does not count as funded. Each order's expected total, ISO currency and decimal precision are rechecked against all existing funding rows; stale/overdrawn order snapshots or invalid/overflowing database integers fail closed. Unconfirmed receiving-bank bindings are displayed as unconfirmed; a bank-account filter includes only explicit matching bindings.

These are **unpaid** BACS order statuses, with UTC order creation bounds; the query excludes COD and paid orders. Pagination is by immutable order ID and totals are per returned page, not a total financial ledger. A hard 3,000-order WooCommerce CRUD scan limit forces a narrower query on large stores rather than misrepresenting an incomplete result as complete. The native fixture exercises classic storage and HPOS separately, including $50 outstanding and $60 outstanding on a $100 order with $40 manual credit; no financial action is triggered.


## Unmatched completed imported bank rows (native CI pending)

The read-only unmatched report excludes approved or committed bank credits and splits other completed imported credit rows into clean unique \`unmatched\`, duplicate/warned/account-conflict \`ambiguous\`, or \`reviewed\` (only an unapproved proposal awaiting a separate checker). All three states remain **unverified bank clearance**, and none can mark an order paid. The report returns no raw bank reference, transaction ID, description, bank number, or account number. Posting-calendar dates are strict ISO days, separate from WooCommerce UTC order creation. Results use a hard 3,000-row scan bound; wide historical import searches fail closed with instructions to narrow account/cursor rather than silently hide records. Native synthetic fixture covers duplicate fingerprints, warnings, already allocated exclusion, exact-money totals and pagination.
