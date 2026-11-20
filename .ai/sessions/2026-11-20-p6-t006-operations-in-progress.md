# P6-T006 operational diagnostics/recovery — 2026-11-20 checkpoint

## Completed and verified prerequisite

P6-T005 full exact-source SHA `93b1a3a97eb6d14d01bf66e9ec68358598b25894` native WooCommerce/MariaDB classic+HPOS and Playground GitHub Actions 44658437248 / 44658430001 SUCCESS. Task graph advanced **69 done, P6-T006 in progress, 33 planned**.

## P6-T006 code implemented on draft PR #12

- Finance manager-only `FinanceOperationsSnapshot`: queries 3 installed source-backed operational stores (import batches, private export jobs, notification delivery) with at most 20 most recent rows each. Exposes states, coarse age bands and bounded counts, not PII/bank data/filesystem paths, and distinguishes missing source from zero.
- Audited redacted diagnostics CSV: fixed allowlist only; no source/job/actor/account/recipient IDs or finance amounts, hashes/paths, customer info, raw statement or email. <=128 KiB, <=60 records, WordPress nonce, manager+finance capability, 3 exports/hour actor named lock; separate metadata-only audit table.
- `FinanceJobRecovery`: exact-purpose nonce, three finance capabilities, printable 12–500-char reason **SHA256 only persisted**, manager lock, max 3 attempts/hour, one successful/reserved recovery per original, append-only outcome audit.
- Failed statement import retry delegates to verified `ImportJob::retry`, checking the stored private file's SHA256. First chunk and continuation Action Scheduler enqueues now fail visibly instead of silently stalling. Continuation avoids unique=true while the existing callback may be pending. Failed source remains failed on rejection; nothing approves a bank payment.
- Failed private report export can only be requeued by the original owner while within original TTL: validated `ReportDefinition` and new bounded `AsyncReportExportJob`, original remains failed and untouched.
- Native WooCommerce `Bank operations` menu and explicit admin POST actions for redacted CSV/recovery; no REST/nopriv endpoint, no automatic retries. Extended forged nonce admin adapter tests.
- New disposable native `tests/integration/pro-finance-operations.php`: deliberately tampered import file is rejected, restored file requeues, new bounded export created, duplicates/anonymous/forged nonce blocked, independent metadata-only recovery audit, 3/hour diagnostics CSV and no raw source leakage.
- ADR `docs/architecture/ADR-0017-operations-diagnostics-recovery.md` documents safety/invariants.

## Next gate

**DO NOT mark P6-T006 complete until full CI (foundation PHP/static/unit, Playground, native WordPress/WooCommerce/MariaDB) passes on exact branch SHA.** Inspect GitHub Actions for actual failure before making fixes; no synthetic staging outside disposable CI database. After T006, proceed P6-T007 privacy/retention/backup/cleanup and T008 admin guide.

PR #12 stays DRAFT until P6-T001–T008 all verified and full phase gate passes; automatic merge only then, per `.ai/phase-merge-policy.md`. Issue #7 stays OPEN until entire product Definition of Finished passes. No public release, production deployment or real bank/payment actions.
