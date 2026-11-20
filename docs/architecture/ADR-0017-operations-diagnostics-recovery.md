# ADR-0017 — Redacted operational diagnostics and deliberate failed-job recovery (P6-T006)

Status: implemented and verified at exact T006 code SHA `9e0c9be`; full GitHub Actions 44661739149 and 44661731639 SUCCESS.

## Scope and authorization

The WooCommerce **Bank operations** screen is restricted to current logged-in finance operators with *all three* capabilities: Free `wbm_approve`, Pro `wbm_import` and Pro `wbm_manage_bank_accounts`. It is a manager-only operational support screen, not a customer claim endpoint, bank-credentials viewer or payment reconciliation action. No new REST/public endpoints or admin GET mutations. Every diagnostics download and recovery is an authenticated POST with its own purpose-specific WordPress nonce and repeated server-side capabilities check.

The bounded read-only `FinanceOperationsSnapshot` projects, from installed source tables, at most 20 newest rows per source: bank statement import batches, private report export jobs and notification delivery audit. It displays internal job ID, state, coarse age band, processed row count and errors/warnings where appropriate. An absent source is **unavailable, not zero**, and an invalid state/counter/timestamp fails closed. Results are expressly **page-scoped**, not full-store/ledger totals or evidence of cleared funds.

## Privacy-preserving diagnostic export

A separate read-only, audited CSV export serializes only constant source/category labels, allowlisted job states, age bucket, nonnegative bounded row counts, and a page-scoped marker. It explicitly excludes source/job/user/recipient/account IDs, raw bank references, upload filenames, filesystem paths, original statement hashes, private report JSON/CSV, customer details, order data, email addresses, reasons and financial amounts. A fixed serializer rejects unknown sources and oversized/invalid rows; buffer cap is 128 KiB and maximum 60 source records (20 per source). No user-controlled CSV cells are present.

Each downloaded CSV requires a validated manager nonce and is independently audited with actor ID, SHA256, byte count and UTC creation in `wbm_pro_diagnostics_exports`. Per-manager named lock enforces a maximum of **3 support exports per rolling hour**. If the audit write or rate check fails, CSV output is denied.

## Explicit failed-job recovery

`FinanceJobRecovery` only accepts two enumerated actions: `retry_import` for a currently **failed** import batch, or `requeue_export` for a currently **failed**, unexpired private report export **owned by that same finance actor**. A 12–500-character UTF-8 printable human reason and a distinct manager POST nonce are required. Only the reason's SHA256 is persisted, not any potentially sensitive free text. Per-actor DB locking, 3 requests/hour, and a per-original-job success/reservation check prevent repeated or concurrent retries. Every attempted recovery after eligibility checks persists a `requested` audit event before scheduling, then marks `queued` or `blocked`. Uncertain audit-finalization remains reserved instead of blindly retrying.

Import replay delegates to the existing `ImportJob::retry` which *must* revalidate the exact private source file's SHA256 against original import batch provenance before CAS transitioning failed → processing. `ImportJob` also checks first-chunk and subsequent Action Scheduler enqueue results; a failed enqueue never silently claims successfully queued work. Import continuation uses nonunique Action Scheduler callback because the currently running chunk may still exist; transaction row-key uniqueness and status/CAS guards make duplicate delivery safe. A recovered import **only stages unverified bank rows**; it cannot allocate money or pay an order.

Failed report export recovery does **not** resurrect or mutate original job/content: it validates the versioned original `ReportDefinition`, then creates a **new**, bounded `AsyncReportExportJob` using all existing actor, concurrency, creation-window and expiry controls. The failed original remains for audit. The new export remains private and requires its own nonce, original actor capabilities and shared audit/rate limit before download. Expired jobs, cancelled jobs, tampered scopes, missing source files or changed finance roles are never recovered.

## Synthetic verification

`tests/integration/pro-finance-operations.php` uses a disposable WordPress/WooCommerce/MariaDB fixture: tampered private bank CSV fails recovery, restored SHA256 permits explicit retry, a synthetic failed private export yields a *new* job, duplicate requeue and forged nonce fail, missing roles deny, the native admin page is privacy-safe, and three redacted CSV exports pass with the fourth blocked. It deletes the synthetic jobs, scheduled actions, recovery and export audit records after each run. All test sources are synthetic; no real finance email, credit, payment action or production store are contacted.

`tests/integration/pro-finance-admin-security.php` separately checks that the authenticated admin_post hooks exist, nopriv hooks do not, and a forged nonce is denied before the service is called. Native classic and HPOS tests plus PHP/static/unit and packaged Playground **at the exact latest phase branch SHA** **passed at exact SHA `9e0c9be` in runs 44661739149 and 44661731639**; P6-T006 is verified. Browser/manual UI validation is P7/P8 scope.

## Follow-on obligations

P6-T007 must specify retention/cleanup of private export files and DB values, diagnostic audit metadata, recovery audit entries, source imports, bank source privacy and backup/restore safety; P6-T008 provides operational user instructions. The whole phase may be merged into `main` under the owner's recorded policy *only when* P6-T001–T008 and the complete phase safety gate are green. No WordPress marketplace release, production deployment, bank settlement or real-money actions are authorized by this ADR.
