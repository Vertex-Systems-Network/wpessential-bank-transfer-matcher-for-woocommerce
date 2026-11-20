# ADR-0014 — Finance report CSV export boundaries (P6-T003)

Status: implementation in progress, native/Playground CI verification pending.

## Decision

The six read-only report families share `ReportDefinition` canonical scope and source-backed `ReportQueryRepository`. `ReportCsvEncoder` serializes **only fixed per-report allowlisted fields** and refuses a mismatched filter fingerprint, authorized-payment flag, mutated-ledger flag, non-list data, more than the bounded page limit (maximum 500 rows), unsafe scalar types or output exceeding 1 MiB. A report result is an *as-of-query page*, not a cleared bank statement or full immutable ledger snapshot.

Every potentially executable spreadsheet value (a leading `=`, `+`, `-`, or `@`, including leading Unicode format/control/whitespace characters) is prefixed with an apostrophe before RFC-style CSV escaping. Exact amounts remain integer minor units; raw customer identifiers, bank reference strings, descriptions, filenames, mapping profiles and full account numbers are never in the export allowlist.

## Authenticated adapter

`ReportExportsPage` registers a WooCommerce-native finance submenu and **authenticated POST-only** `admin-post.php?action=wbm_pro_export_report` download. Both Free `APPROVE` and Pro `IMPORT` capabilities, a logged-in actor, a WordPress nonce and strict `ReportDefinition` filters are required. No storefront, unauthenticated, REST, cron or automatic download route is present.

`ReportCsvExportService` regenerates the canonical source report within the authenticated request, runs the allowlisted encoder, and logs an append-only successful-export metadata row in `wbm_pro_report_exports`. Only actor ID, report type, scope fingerprint, CSV SHA-256, row/byte counts and UTC timestamp are stored; not raw CSV/source references. If audit persistence fails the CSV is withheld. A database per-actor named lock serializes rate checks and enforces at most 3 exports per minute even with overlapping requests.

The response includes explicit `X-WBM-Has-More` and `X-WBM-Next-Before-ID` pagination headers; the UI cautions that one CSV is only one page, not a complete bank ledger. Caching is disabled. No order/payment/bank source or allocation state is changed by an export.

## Gates still open

- Run full PHP unit/static/Playground plus disposable WordPress/WooCommerce/MariaDB export fixtures and the forged-nonce admin adapter checks at the latest branch SHA. Record actual results, not older jobs.
- Review >500-row asynchronous job need and establish a safe bounded job contract, expiry, cancellation, access-controlled download, duplicate/retry rules, and privacy retention. Do not expose an unbounded sync or unattended export as a substitute.
- Verify exact export audit pagination/role behavior, concurrency and failure modes; document operational user guidance. Mark P6-T003 done **only after** task scope and required integration evidence are complete.

Phase P6 cannot be merged into `main` until *all* P6 tasks T001–T008, export/redaction and no-oversend notification phase gates have passed; see `.ai/phase-merge-policy.md`.
