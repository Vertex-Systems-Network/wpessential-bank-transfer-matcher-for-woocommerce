# ADR-0014 — Finance report CSV export boundaries (P6-T003)

Status: implementation in progress, native/Playground CI verification pending.

## Decision

The six read-only report families share `ReportDefinition` canonical scope and source-backed `ReportQueryRepository`. `ReportCsvEncoder` serializes **only fixed per-report allowlisted fields** and refuses a mismatched filter fingerprint, authorized-payment flag, mutated-ledger flag, non-list data, more than the bounded page limit (maximum 500 rows), unsafe scalar types or output exceeding 1 MiB. A report result is an *as-of-query page*, not a cleared bank statement or full immutable ledger snapshot.

Every potentially executable spreadsheet value (a leading `=`, `+`, `-`, or `@`, including leading Unicode format/control/whitespace characters) is prefixed with an apostrophe before RFC-style CSV escaping. Exact amounts remain integer minor units; raw customer identifiers, bank reference strings, descriptions, filenames, mapping profiles and full account numbers are never in the export allowlist.

## Authenticated adapter

`ReportExportsPage` registers a WooCommerce-native finance submenu and **authenticated POST-only** `admin-post.php?action=wbm_pro_export_report` download. Both Free `APPROVE` and Pro `IMPORT` capabilities, a logged-in actor, a WordPress nonce and strict `ReportDefinition` filters are required. No storefront, unauthenticated, REST, cron or automatic download route is present.

`ReportCsvExportService` regenerates the canonical source report within the authenticated request, runs the allowlisted encoder, and logs an append-only successful-export metadata row in `wbm_pro_report_exports`. Only actor ID, report type, scope fingerprint, CSV SHA-256, row/byte counts and UTC timestamp are stored; not raw CSV/source references. If audit persistence fails the CSV is withheld. A database per-actor named lock serializes rate checks and enforces at most 3 exports per minute even with overlapping requests.

The response includes explicit `X-WBM-Has-More` and `X-WBM-Next-Before-ID` pagination headers; the UI cautions that one CSV is only one page, not a complete bank ledger. Caching is disabled. No order/payment/bank source or allocation state is changed by an export.

## Private bounded asynchronous export jobs

`AsyncReportExportJob` registers explicit Action Scheduler worker/expiry hooks, plus a WooCommerce-only authenticated, nonce-protected POST queue, private download, and cancel adapter. **This is not an automatic bank operation.** The user who queues a job must have Free `APPROVE` and Pro `IMPORT` capabilities. Workers re-check that actor's live capabilities before every read-only source page, temporarily establish the actor solely for the existing repository's permission contract, and restore the original user in `finally`. Download and status again require the original same actor's capabilities; download and cancellation require their own WordPress nonces.

Each task runs at most **one page of 100 rows**, persists a monotonic keyset cursor, and enqueues the next bounded action only after checkpoint persistence. Jobs stop after **10 pages, 1,000 rows, or 5 MiB**; wider sources fail closed with no partial downloadable CSV. Duplicate job execution, missing or non-progressing cursors, scheduling failures, cancellation, expiry, revoked actor capabilities, and unparseable scope cannot return a partial or unauthorized export. At most **two** unexpired ready/active jobs per finance user and **three** new jobs per minute are accepted under DB per-actor locking, preventing queue flooding.

No export file or temporary public upload URL is created. Only the fixed-allowlist CSV is stored in a private `MEDIUMTEXT` DB column, referenced by user-owned job ID. Download verifies its digest and byte count, rechecks original permissions/nonce, and records the action in the **same audited 3-downloads-per-minute** ledger as immediate page exports. A private Action Scheduler expiry event erases CSV content after two hours, and reads also deny on expiration if the scheduler is delayed. Cancellation wipes retained CSV and leaves the audit history intact. The admin page offers queued-job status and cancellation with no open endpoints.

Report pages are **not an atomic all-time ledger snapshot**: intermediate bank/order changes during paging may affect rows, and jobs fail rather than claim completeness when their scan limit is exhausted. A future snapshot export would require an explicitly versioned accounting read model.

## Gates still open

- Run full PHP unit/static/Playground plus disposable WordPress/WooCommerce/MariaDB export fixtures and the forged-nonce admin adapter checks at the latest branch SHA. Record actual results, not older jobs.
- Verify the newly implemented Action Scheduler private 10-page/1,000-row contract, expiry/cancellation/actor revocation, retry behavior, and cap enforcement in native/Playground at the latest branch SHA. Full-pipeline CI remains the source of truth; a code commit is not test evidence.
- Verify exact export audit pagination/role behavior, concurrency and failure modes; document operational user guidance. Mark P6-T003 done **only after** task scope and required integration evidence are complete.

Phase P6 cannot be merged into `main` until *all* P6 tasks T001–T008, export/redaction and no-oversend notification phase gates have passed; see `.ai/phase-merge-policy.md`.
