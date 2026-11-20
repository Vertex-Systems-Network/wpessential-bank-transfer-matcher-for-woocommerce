# ADR-0012 — P6-T001 reproducible reporting definitions and safe totals

Status: verified in full GitHub Actions runs 44643672874 and 44643662149 (Foundation, Playground and native).

A report definition is a **read-only query contract** rather than an authorization, a materialized report, a payment assertion, or evidence of bank clearance. The Pro reporting phase defines six report IDs: outstanding BACS orders, unmatched imported credits, partial order funding, financial exception aging, import batches and allocation audit. Each type has an explicit allowed state vocabulary. Unknown keys or unsupported states fail closed.

Dates must be exact ISO \`YYYY-MM-DD\`; bounds are inclusive and restricted to at most 367 days (difference at most 366). For imported bank records the date refers to the supplied bank posting calendar date and is not silently converted from a merchant timezone. Other reports use UTC-created database dates, to be enforced by the read-only SQL consumers in P6-T002. Bank account ID, before-ID pagination cursor, currency and result limit are strictly validated (maximum 500 records per page). A canonical fixed-key representation and SHA-256 hash make equivalent inputs reproduce the **same query scope**, not a frozen database snapshot. Account/report authorization still belongs to each query consumer and cannot be inferred from the hash.

\`PerCurrencyTotals\` sums **exact unsigned integer minor units**, segregated by uppercase ISO currency, exponent and evidence/state bucket. It rejects floats, scientific notation, negative values, unsigned PHP overflows, invalid precision and more than 20,000 rows. Proposed allocation totals never silently merge with approved or committed totals. Totals cannot establish that a bank credit cleared or a WooCommerce order has been paid.

P6-T001 unit tests cover leap dates, invalid intervals, unknown or malicious filters, stable canonical hashes, distinct currencies/exponents/approval states, exact big integers and overflow rejection. No report SQL endpoint, CSV export, automatic email or dashboard is introduced here; those are separate later tasks.
