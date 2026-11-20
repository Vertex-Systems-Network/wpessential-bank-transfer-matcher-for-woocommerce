# ADR-0016 — Bounded, source-backed Pro finance dashboard (WBM-P6-T005)

Status: implemented on P6 draft branch, native classic/HPOS + Playground verification pending.

## Decision and financial truthfulness

The WooCommerce `Bank finance dashboard` screen is read-only and requires **both** Free `wbm_approve` and Pro `wbm_import` capabilities. It performs no approval, payment, order status transition, bank clearance or customer claim mutation.

The six report cards source their information through the existing `ReportDefinition` / `ReportQueryRepository` contracts, reusing their SQL/WooCommerce/HPOS-safe paths and audit interpretation:

- Outstanding unpaid BACS amounts — only native BACS, pending/on-hold orders, expected minus independently funded; not the same as bank-cleared amounts
- Partial funding — only independent confirmed allocation evidence, not automatically paid
- Unmatched/ambiguous imported credits — imported candidates, *never* proof of bank clearance
- Exception aging — counts grouped by age/state/currency, monetary exposure explicitly **non-additive** and **not a ledger amount**
- Import batches — batch/valid/error/warning counts, **not** money
- Allocation audit — independent states proposed/approved/committed/reversed, **not** a WooCommerce paid decision

**Every card represents one bounded query page** of at most 20/50/100 source records, and carries `has_more` when applicable. There is no unverified claim to full-store or full-ledger aggregates. Totals preserve exact integer minor units and distinct ISO currency, exponent and financial state keys. There is **no conversion, averaging, float math, cross-currency or cross-state financial sum**. Empty matching pages are distinguished from a missing or inconsistent source: schema/database/filter errors render `Source unavailable — not zero`. The focused report has its own filtered state/cursor; other cards continue to show their first bounded pages. Each view is an as-of-query page rather than a transactional snapshot spanning all six sources.

The browser presents UTC date filters (except imported bank credit `posted_date` uses source posting calendar), ISO currency or ALL, optional bank account ID, 20/50/100 rows/page, and a selected report-specific state. Query parameters are strictly validated through `ReportDefinition` and a dashboard-level allowlist; bad cursor IDs, unknown report or state, invalid dates/currencies and excessive page limits fail closed rather than widening scope. Detail tables expose fixed safe diagnostic fields only, no customer PII, receipt attachment, bank account number, raw bank reference, imported free-text or provider credentials. The screen follows native WordPress admin layout, table, form and action conventions.

## Test gate

- `tests/integration/pro-finance-dashboard.php` uses a disposable WooCommerce/MariaDB database and 21 synthetic USD + 1 synthetic EUR batches. It verifies exact first/next page counts, the keyset cursor, page totals separated by currency, unavailable versus empty source cards, bounded native admin rendering, anonymous denial and invalid input rejection.
- The test runs via `scripts/test-native-install.sh` in the CI's classic and HPOS modes. All other foundation static, packaging and Playground checks must pass on the **exact current phase branch SHA** before marking P6-T005 `done`.
- The phase gate still requires P6-T006 diagnostics/recovery, T007 retention/privacy/cleanup and T008 operational docs, plus no-oversend notifications. Owner-approved auto-merge happens only after all P6 tasks and exact-head CI pass, not now.

## Limitations

Saved Views are *not included* in this P6 implementation. Persistent user presets would require capability-scoped, strictly validated user metadata and their own privacy/retention policy. The current native filters are intentionally transient; there is no unsupported promise of saved views or global KPIs. Full-ledger aggregate dashboards would require a separate reconciled source, snapshot consistency model and tests before publication.
