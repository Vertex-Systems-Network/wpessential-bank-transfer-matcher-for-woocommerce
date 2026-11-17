# MySQL and MariaDB verification

Playground is the first environment for package installation and bootstrap checks. The SQL target needs a separate disposable WordPress site with MySQL or MariaDB, no connection to a real merchant installation.

1. Provision a disposable WP test environment with WordPress 7.0, PHP 8.3 and a supported WooCommerce version. Choose MySQL or MariaDB and confirm WordPress is using it.
2. Install the two development archives produced by the commands in START_HERE.md and record core, plugin, PHP and database versions.
3. Verify separate packages, dependency behavior and activation. Record evidence for both WooCommerce storage modes when the product implements order persistence.
4. Run allocation, rollback and recovery tests on the same database after ledger migrations and approval orchestration exist. Open independent DB sessions for simultaneous approval cases and assert ledger totals and a single WooCommerce transition.

The migration integration job runs independently of Playground. It uses a fresh WordPress database named `wbm_ci` and two MariaDB connections. To rerun against an equivalent disposable WP-CLI environment, install the Free Composer autoloader, provision a fresh database, and run `wp eval-file /absolute/path/to/tests/integration/migrations.php --path=/absolute/path/to/wordpress`. The fixture writes schema and checkpoint data. Use a fresh database for each run.

The development harness is defined in `tools/mysql/compose.yml`. From the repository root, run `docker compose -p wbm-test -f tools/mysql/compose.yml up -d --wait`. Open `http://127.0.0.1:8891`, complete the disposable setup, then install WooCommerce and the required ZIPs. The image tags and full startup still need a live Docker verification.

A local MariaDB server and WP-CLI also work without Docker. Provision a fresh database named `wbm_ci` and set `WBM_DB_HOST`, `WBM_DB_USER` and `WBM_DB_PASSWORD` for its disposable account. Use a new WordPress directory.

```bash
wp core download --path="$WP_TEST_PATH" --version=7.0.8
wp config create --path="$WP_TEST_PATH" --dbname=wbm_ci --dbuser="$WBM_DB_USER" --dbpass="$WBM_DB_PASSWORD" --dbhost="$WBM_DB_HOST"
wp core install --path="$WP_TEST_PATH" --url=http://wbm.test --title="Matcher CI" --admin_user=wbm_ci --admin_email=ci@example.test --skip-email
python3 scripts/build-plugin-zips.py
bash scripts/test-native-install.sh "$WP_TEST_PATH"
```

Install each edition's Composer runtime autoloader before building the ZIPs. The install script uses WooCommerce 11.3.0 by default and accepts a local WooCommerce ZIP as its second argument. The script installs, removes and reinstalls plugin files and toggles the WooCommerce order store inside the disposable fixture. Its evidence is limited to plugin bootstrap and WooCommerce CRUD. Matcher order integration and any HPOS compatibility declaration are covered under P8-T005.
