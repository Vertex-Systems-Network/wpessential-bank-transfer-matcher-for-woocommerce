# Start here

Build independent Free and Pro WooCommerce plugins for local bank transfer reconciliation. Resume from `.ai/state.json`, `.ai/next-task.json`, and `BACKLOG.md`; the master prompt's Section 23 defines completion.

## P8-T001–T005 verified; P8-T006 active — 2026-11-21

P7 PR #14 merged to main at `4aa10da38e73ac5effc27280efbc05ccd719fb8d`. P8 [draft PR #15](https://github.com/Vertex-Systems-Network/wpessential-bank-transfer-matcher-for-woocommerce/pull/15) remains unmerged. T001 roles/REST, T002 private HTTP, T003 SQL/XSS/CSRF/XXE/CSV, T004 SQL advisory locks/stale bank sources are source-verified. **T005** independently packaged Free+Pro official Woo HPOS declarations and tested-version headers **VERIFIED** at exact source `6ffc566738e24c3fb0083763493319823a3f2271`: 5/5 checks green; Foundation/native MariaDB/Playground runs 44820665254/44820655490 and LTR/Arabic RTL browser 44820655390, evidence artifact 14671987232. Real WooCommerce 11.3.0 HPOS and classic data stores both pass order CRUD and no-paid-state assertions. Docs `docs/security/p8-t005-hpos-compatibility.md`. No generalized Woo 10.8/PHP version or Blocks support claim.

**P8-T006 active:** real Checkout Blocks vs classic BACS checkout flow and WC/PHP version matrix. T007–T009 and P9 pending; no live merchant payments, repository release or plugin publication. Issue #7 stays open.

## Historical checkpoint — 2026-11-20

Phase P6 PR #12 merged into `main` at 51c34bd75f3936bddfc86911c99722c834465e1a after both full CI runs 44664553928/44664548348 SUCCESS. Current dedicated P7 branch: `development/p7-ui-ux-schemas-20261120` (draft PR #13).  
Phase: **P7 — Complete UI/UX, Visual System, Responsiveness** (after P6 phase PR merges). Tasks P6-T002–T003 are verified via GitHub Actions 44647473663 and 44653642849. P6-T004 also verified at exact SHA 6318c3e by full GitHub Actions 44656913883 and 44656906701. P6-T005 passed full GitHub Actions 44658437248 and 44658430001 at SHA 93b1a3a. P6-T006 also fully passed at SHA 9e0c9be (GitHub Actions 44661739149 and 44661731639). P6-T007 fully passed at exact SHA 3ca9a491 with GitHub Actions 44663096422 and 44663089250. P6-T008 operating guide and incident runbooks passed full GitHub Actions 44663971319 and 44663964390 at b2311ce6. All **eight P6 tasks are verified**, but final P6 task/state commit requires fresh exact-HEAD CI before draft PR #12 is merged to main. P7-T001 source-backed UI-001–UI-014 schema and 14-renderer PHP contract verified at exact SHA a396837e with GitHub Actions 44665899783/44665892900 SUCCESS. **P7-T002 verified** at exact SHA `d08802c9` via full runs 44668451481/44668445322 SUCCESS (native Free/Pro CSS and screens). **P7-T003 verified** at source SHA `8c71ca4fe5c5f1089012eacd0ebad558adc14e85`, both complete CI runs 44671126063/44671120192 SUCCESS. **P7-T004 real browser verified** at code SHA 483a7e49 via [browser Actions 44672577045](https://github.com/Vertex-Systems-Network/wpessential-bank-transfer-matcher-for-woocommerce/actions/runs/44672577045) (111 real PNGs, Arabic RTL, forced colors, keyboard) plus Foundation/native Actions 44672584525/44672576959 all green. **P7-T005 VERIFIED**: 77/103 tasks done at exact source SHA `27e2c46` (browser 44682670860: 115 real LTR/Arabic RTL/high-contrast PNGs, 0 browser issues; Foundation/native 44682670864 + 44682677458 SUCCESS). **P7-T006 ACTIVE**: real Free/Pro WooCommerce navigation integration, role-aware menus and no duplicated/stale links. [P7 screen contract audit](docs/ux/p7-source-backed-screen-contracts.md) committed; Foundation/native/Playground CI and browser/RTL QA gates still separate. Read `.ai/state.json` and `.ai/next-task.json` for the *live* task after every commit.

**Owner-authorized phase merges:** Follow [`.ai/phase-merge-policy.md`](.ai/phase-merge-policy.md). Once **all** tasks and safety acceptance criteria of a phase pass against the exact current PR head SHA, merge that phase PR to `main` automatically without routine re-approval while the execution runner is active. Do not merge incomplete phases or bypass failing tests/branch protection. The authorization does not cover marketplace publication, release distribution, bank transfers, refunds or production deployment.

Continue through every unblocked task while the runner is active. Do not pause for a routine “start/continue?” prompt or claim background work after the runner ends. All financial fixtures must be synthetic. Issue #7 stays open until Section 23 is fully verified.

## Plan integrity

`scripts/update-backlog.py` now preserves a valid active unfinished task, excludes terminal statuses, validates duplicate/missing IDs and dependency cycles, and deterministically selects the first unblocked item when the active item is complete. The task graph remains sequential pending a deliberate dependency audit; do not add parallel dependencies that violate implementation prerequisites. Task graph and generated documentation passed in GitHub Actions run 44582174758.

## Reproduce and verify

```sh
python3 scripts/update-backlog.py
python3 scripts/check-project-state.py
php -l plugins/wpessential-bank-transfer-matcher-pro/src/Import/ImportJob.php
# Follow .ai/runbooks/mysql-test.md for disposable WordPress + WooCommerce + MariaDB.
# Run the task-specific PHP/static/unit/integration tests and Playground scenarios.
```

Record only commands actually run and their exact outcomes. Update the task evidence, state, next-task pointer, generated backlog, and append-only handoff before ending a session. Move to the next task automatically after acceptance is verified.

## Financial invariants

Customer claims and imported statements remain unverified. Import never marks an order paid. Only explicit authorized merchant review, cleared evidence, exact allocation and idempotent WooCommerce payment semantics can complete payment. Keep Free deployable independently of Pro.

## Package an edition

Use `bash scripts/package-plugin.sh free` or `bash scripts/package-plugin.sh pro` after local runtime is available. Run clean-install, upgrade/recovery, Playground and MariaDB checks; do not infer them from connector-only reads.
