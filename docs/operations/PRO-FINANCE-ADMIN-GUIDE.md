# Bank Transfer Matcher Pro — Finance administrator operating guide

**Audience:** WooCommerce store administrators, finance managers, verified import/reconciliation staff and site-maintenance operators. **Scope:** Free + Pro plugin installation, offline bank transfer review, operational reports, notices, private exports, failure recovery, privacy and backups. This is an admin runbook, **not** advice to treat imported bank rows as cleared funds.

**Product safety invariant:** importing a statement, customer submitting a claim, an automated matching suggestion, a report balance, a review-case assignment, CSV download or sending a digest **does not** authorize a payment, settle a bank transfer, clear a financial hold or mark a WooCommerce order paid. Evidence must be independently verified through the merchant's normal bank reconciliation and approval controls.

## 1. Install and authorize

1. Verify WordPress, PHP, WooCommerce, Free/Pro edition versions and plugin dependencies match the release manifest. Install **Free first**, activate it, then install and activate the compatible Pro ZIP. Never install Pro without Free; Pro's dependency gate is designed to refuse activation.
2. Ensure WooCommerce BACS is enabled and the merchant's own BACS account instructions are correct. The plugin **does not** automatically replace native bank account configuration or redirect transfers.
3. Under **WooCommerce**, the Pro UI offers *Matcher bank accounts*, *Statement mappings*, *Import statements*, *Bank transactions*, *Bank review queue*, *Finance allocations*, *Bank finance dashboard*, *Finance reports*, *Finance notifications*, *Bank operations* and *Bank data retention*. Menu availability depends on the logged-in user's actual capabilities.
4. Free financial review roles use `wbm_view`, `wbm_review_claim` and `wbm_approve`. Pro import requires `wbm_import`. Sensitive management tasks—creating/archiving bank accounts, selecting notification recipients, recovery actions, diagnostics and retention—also require `wbm_manage_bank_accounts` **and** Free approval + Pro import.
5. By default administrators have both Pro permissions; shop managers have Pro import but **not** bank-account management. A user with import alone must not manage global recipient lists or retention. Do not substitute a nonce for capabilities; sessions are rechecked on every sensitive POST.
6. Create a current tested backup and confirm the private source staging directory is outside both WordPress and the public document root before any import. Keep permissions restrictive. Never forward bank files to a support ticket.

## 2. Register bank account metadata and mapping profiles

**WooCommerce → Matcher bank accounts:** create metadata for each receiving bank account using a safe label, bank identity and ISO currency. Verify bank ownership and receiving details **outside** the plugin. Account records are not proof of payment; archive obsolete entries rather than silently reassigning historic sources.

**WooCommerce → Statement mappings:** define an exact CSV mapping profile: delimiter, bank posting-date header/format, amount header, decimal/thousands separators, fixed or explicit ISO currency, source reference header and transaction-ID header. Preserve bank transaction IDs as text so leading zeroes remain intact. Treat a profile change as a new interpretation of the bank statement, not an authorization to change already-approved amounts. Export or capture the selected profile/configuration in a secured audit runbook before a migration.

Do not mix currency exponents or automatically convert USD/EUR/other ISO amounts. Malformed money, invalid dates, negative/ambiguous credits, reimports and duplicate fingerprints must be reviewed rather than silently accepted as cleared incoming funds.

## 3. Statement import — preview, commit, inspect

**WooCommerce → Import statements** is intentionally two-stage:

1. Choose the registered receiving account, mapping profile and a CSV source inside configured file-size limits (the staging backend enforces a 20 MiB cap).
2. Select **Validate and preview**. Inspect mapped dates, ISO currency, amount sign, transaction ID, duplicate warnings and invalid rows. A debit is not an incoming credit. Validate the bank statement against an independent bank portal/source, not just a matching order reference.
3. Select **Commit import** only once the preview and bank account/currency are correct. Import creates a private, bounded source-backed staging job; it **does not pay any order**.
4. Track `pending → processing → completed` in the import history. Invalid rows, warnings and counts are separate. Action Scheduler stages chunks of up to 100 rows with transactional checkpoints and duplicate row keys.
5. On `failed`, use the explicit retry controls only if the **original private source file still matches its SHA256**. A missing/changed source must stay failed; do not bypass the check or copy an unrelated statement over it. If recovery is not possible, follow a controlled merchant-approved reimport/review process.
6. On an incorrect import or compromised source, do not delete matching accounting evidence as a shortcut. Stop further approvals, document the incident, consult the correction/reversal workflow and preserve finance audit trails.

Private source file paths and bank-supplied free text are confidential and must not be copied into WordPress comments, issue trackers or support exports.

## 4. Bank transaction review and maker-checker

**WooCommerce → Bank transactions** shows imported source-backed transaction rows. A missing reference, ambiguous credit, duplicate fingerprint, currency mismatch, outgoing debit or previously allocated source requires attention. Do not “fix” a bank transaction by changing the original source transaction ID.

**WooCommerce → Bank review queue** shows generated review cases, assigned reviewer, priority, status, explanation codes, comments and history. Cases are hypotheses/investigations, not bank clearance. Assign to an eligible finance reviewer, document the independent evidence, and use explicit state transitions. Avoid customer PII in case comments.

**WooCommerce → Finance allocations** supports explicit proposals, approval and investigation/hold actions. A *proposal* is not an approved allocation; an approved allocation is not automatically a WooCommerce paid order. Maker/checker separation, optimistic/transactional locking, independent bank account evidence, exact minor-unit arithmetic and order eligibility are enforced in their respective source workflows. Only a separately authorized finalization path should ever set paid status after the complete independently verified amount and eligibility are satisfied. For partial funding, show the remaining unpaid amount; never mark a partially funded BACS order fully paid.

### Synthetic example (no real bank account or customer)

A BACS order #5001 has total **USD 125.00**. Synthetic private CSV includes a +USD 100.00 transaction with a compatible reference, +USD 25.00 from a separate verified bank transaction, a possible duplicate +USD 25.00 and an outgoing −USD 15.00 debit. Preview all four rows, retain duplicate/debit warnings, and review bank source identity. If **only** the independent USD 100.00 evidence is verified and allocated, the outstanding unpaid amount is USD 25.00. The possible duplicate USD 25.00 must **not** be counted automatically. After a separate USD 25.00 source is independently confirmed, follow the explicit maker/checker allocation and finalization workflow; imported rows and reports alone must never cause an order-paid transition. This is a demonstration, not permission to bypass merchant bank clearance.

## 5. Finance dashboard and reports

**WooCommerce → Bank finance dashboard** provides six **bounded, read-only report pages**, not global ledger totals. Filters: UTC date range, ISO currency or `ALL`, receiving bank account ID (0 = all), focused report and its valid state vocabulary, and page sizes 20, 50 or 100. A selected card can be filtered and navigated with a stable ID cursor. If a source is not installed or inconsistent, the screen displays **unavailable**, never “0”.

Report families:

| Report | Interpretation | Financial caveat |
| --- | --- | --- |
| Outstanding BACS orders | pending/on-hold native BACS expected less independently funded | No bank clearance claim |
| Partially funded BACS | amounts still due on partially funded eligible orders | Included in outstanding: **never add these card totals together** |
| Unmatched/ambiguous credits | imported candidate incoming credits without confirmed allocation | Duplicates, debit signs and missing references require separate review |
| Exception aging | unresolved exception case counts, age bands and holds | Potential exposures overlap; **non-additive** and **not a cash balance** |
| Import batch summaries | import states and valid/error/warning counts | Batch counts are not money |
| Allocation audit | proposed/approved/committed/reversed source-state records | Keep state categories distinct; proposed ≠ approved/paid |

Amounts are exact integer **minor units**, always separate by ISO currency, exponent (e.g., 2), and financial state. Do not sum currencies, exponents, case exposures or report families as if they were one balance. A page showing 20 rows with `has_more` does not claim all records were counted. Saved Views and cross-report bank-balance aggregates are **not** included in this phase.

**WooCommerce → Finance reports** generates six corresponding fixed-field CSVs. Immediate export: at most **500 rows / 1 MiB**, one report page, at most **3 downloads per finance user per minute**, audited. To get a following page use the returned cursor; do not concatenate without checking date/filter/snapshot consistency. Queued export: Action Scheduler at most **10 pages × 100 rows = 1,000 records**, up to **5 MiB** in the private DB, **2 active/downloadable jobs per user**, **3 new jobs per minute** and a **2-hour download TTL**. An incomplete/failed/expired job cannot be downloaded; a larger or changing dataset requires narrower filters. No bank references, full account numbers, customer personal data, original filenames, arbitrary columns or credential values are exported. CSV formula-injection protection is applied.

## 6. Optional finance notifications

**WooCommerce → Finance notifications** starts **OFF**. A bank-account manager explicitly selects one or more of four fixed, plaintext digest templates: open exceptions, pending finance reviews, bank statement mismatches, and unresolved reviews older than 48 hours. Select at most 10 actual, currently permissioned WordPress finance user IDs; arbitrary email addresses and customer recipients are not accepted.

**Preview** displays an internal non-sending sample. **Send test** is an explicit privileged POST to previously opted-in eligible recipients; it counts toward the same quotas as scheduled digests. The scheduled worker only runs after saving opt-in and only when unresolved source-backed work exists. Each recipient is limited to **2 accepted sends per rolling 24 hours**, with one same-type digest per 24 hours. Delivery audit records acceptance/reservations, **not confirmed inbox delivery**. If notice volume seems high: disable the digest, inspect the audit/queue, and never attach raw statements to emails.

## 7. Troubleshoot and recover failed jobs

**WooCommerce → Bank operations** requires bank-account management plus both finance caps. It shows only **20 latest rows per source** for import batches, report exports and mail-delivery audit, grouped by job status, coarse age and counts. Missing source schema is **unavailable**, not a healthy zero.

- **Redacted diagnostics CSV** requires explicit POST/nonce and is audited at at most **3 downloads per user per hour**. Its fixed fields are source, status, age band and counts; it intentionally excludes *all* job IDs, actor IDs, account numbers, customer/email data, filenames, paths, financial amounts, source hashes, source CSV and arbitrary notes. It is bounded to **60 records / 128 KiB**.
- **Retry a failed import:** provide a printable 12–500-character reason and click **Verify file & retry**. The original file must exist privately with exactly its original hash, and only a failed batch can be retried. This resumes unverified staging only; it never approves money. Wrong hashes, jobs already completed, or missing private sources block recovery.
- **Requeue own failed export:** use **Requeue own failed export** on the originally owned, still-unexpired *failed* job. It validates the original scope and creates a **new** bounded private export job, leaving the original failed record intact. It does not resurrect old CSV bytes or broaden filters.
- Recovery is manager/nonce-gated, metadata-audited with a SHA256 of the reason (the reason text is not persisted), rate-limited to **3 attempts per finance actor per hour**, and prevents repeat successful retries of one original. In case of persistent failure or suspected compromise, preserve audit state and escalate rather than circumventing controls.

## 8. Privacy, retention and backup

**WooCommerce → Bank data retention** starts with **automatic cleanup OFF** and completed/cancelled original private source CSV retention set to **never delete**. A manager can select allowed metadata retention windows, explicitly consent to daily scheduled cleanup, or run one confirmed manual pass. Each pass handles at most 25 eligible rows per source, takes a site lock and records privacy-safe counts/policy hash/status. The 2-hour private report CSV TTL remains independently enforced.

Deleting a completed/cancelled source CSV requires a merchant-selected nonzero number of days, an eligible terminal batch age, a random private basename, an outside-webroot path and an exact original source SHA256. Failed, pending and processing imports are **not** purged. Financial ledger records, imported canonical rows, customer claims, approvals, payment states and WooCommerce orders are never targeted by this cleanup. Do not enable deletion without checking legal holds, dispute windows, bank record retention and local privacy obligations.

**Backup and migration:** a WordPress database backup alone does **not** guarantee that private import files outside the web root are included. Back up Free/Pro tables, versioned migration options, WooCommerce classic/HPOS order storage and the separately private staging directory as a consistent encrypted recovery set. Restrict and encrypt access; restore on a disposable store first; preserve source file hashes and directory path configuration; reactivate compatible Free before Pro; validate exact-money and maker-checker invariants before Action Scheduler resumes. A lost/tampered source must not be “fixed” by disabling hash verification. See [ADR-0018](../architecture/ADR-0018-privacy-retention-and-backups.md) for the full data inventory, retention windows, operational purge constraints and backup/restore steps.

## 9. Daily and weekly operational checklist

**Daily finance review:** check pending and on-hold BACS orders, verify receiving accounts outside the plugin, review only legitimate bank credits, inspect duplicates/missing references, assign review cases, investigate holds, and explicitly approve only independently verified evidence. Review import failures and warning counts without treating them as bank clearance.

**Weekly:** inspect oldest unresolved cases and case aging, confirm non-over-sending notifications, reconcile import/reconciliation source counts, verify export/notification Action Scheduler queue health, and check private staging/backups access. Confirm daily privacy cleanup remains disabled or explicitly authorized under the merchant's policy.

**Before any release/upgrade:** take secure tested backup, confirm version/dependency compatibility, run dry-run/source migration tests, verify Free and Pro independently package/install, test native WooCommerce classic and HPOS with MariaDB, and inspect no regressions to exact-currency, allocation locking, duplicate prevention, nonce/capability security and failed-job recovery. The P7/P8 browser/manual accessibility/RTL/visual QA and subsequent full release gates still apply; passing P6 alone is not marketplace release permission.

## 10. What to include in a support report

Include plugin Free/Pro versions, WooCommerce/WordPress/PHP/MariaDB versions, classic vs HPOS mode, sanitized action/time (UTC), source report type, status or **redacted** diagnostics CSV, and an issue description using **synthetic** reference/currency/order examples. Never include passwords, bank account numbers, real bank statement CSV, file paths, unredacted screenshots of customers, receipt attachments, private export tokens, source fingerprints or production DB dumps.

**Data-integrity rule:** if a supporting source is missing or changed, stop the related recovery/approval operation, keep the financial hold and escalate through the controlled audit/correction process. Do not invent missing funds or mark an order paid to silence an exception.
