# Start here

Build independent Free and Pro WooCommerce plugins for local bank transfer reconciliation. Resume from `.ai/state.json`, `.ai/next-task.json`, and `BACKLOG.md`; the master prompt's Section 23 defines completion.

## Current checkpoint — 2026-11-19

Branch: `development/autonomous-release-track`  
Base: `main` at `939b9c6c7ecd1882340af9dcd55fe932d4207251`  
Active task: `WBM-P6-T001` — bounded canonical reporting filters, deterministic report IDs and overflow-safe per-currency/precision/state totals (unit CI pending).

This is an unmerged development branch. Continue through every unblocked task while the runner is active. Do not pause for a routine “start/continue?” prompt. Never claim background work after the runner ends. Do not merge, publish, submit WordPress.org/Woo Marketplace, or transmit financial data externally. Fixtures must be synthetic.

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
