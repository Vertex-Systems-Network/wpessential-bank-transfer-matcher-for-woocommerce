# Bank Transfer Matcher Pro — Finance incident and recovery playbooks

This companion to [the Pro admin guide](PRO-FINANCE-ADMIN-GUIDE.md) is for **authorized finance managers only**. Import/review/export workflows never constitute confirmation of cleared funds, and recovery jobs never authorize WooCommerce paid status.

## Incident levels and evidence hygiene

- **P0 — suspected financial state inconsistency:** wrongly paid order, double allocation, incorrect ledger/source identity, bank mismatch, leaked private bank statement, unauthorized finance recipient or forged approval. Immediately suspend related operational approvals and/or Action Scheduler job source, preserve audit events, inspect the WooCommerce order through its secure original store and independent bank records, and escalate to the authorized finance incident owner. Do **not** “repair” by editing raw SQL status, deleting bank rows or marking the order paid.
- **P1 — blocked reconciliation:** legitimate failed statement import, invalid mapping, importer checkpoint failure, duplicated/ambiguous credit, partial funding discrepancy, expired export, or unresolved review hold. Keep outstanding balances unconfirmed; investigate safe source evidence, metadata-only diagnostics and role capabilities.
- **P2 — operational/reporting issue:** missing dashboard data source, malformed report filter, a notification intentionally suppressed by quota, or an expired support diagnostic. Do not infer zero cash from unavailable sources.

All support tickets use synthetic examples and the authorized **redacted** diagnostic CSV. Never include file paths, bank/customer/account data, private CSV, source hashes, financial credentials, recovery notes or unredacted screenshots. Restrict evidence access and log who reviewed the incident.

## Runbook 1 — Statement import failed or import chunk stuck

1. In **WooCommerce → Import statements**, note batch ID, account currency, the recorded counts and state. A \`failed\` job is not cleared money; never approve any of its bank rows just to unblock processing.
2. Check the private staging directory is outside the public web root and readable with restrictive filesystem permissions. Do not move or rename source files arbitrarily, and never attach them to a ticket.
3. In **WooCommerce → Bank operations**, compare import batch state and counts. Only a \`failed\` batch with an intact original private file and matching immutable source SHA256 is eligible for **Verify file & retry**. Provide the 12–500-character reason (stored as a SHA256 digest in the audit; no personal information in the reason).
4. If source missing/modified or original mapping snapshot is invalid, **do not override hash or mapping checks**. Leave the batch failed. Merchant may choose a controlled new import, then investigate duplicates/overlap; imported files and source transaction identities are not silently replaced.
5. Retry should enqueue only unverified 100-row source-staging chunks. Check \`processing\` then \`completed\`, and compare error/warning counts with the original source. New or duplicate staged rows cannot be payment evidence without review.
6. If Action Scheduler shows a queued callback but no progress, investigate queue health and DB availability. Import enqueue failures are explicit; the current action can still be pending so next-chunk scheduling intentionally relies on row idempotency and transactional checkpointing rather than an unsafe duplicate scheduling guarantee.

**Never do:** delete \`wbm_pro_import_rows\` to force a retry; bypass source SHA256; write imported amount directly to an order payment; change source transaction IDs to match an order.

## Runbook 2 — Private report export failed or expired

1. Check **WooCommerce → Finance reports** job status and expiry, original actor permissions, currency/date/account filters, page limit and Action Scheduler availability.
2. A failed/expired export is not downloadable; CSV bytes are private in the database and time-bound. Failed jobs do not leak partial CSV.
3. For an *unexpired failed job owned by the current finance manager*, **WooCommerce → Bank operations → Requeue own failed export** creates a **new** job with independently validated original \`ReportDefinition\`. It does not mutate or resurrect the original failed job. Observe 3 recovery attempts/hour, 2 live jobs/user, 3 queue starts/minute, 1,000 rows/5 MiB and 2-hour TTL.
4. Expired jobs cannot be requeued. Create a new export in Finance reports with fresh, narrower filters. Data may have changed between report pages; never certify a multi-page report as an atomic financial snapshot unless independently reconciled.
5. Use the metadata-only **Download redacted diagnostics CSV** to report the failure, not the private report CSV or bank statement.

**Never do:** turn off nonce or owner checks, increase unbounded limits, expose exports via a public uploads URL, or mark a source paid to improve a dashboard KPI.

## Runbook 3 — Suspected duplicate imported credits or maker-checker disagreement

1. Keep any unresolved source/funds under investigation or hold. Compare independent bank posted date, real incoming amount, currency exponent, receiving bank account and transaction identity against original bank records.
2. **WooCommerce → Bank transactions** and **Bank review queue** distinguish valid imported incoming credits, debits, duplicate fingerprints, missing references, ambiguous sources and proposed allocations. Imported text does not establish bank clearance.
3. Record a case explanation/comment and assign a reviewer. Where a correction is needed, use **Finance allocations** investigation/hold and audit-only correction workflow, not raw database edits. A proposed or approved allocation must not be added to committed funds twice.
4. For a partial payment, retain the unpaid balance. For overpayment/mismatch, do not auto-refund or cancel: preserve exact source evidence and require separately authorized merchant process.
5. Escalate persistent ledger/outstanding disagreement with redacted source statuses, exact synthetic examples and controlled audit evidence; preserve the original immutable case/ledger timeline.

## Runbook 4 — Finance notifications over-sending or not arriving

1. In **WooCommerce → Finance notifications**, verify whether notifications are explicitly enabled. OFF is the default and must remain OFF after merchant opt-out.
2. Review selected *actual finance WordPress user IDs* and current capabilities; customer addresses and arbitrary emails cannot be recipients. Revoked finance capabilities suppress later sends.
3. A local **Preview** sends nothing; a privileged **Send test** is an actual mail attempt to opted-in recipients and consumes the same 2 emails/rolling 24h limit as scheduled digests. Duplicate fixed type deliveries are suppressed within 24 hours by a reserved-before-send DB audit under per-recipient lock.
4. \`wp_mail()\` acceptance is not proof of inbox delivery; inspect legitimate mail-provider logs outside the plugin. Uncertain send failures intentionally consume the reservation to avoid repeated payment-related mail.
5. For suspected excess mail, disable the configured digests and investigate Action Scheduler/notification audit. Do not manually replay the scheduler or remove audit rows to bypass rate limits.

## Runbook 5 — Retention cleanup, backups and a suspicious private file

1. Open **WooCommerce → Bank data retention** with a bank-account manager account. Automatic cleanup defaults OFF, and private completed/cancelled source CSV retention defaults NEVER.
2. Before opting into deletion, confirm legal retention, disputed transactions/holds, bank privacy obligations, encrypted backups and the merchant's approved date windows. **Financial ledger, allocation, claim, review and WooCommerce order records are never part of this purge.**
3. Manually run only one explicit confirmed pass. It is DB-site-locked, limited to 25 records per source and auditable. A partially failed pass must be reviewed and rerun only after resolving the cause; never delete files with shell globbing, path changes or manual SQL.
4. Terminal completed/cancelled staged source purge is allowed only with a nonzero merchant policy, past the selected cutoff, and when an outside-webroot private path, 48-hex filename, regular nonsymlink file and original SHA256 check all pass. Failed/pending/processing import sources stay available for inspection/recovery.
5. If source path/hash is suspicious, stop cleanup and inspect securely. A missing private file can leave provenance record and clears only the stale path once in terminal state; source/bank rows remain.
6. Before a WordPress migration, make an encrypted DB backup including edition/migration options and paired plugin tables. Back up the private directory separately; ordinary WordPress ZIP/database backups may omit files outside the web root. Restore on an isolated test store, validate SHA256/permissions/DB prefix and inspect failed-job checkpoints before re-enabling Action Scheduler.
7. Backups may still hold historical personal data erased from live stores; enforce backup access/expiration under merchant policy independently.

Full retention controls and restore checklist: [ADR-0018](../architecture/ADR-0018-privacy-retention-and-backups.md).

## Runbook 6 — HPOS/classic checkout or plugin upgrade regression

1. Capture exact WordPress/WooCommerce/Free/Pro versions, PHP and MariaDB versions, \`woocommerce_custom_orders_table_enabled\` / HPOS state and route (Classic or Blocks).
2. Confirm dependency order: Free active before Pro. Do not assume Pro operates standalone.
3. Run native disposable database fixture \`scripts/test-native-install.sh\` and Foundation \`.github/workflows/php-ci.yml\` in a safe CI environment; verify WooCommerce order CRUD, BACS reference presentation and manual evidence lifecycle under **both** classic/HPOS.
4. Examine Action Scheduler for orphaned old-version job types and migrate only through documented versioned schema migrations. Restore backups atomically or roll back the compatible paired ZIP versions; do not mix incompatible Free/Pro database schemas.
5. If a finance invariant fails, preserve audit evidence and disable dangerous approvals until independently confirmed. Do not perform production data backfills or marketplace release without the separate approval gates.

## Quick incident handoff

Record only: UTC detection time, severity, synthetic order/bank scenario, plugin/WooCommerce/PHP/MariaDB versions, UI screen and safe status, redacted diagnostics CSV hash (not original financial CSV), whether Free/Pro and classic/HPOS passed, and actions already taken. Assign a manager/checker owner and a separate remediation verifier.

**P6 safety release gate:** every report source reconciles to independent ledger evidence; no unauthorized CSV/notification access; no over-sends; no financial state mutation by diagnostics/reporting/import jobs; proper expired export wipe; retention only with explicit consent; full exact-head CI green and review/branch protection satisfied. P6 completion alone does not authorize WordPress.org/WooCommerce marketplace publication, production deployment or transactions.
