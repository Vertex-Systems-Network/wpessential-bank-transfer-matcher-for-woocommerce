# Schema migrations and rollback

Free provides the migration runner. Free and Pro will each supply a separate version option and an append-only list of ordered, idempotent migration steps. Invoke the runner when the relevant package has a real migration to apply. Use the current site's WordPress table prefix for plugin-owned tables.

The runner serializes each package's upgrades per site with a MySQL or MariaDB named lock. It reads the version from the database while holding that lock, verifies each step and checkpoints immediately after success. If a step fails or its checkpoint cannot be written, stop the upgrade. Retrying may rerun that step, so the step must inspect the schema and handle partial changes. An older package must reject a newer schema.

## Recovery

1. Before a production upgrade, take a tested database backup and keep the matching prior plugin packages. Put the site into maintenance mode and stop reconciliation workers before capturing a backup or restoring it.
2. After an interrupted upgrade, inspect the recorded version and actual schema. Fix the cause and retry the same package if its steps can safely resume.
3. If recovery requires a downgrade, restore the known-good database snapshot and matching plugin packages together. Assess writes since the snapshot before restoring. Verify schema versions and run an admin smoke check before reopening the site.

Schema changes can outlive a failed upgrade. Lowering the version option alone cannot reverse them. The scaffold currently has no production table migrations. Its local PHP test verifies control flow with database doubles. Real MySQL or MariaDB locking, DDL and restore verification remain integration gates.

The version option is internal to the runner. Read it directly for diagnostics to avoid stale WordPress option-cache values. Schedule schema upgrades with reconciliation workers stopped. A database reconnection can release a named lock. The checkpoint checks the current connection's lock ownership before writing, so a disconnected worker cannot advance the recorded version.
