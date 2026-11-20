# Handoff — P5-T006 source-backed mismatch cases

- Branch: development/autonomous-release-track; parent commit 1192b2fc4886f8367a74eba90b3ee234f11bd503, draft PR #9 unmerged.
- Explicit merchant receiving account binding and existing proposal/approval regression tests passed GitHub Actions run 44624804673 full foundation/Playground and MariaDB.
- Source-backed `MismatchCaseRepository` added to Pro: a versioned mismatch case+event schema, immutable bank/order/currency/account and balance snapshots with source fingerprints and audit-only independent reviewer decisions. No allocation, refunds, payment_complete or automatic matching approval.
- Native `pro-mismatch-cases.php` fixture covers wrong USD receiving account/excess, EUR currency mismatch, partial/wrong reference, duplicate/replay, source/order drift, independent reviewer, zero financial mutations.
- **Latest code still unverified until new CI green**. On failure inspect job logs and fix; only then consider marking P5-T006 done. Harder cross-order bank dedup/concurrency and UI remain future gates.
- Do not merge/publish/release or connect live bank data.
