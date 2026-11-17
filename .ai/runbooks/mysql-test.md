# MySQL and MariaDB verification

Playground is the first environment for package installation and bootstrap checks. The SQL target needs a separate disposable WordPress site with MySQL or MariaDB, no connection to a real merchant installation.

1. Provision a disposable WP test environment with WordPress 7.0, PHP 8.3 and a supported WooCommerce version. Choose MySQL or MariaDB and confirm WordPress is using it.
2. Install the two development archives produced by the commands in START_HERE.md and record core, plugin, PHP and database versions.
3. Verify separate packages, dependency behavior and activation. Record evidence for both WooCommerce storage modes when the product implements order persistence.
4. Run allocation, rollback and recovery tests on the same database after ledger migrations and approval orchestration exist. Open independent DB sessions for simultaneous approval cases and assert ledger totals and a single WooCommerce transition.

This workspace had no native MySQL/MariaDB executable or Docker. Local Playground checks are recorded separately from the SQL test gate.
