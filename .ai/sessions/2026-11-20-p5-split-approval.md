# Handoff — P5-T004 atomic split maker-checker

- Branch: development/autonomous-release-track; previous code commit c75df3c63dfad2b9e31b22488e1b00645124b405; draft PR #9 stays unmerged.
- Preview run 44613122530 and 44613105946 passed full native/Playground.
- Added SQL migration v3 `split_group_key`, internal SplitCreditAllocation service, explicit 2–25 order splits of a whole imported unique credit, deterministic all-order/source locks, atomic source/order validation, separate finance reviewer and append-only audit, idempotent group retry. OneToOneApproval rejects split rows to prevent individual bypass.
- Added `tests/integration/pro-split-credit.php` native fixture, negative paths and unchanged WooCommerce paid status, registered in native install script.
- **New split code remains unverified until CI**. Check any PHPStan, native MariaDB, and WordPress Playground failures and correct before marking P5-T004 done.
- Current limitation: whole-source group allocation only; resplitting a leftover is intentionally disallowed until separate audited workflow. Cross-order provenance/concurrency stress remain P8 release blockers.
- No merge, marketplace submission, release, or live bank connection.
