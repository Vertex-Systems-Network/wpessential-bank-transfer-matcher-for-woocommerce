# Start here

Build independent Free and Pro WooCommerce plugins for local bank transfer reconciliation. Resume from `.ai/state.json`, `.ai/next-task.json`, and `BACKLOG.md`; the master prompt's Section 23 defines completion.

## Current checkpoint — 2026-11-20

Phase branch: `development/p6-reports-operations-20261120` (base `main`, carrying merged PR #10/#11 fixes).  
Phase: **P7 — Complete UI/UX, Visual System, Responsiveness** (after P6 phase PR merges). Tasks P6-T002–T003 are verified via GitHub Actions 44647473663 and 44653642849. P6-T004 also verified at exact SHA 6318c3e by full GitHub Actions 44656913883 and 44656906701. P6-T005 passed full GitHub Actions 44658437248 and 44658430001 at SHA 93b1a3a. P6-T006 also fully passed at SHA 9e0c9be (GitHub Actions 44661739149 and 44661731639). P6-T007 fully passed at exact SHA 3ca9a491 with GitHub Actions 44663096422 and 44663089250. P6-T008 operating guide and incident runbooks passed full GitHub Actions 44663971319 and 44663964390 at b2311ce6. All **eight P6 tasks are verified**, but final P6 task/state commit requires fresh exact-HEAD CI before draft PR #12 is merged to main. Next task P7-T001 (on a dedicated P7 branch once phase PR is merged): audit UI-001–UI-014 schemas and real screen fields. Read `.ai/state.json` and `.ai/next-task.json` for the *live* task after every commit.

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
