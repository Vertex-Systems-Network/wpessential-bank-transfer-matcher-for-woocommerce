# Start here

Build independent Free and Pro WooCommerce plugins for local bank transfer reconciliation. Read both root directives, then reconcile [.ai/state.json](.ai/state.json) with Git and the [backlog](BACKLOG.md).

## Checkpoint

Branch `development/phase-0-1` started from `2b9e860`. The initial repository held three tracked documents and had no issues in the connector search. Phase 0 seeded product, architecture, UX, test and release contracts. Phase 1 has separate development bootstraps, package tooling, generated development readmes and an exact money slice.

## Reproduce

```sh
for plugin in plugins/*; do
  composer --working-dir="$plugin" validate --strict
  composer --working-dir="$plugin" install --no-dev --prefer-dist --no-interaction --optimize-autoloader
done
node scripts/generate-readmes.ts --check
php tests/money.php
python3 scripts/build-plugin-zips.py
python3 scripts/build_playground_bundles.py
python3 scripts/test-playground-foundation.py
```

## Next task

Use [.ai/next-task.json](.ai/next-task.json) for the current command and acceptance evidence. See [Playground evidence](playground/evidence/) for recorded runs.

All fixtures must be synthetic. Any WooCommerce payment completion depends on explicit authorization from the merchant, cleared bank evidence and exact allocation checks. Keep Free deployable independently of Pro. Verify financial integrity on transactional MySQL or MariaDB.
