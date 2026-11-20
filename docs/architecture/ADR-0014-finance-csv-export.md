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

## Verified P6-T003 evidence and remaining downstream gates

- **P6-T003 implemented and native+Playground verified** at exact development code commit `13bbbee13d685c58836a251042c1f0fc3567f4fe`: [GitHub Actions 44653642849](https://github.com/Vertex-Systems-Network/wpessential-bank-transfer-matcher-for-woocommerce/actions/runs/44653642849), foundation PHP/static/unit+Playground and disposable WooCommerce/MariaDB fully SUCCESS.
- The synthetic native tests cover all six allowlisted schemas, CSV formula injection/PII exclusion, nonce/finance authorization, one-page audit throttling, 106-row/two-page queued export, ready-only download, no extra headers, user-bound access, 3 queued downloads/minute, 2 live jobs, 3 enqueues/minute, cancellation, expiry and duplicate-worker safety. The `unique=true` continuation bug was **discovered in actual failing CI** 44653254349/44653246116 and fixed at `13bbbee`, not papered over.
- **Follow-on P6-T006**: improve operational failed-job detection, explicit restart/repair guidance, and redacted diagnostics. **P6-T007**: define retention/privacy/backup cleanup. **P7/P8**: end-to-end UI screenshots, capability-revocation and manual browser/RTL QA. These are downstream tasks, not grounds to claim release readiness today.
- **P6 remains incomplete** until P6-T004 notifications, T005 dashboard, T006 diagnostics/recovery, T007 privacy, T008 full operational docs and the exact *phase gate* pass. Phase PR #12 is still draft and must not be merged yet.
