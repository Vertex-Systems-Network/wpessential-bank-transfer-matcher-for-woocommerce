# 2026-11-20 — P6-T005 source-backed finance dashboard implementation

**Current task: P6-T005 IN PROGRESS.** P6-T004 was verified at exact SHA `6318c3e`, full GitHub Actions 44656913883 and 44656906701 SUCCESS. Task graph now 68 done / 1 in progress / 34 planned.

## Code committed on the P6 draft branch

- `FinanceDashboardSnapshot.php` composes existing six independent report sources as bounded read-only query pages. Strict focus filters/cursors, no payment/ledger mutations, explicit source-availability state, exact page-only grouped currency/exponent/financial-state minor units. Missing schema or invalid source is **unavailable, not zero**.
- `FinanceDashboardPage.php` adds native WooCommerce finance submenu for users with both Free `wbm_approve` and Pro `wbm_import`; filters by date (UTC), bank account ID, ISO currency, focused report/state, 20/50/100 page size. Native WP cards and a fixed safe field subset of focused rows, with keyset next-page link and prominent financial caveat. No PII/raw bank references.
- `tests/integration/pro-finance-dashboard.php` stages 21 USD + 1 EUR synthetic import batches, checks exact currency separation, first/next cursor page, missing-source statuses, malformed filters, anonymous denial and HTML rendering. Wired into `scripts/test-native-install.sh` for classic + HPOS native MariaDB.
- `docs/architecture/ADR-0016-finance-dashboard-page-scopes.md` documents scope and deliberate exclusion of persistent Saved Views; full-ledger KPIs are not guessed from a page.

## Required next action

Inspect exact current phase-branch SHA CI runs, particularly `mariadb-migrations` and `foundation`. Fix native or PHP/Playground failures. Mark T005 done only after **full CI passes at the actual latest commit SHA**, then proceed T006 diagnostics/recovery without routine approval. P6 PR #12 stays DRAFT until all phase tasks T001–T008 and P6 financial/notification safety gate verified; no external release/production payment actions. Issue #7 remains open.
