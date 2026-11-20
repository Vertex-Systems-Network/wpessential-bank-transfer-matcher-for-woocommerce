# Phase P6 — Operational acceptance checklist

This checklist is intentionally more conservative than successful source compilation. It is not a declaration of release readiness unless checked against the **exact current Git SHA**.

## Source provenance and finance invariants

- [ ] Each report family queries the corresponding independent WooCommerce/Free/Pro source and honors ISO currency, exponent, bank account, date and state filters. A \`has_more\` page is not the full ledger; no silent cross-currency or proposed/approved amount mixing.
- [ ] Import source debit/duplicate/missing reference, mismatched receiving account and unverified claim remain unconfirmed; no auto clearance, paid status, approval or refund from reporting/notification/cleanup.
- [ ] HPOS and classic WooCommerce test BACS order eligibility and equal exact money/allocations; invalid/negative amounts, race recovery and changed ledger source hold safely.

## Private CSV and support exports

- [ ] Six finance CSV allowlists, raw bank/customer data redaction and formula injection defenses pass; immediate 500-row/1MiB/3-per-minute limitations enforced.
- [ ] Queued 100-row/page and 10-page/1,000-row/5MiB bounds, private DB storage, 2 live jobs/user, 3 starts/minute, 2-hour expiry, job ownership, nonce and audited download rate; no partial CSV leaks.
- [ ] Redacted support CSV <escape><=</escape>60 records/128KiB and only safe source/status/age/count columns; 3/hour audit; no IDs, source paths, customer emails, bank references, passwords, original bank source data.

## Notifications and recovery

- [ ] Default OFF, four fixed plaintext non-sensitive templates, local preview without send, finance manager-only recipient changes, no arbitrary customer addresses.
- [ ] Scheduled digests only for unresolved source-backed work after explicit opt-in; 2 mail attempts per recipient/rolling day and one same type/24h with DB reservation-before-send; opt-out unschedules.
- [ ] Failed bank source import retry enforces exact SHA256 and explicit manager nonce+reason; source still unverified on resume. Failed private export owned by same actor requeues to **new** bounded job; no modifying original; 3 recovery requests/hour, duplicate protection and audit.

## Retention, privacy and backups

- [ ] Recurring cleanup OFF and private statement deletion NEVER by default, requiring explicit manager consent plus finite allowlisted settings; forged/anonymous attempts denied.
- [ ] Bounded 25/source sweep with audit and site lock, expiry wipes private CSV without modifying payment ledger. Old metadata audited deletion obeys selected windows.
- [ ] Private bank file purge requires terminal completed/cancelled batch, past merchant-set age, exact 48-hex private basename, non-webroot file, nonsymlink and matching original source hash. Missing/wrong path fails closed; financial source rows, claims, orders and approvals never purged.
- [ ] Encrypted consistent DB + separately private staged-file backup and isolated restore/migration instructions documented; no disguised “delete from backup” promise.

## Admin and docs

- [ ] WordPress-native menu, permission/nonce/POST gates tested for Dashboard, Finance reports, Notifications, Bank operations and Bank data retention; nopriv hooks absent.
- [ ] [Finance admin guide](PRO-FINANCE-ADMIN-GUIDE.md) and [Incident runbooks](PRO-INCIDENT-RUNBOOKS.md) explain synthetic BACS partial/duplicate case, reports, exports, notices, import and export recovery, migration, HPOS/classic and consented privacy.
- [ ] All P6-T001–T008 task entries have **evidence of latest code** and complete phase financial/no-oversend gate; all Foundation/native MariaDB/Playground GitHub Actions checks green at the exact latest PR head and required review/branch protection satisfied.

## Merge boundary

Only when all boxes are verified and documented: apply the owner-authorized automatic phase PR merge into \`main\` under \`.ai/phase-merge-policy.md\`. **Do not** merge incomplete draft P6 PR #12, bypass a failing CI, delete unfinished financial evidence, issue refunds, touch production bank accounts, publish a marketplace package or close the master project Issue #7 before the full Definition of Finished Product is independently verified.
