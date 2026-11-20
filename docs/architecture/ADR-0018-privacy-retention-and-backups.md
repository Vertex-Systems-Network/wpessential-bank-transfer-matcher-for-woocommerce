# ADR-0018 — Explicit merchant retention, privacy boundaries, bounded cleanup and safe backups (P6-T007)

Status: implementation on P6 draft branch; exact-source native/Playground CI verification required before marking T007 done.

## Consent and preservation boundaries

The Pro retention system defaults to **automatic cleanup OFF** and `private_source_days=0` (**retain original private statement files**). The WooCommerce **Bank data retention** page is available only to the current Free `wbm_approve` + Pro `wbm_import` + Pro `wbm_manage_bank_accounts` manager, with nonce-protected POST actions and a separate explicit acknowledgment to enable daily Action Scheduler cleanup. Saving retention settings alone does not run cleanup. A separate confirmed manual POST can run one bounded pass even with recurring cleanup disabled, visibly stating that deleted files/metadata cannot be recovered from the plugin.

The database ledger and all records needed to prove transfer decisions are **not in any cleanup query**: WooCommerce orders/order meta, payment completion state, immutable payment references, customer payment claims, manual bank evidence, imported transaction/canonical rows, allocations, proposals, approvals, correction/overpayment/mismatch cases, maker-checker audit and review comments/history. No job or reminder creates a payment, authorizes a transfer or sends an email.

### Data inventory and default policy

| Data class | Default | Operator choices | Cleanup |
| --- | --- | --- | --- |
| Private report-export CSV bytes | fixed 2-hour TTL | not extendable | per-export Action Scheduler expiry plus bounded retention fallback; download fails on expiry even if cron stalls |
| Diagnostics download metadata (actor, digest, byte count, UTC) | 90 days | 30/90/180/365 days | opt-in/manual only, maximum 25 eligible rows per pass |
| Opted-in notification delivery reservation audit (recipient ID, status, date) | 180 days | 90/180/365 | opt-in/manual only, no email addresses/body |
| Failed-job recovery audit (actor, source job ID, reason SHA256, result) | 365 days | 180/365/730 | opt-in/manual only; expiry never touches financial evidence |
| Finance CSV download audit (actor, scope SHA256 and CSV digest) | 365 days | 180/365/730 | opt-in/manual only; financial records remain intact |
| Original private uploaded bank statement files | **never automatically** | 0 (keep), 30/90/180/365 days | only after explicit nonzero selection, and only for **completed/cancelled** imports beyond selected age |
| Source row identities, bank transaction records, balances, approvals, WooCommerce financial state | retain; merchant's accounting legal hold | none through this tool | never delete through retention service |
| Retention execution audit metadata | retained | no automatic purge in this implementation | records actor/policy hash, coarse counts and status without file names or bank data |

All selected retention windows are strictly enumerated, values must form the complete allowlisted schema, and a corrupt stored policy fails closed. Without explicit merchant opt-in, no scheduled daily retention Action Scheduler action exists. On explicit disable, future scheduled jobs are unscheduled. Manual passes require a dedicated purpose-specific nonce and an irreversible-action checkbox. Source and user-specific options do not grant new capabilities; the live finance manager role is checked again at every POST.

### Bounded, auditable safe deletion

The worker takes a per-site database named lock, records an immutable `started` audit row first, processes **up to 25 records per source per run**, and records counts, policy SHA256 and completed/failed state. An interrupted multi-source pass may be partially complete: each operation is idempotent, and a subsequent explicitly permitted run resumes from remaining eligible rows. The worker deletes no arbitrary files or tables.

Private statement deletion additionally requires that the batch is in immutable terminal `completed` or `cancelled` state, older than the chosen cutoff, **not** pending/processing/failed; the source path points to a regular nonsymlinked randomly named `48 hex characters + .csv` file in the trusted private import directory **outside WordPress/document root**; and its SHA256 matches the immutable batch file digest. Unexpected path, symlink, incorrect hash or bad directory means **no deletion** and visible failure. Once successfully unlinked, only the no-longer-valid source-path pointer is cleared. The import-batch provenance (including source digest) and canonical row data remain. Missing known private files can have their stale pointer cleared without deleting unrelated paths.

Expired private export bytes are cleared, original scope metadata remains for audit. In any case, **a report must never become downloadable after the original TTL**; direct download checks expiry independently of scheduler timing.

### Customer privacy handling

The Free plugin provides WordPress personal-data export/erase of customer payment claims and redacts optional sender name, customer note and attachment on approved erasure; financial reference/date/amount/status and audit records remain for reconciliation. Pro imported bank statements may contain arbitrary third-party free-text; they cannot be reliably joined to a data subject by email address, so an automatic blanket erase/export matching guessed names is unsafe. Merchant must manually assess and respond to rights requests under applicable law, preserve lawful accounting/audit obligations and control backup copies. Provide only redacted support exports and synthetic issue reproductions; never attach raw bank statements or CSVs to tickets.

### Backup, migration and restore runbook

1. Back up WordPress/WooCommerce order storage **and** the Free/Pro reconciliation tables and `wp_options` versioned migration/retention/notification settings as one consistent, encrypted database snapshot. Choose a quiescent period or suspend Action Scheduler workers to prevent divergent checkpoints.
2. Original private import CSVs are held *outside* WordPress's public uploads/web root, so common WordPress-only backups **do not include them**. If they must be retained for still-recoverable failed imports, back up the private directory separately to encrypted access-controlled storage while retaining exact file bytes, hashes and restrictive permissions (0700 directory, 0600 files). Never write it to a public URL or issue attachment.
3. Record WooCommerce/WordPress/PHP/MariaDB, plugin edition versions, enabled classic/HPOS mode, database prefix, Action Scheduler queue state and data retention choices. Keep audit checkpoints, reference indexes, and environment-independent manifests; verify data-restoration SHA256 before resuming workers.
4. Restore into an isolated nonproduction environment first. Restore database, compatible Free before Pro, then any allowed private import file tree to a trusted **non-web** path; ensure matching `WBM_PRIVATE_IMPORT_DIR` if changed. Run idempotent versioned migrations via the plugin and verify report/ledger invariants in classic and HPOS. Restore WordPress task queue carefully; avoid scheduling duplicate outstanding work from both old and new stores.
5. If private source paths are not restored or their hashes differ, **do not bypass provenance validation**: failed imports cannot be retried and must follow merchant-approved controlled reimport/review. No automation marks a bank transaction cleared or marks an order paid from a restored statement.
6. Backups may retain records already erased from the live store; set backup access, expiry and deletion rules separately according to legal retention, privacy rights, disputes and recovery obligations. Revoke any temporary support access after investigation.

### Validation and phase gate

Pure fixture `tests/finance-retention-policy.php` rejects unknown/incomplete/unsafe policy windows, confirms automatic cleanup OFF and private source retain default. Native disposable fixture `tests/integration/pro-finance-retention.php` verifies explicit consent, missing/forged nonce denial, automatic worker no-op while disabled, old source untouched at default 0 days, explicitly approved 30-day terminal source cleanup, preserved import batch status, expired private export CSV erasure, bounded old support-audit cleanup, idempotency and scheduling opt-out. Admin forged nonce and nopriv hook checks are in `tests/integration/pro-finance-admin-security.php`. **Only exact-head full Foundation/static/Playground + native classic/HPOS MariaDB success allows marking T007 done.**

P6-T008 requires plain-language operator steps, support examples and troubleshooting guides. Phase PR #12 merges automatically under the owner's stored policy **only** after all P6 tasks, security/no-oversend gate, complete latest SHA CI and branch protections pass. No production cleanup, deployment, marketplace publication or bank transaction is executed by this development work.
