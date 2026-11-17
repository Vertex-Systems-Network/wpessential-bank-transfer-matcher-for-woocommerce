# ADR-0001 Separate Free and Pro packages

Accepted 2026-11-17.

Free owns order references, claim evidence, shared domain contracts, authorization and reconciliation invariants. Pro owns import, matching, advanced review, reporting and licensing. Pro binds to a versioned Free API after dependency checks. Each package owns its namespace, Composer manifest, lockfile, assets and generated production autoloader. Free must activate independently and build from its own directory.

The alternatives were a single gated package or two packages sharing a runtime vendor directory. Package isolation allows independent installation and review and prevents Pro code in the directory package.

Use an append-only audit trail and integer minor units. Snapshot financially relevant order facts before approval and stop on changes to those facts. Commit ledger changes and a unique pending payment-transition intent atomically on transactional tables. Retry only after checking WooCommerce state and a stable transition identifier. Route ambiguous outcomes to deliberate review. Playground establishes UI and installation behavior. Transaction integrity and race tests require MySQL or MariaDB.
