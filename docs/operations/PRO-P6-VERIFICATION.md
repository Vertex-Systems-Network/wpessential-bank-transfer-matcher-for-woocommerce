# P6 phase completion checkpoint and exact CI requirements

**All eight P6 tasks T001–T008 are DONE in the task graph**. The following CI runs each verified the exact source at its respective implementation checkpoint; they do NOT substitute for GitHub Actions at the final task-state SHA.

| Task | Deliverable | Verified GitHub Actions runs |
|---|---|---|
| P6-T001 | Canonical filters, exact per-currency state totals | 44643672874 / 44643662149 |
| P6-T002 | Outstanding BACS, partial, unmatched/ambiguous, exception aging, import/allocations source-backed reports | 44647473663 |
| P6-T003 | Safe whitelisted CSV, private 1,000-row Action Scheduler jobs, audit+rate+expiry+owner | 44653642849 |
| P6-T004 | Default-off privacy-safe digest templates, manager recipients, nonce, 2 sends/24h | 44656913883 / 44656906701 |
| P6-T005 | Six-source bounded WooCommerce native dashboard, page-scoped exact currency/state minor units | 44658437248 / 44658430001 |
| P6-T006 | Redacted support diagnostics, verified-import-source recovery, new owner-private bounded export | 44661739149 / 44661731639 |
| P6-T007 | Explicit merchant consented retention, hashes, financial-ledger protection, backup ADR | 44663096422 / 44663089250 |
| P6-T008 | [Full finance admin guide](PRO-FINANCE-ADMIN-GUIDE.md), [incident runbooks](PRO-INCIDENT-RUNBOOKS.md), [phase safety checklist](PRO-P6-ACCEPTANCE.md) and content tests | 44663971319 / 44663964390 |

**P6 financial phase gate:** existing native synthetic tests ensure no imported/claimed funds auto-clear or auto-pay, exact amount and currency separation and HPOS/classic safe reads, explicit approval controls, secure CSV roles/nonce/formula neutralization, private queued export expiry, and database-locked opt-in role-limited mail with no over-sends. Private retention is off by default and explicitly confirmed. The source/provider failure state is unavailable, never falsely represented as paid or zero; redacted exports avoid bank references, PII and files. Browser screenshots/RTL/keyboard and release/publishing checks remain separate downstream P7/P8 gates, not invented as completed.

**Merge policy:** At the exact final P6 branch head, require *both* GitHub workflows (native MariaDB/WooCommerce+Foundation/Playground) successful, no failed required checks, no unresolved mandatory human review/security findings, no conflicts or rule violations. Only then change draft PR #12 to ready and use merge with `expected_head_sha` under owner's standing authorization. Reread main afterward. Until then PR #12 is unmerged; Issue #7 stays open until master Section 23 definition of finished product is verified. No production payment/deploy/marketplace release authorized.

**Next:** dedicate fresh P7 branch from merged main. P7-T001 validate actual UI-001–UI-014 against WordPress-native Free/Pro forms, routes and states; do not misrepresent pending browser/manual tests.
