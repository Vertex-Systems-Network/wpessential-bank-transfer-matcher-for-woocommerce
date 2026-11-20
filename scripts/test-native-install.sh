#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
site="${1:?Provide a fresh disposable WordPress path}"
woo_archive="${2:-https://downloads.wordpress.org/plugin/woocommerce.11.3.0.zip}"
wp_cli="${WBM_WP_CLI:-wp}"
free=wpessential-bank-transfer-matcher
pro="$free-pro"

wp_test() {
  "$wp_cli" --path="$site" --no-color "$@"
}

if [[ "$(wp_test config get DB_NAME)" != wbm_ci ]]; then
  printf 'Expected the disposable wbm_ci database.\n' >&2
  exit 1
fi

wp_test plugin install "$woo_archive" --activate
wp_test plugin install "$root/dist/$pro.zip"
wp_test eval-file "$root/tests/integration/order-storage.php" pro-missing-free

wp_test plugin install "$root/dist/$free.zip" --activate
wp_test option update woocommerce_custom_orders_table_data_sync_enabled no
wp_test option update woocommerce_custom_orders_table_enabled yes
wp_test eval-file "$root/tests/integration/order-storage.php" hpos

wp_test plugin deactivate "$free"
wp_test plugin delete "$free"
wp_test plugin install "$root/dist/$free.zip" --activate
wp_test option update woocommerce_custom_orders_table_enabled no
wp_test eval-file "$root/tests/integration/order-storage.php" classic

wp_test plugin install "$root/dist/$pro.zip" --force --activate
wp_test eval-file "$root/tests/integration/order-storage.php" paired

wp_test plugin install "$root/dist/$free.zip" --force --activate
wp_test plugin install "$root/dist/$pro.zip" --force --activate
wp_test eval-file "$root/tests/integration/order-storage.php" paired
printf 'PASS paired ZIP replacement and bootstrap\n'
wp_test eval-file "$root/tests/integration/pro-bank-accounts.php"
wp_test eval-file "$root/tests/integration/pro-csv-parser.php"
wp_test eval-file "$root/tests/integration/pro-mapping-profiles.php"
wp_test eval-file "$root/tests/integration/pro-dry-run.php"
wp_test eval-file "$root/tests/integration/pro-import-batches.php"
wp_test eval-file "$root/tests/integration/pro-import-job.php"
wp_test eval-file "$root/tests/integration/pro-import-ui.php"
wp_test eval-file "$root/tests/integration/pro-review-cases.php"
wp_test eval-file "$root/tests/integration/pro-review-ui.php"
wp_test eval-file "$root/tests/integration/pro-review-api.php"
wp_test eval-file "$root/tests/integration/pro-matching-safety.php"
wp_test eval-file "$root/tests/integration/pro-allocation-schema.php"
wp_test eval-file "$root/tests/integration/pro-receiving-bank-binding.php"
wp_test eval-file "$root/tests/integration/pro-mismatch-cases.php"
wp_test eval-file "$root/tests/integration/pro-approval-locks.php"
# Real simultaneous independent WP-CLI requests against the same MariaDB database.
bash "$root/scripts/test-pro-cross-process-races.sh" "$site" "$wp_cli"
wp_test eval-file "$root/tests/integration/pro-one-to-one.php"
wp_test eval-file "$root/tests/integration/pro-many-credits.php"
wp_test eval-file "$root/tests/integration/pro-split-credit.php"
wp_test eval-file "$root/tests/integration/pro-allocation-ui.php"
wp_test eval-file "$root/tests/integration/pro-report-queries.php"
wp_test eval-file "$root/tests/integration/pro-report-unmatched-credits.php"
wp_test eval-file "$root/tests/integration/pro-report-exception-aging.php"
wp_test option update woocommerce_custom_orders_table_enabled no
wp_test eval-file "$root/tests/integration/pro-report-order-balances.php"
wp_test option update woocommerce_custom_orders_table_enabled yes
wp_test eval-file "$root/tests/integration/pro-report-order-balances.php"
wp_test option update woocommerce_custom_orders_table_enabled no
wp_test eval-file "$root/tests/integration/pro-finance-admin-security.php"
# Exercise actual payment completion and audit recovery under both WooCommerce storage modes.
wp_test option update woocommerce_custom_orders_table_enabled no
wp_test eval-file "$root/tests/integration/pro-payment-finalizer.php"
wp_test option update woocommerce_custom_orders_table_enabled yes
wp_test eval-file "$root/tests/integration/pro-payment-finalizer.php"
wp_test option update woocommerce_custom_orders_table_enabled no
wp_test eval-file "$root/tests/integration/pro-overpayment-exceptions.php"
wp_test eval-file "$root/tests/integration/free-pro-allocation-balance.php"

wp_test option update woocommerce_cod_settings '{"enabled":"yes"}' --format=json
wp_test option update woocommerce_bacs_accounts '[{"account_name":"Fixture account","bank_name":"Fixture bank","account_number":"","sort_code":"","iban":"","bic":""}]' --format=json
wp_test option update woocommerce_bacs_settings '{"enabled":"no","instructions":"Fixture transfer instructions"}' --format=json
wp_test eval-file "$root/tests/integration/bacs-settings.php" disabled
wp_test option update woocommerce_bacs_settings '{"enabled":"yes","instructions":"Fixture transfer instructions"}' --format=json
wp_test eval-file "$root/tests/integration/bacs-settings.php" enabled

wp_test eval-file "$root/tests/integration/payment-references.php"
wp_test option update woocommerce_custom_orders_table_enabled yes
wp_test eval-file "$root/tests/integration/payment-references.php"
wp_test eval-file "$root/tests/integration/reference-presentation.php"
wp_test eval-file "$root/tests/integration/customer-claims.php"
wp_test eval-file "$root/tests/integration/claim-attachments.php"
wp_test eval-file "$root/tests/integration/bank-evidence.php"
wp_test eval-file "$root/tests/integration/manual-allocations.php"
wp_test eval-file "$root/tests/integration/claim-reviews.php"
wp_test option update woocommerce_custom_orders_table_enabled no
wp_test eval-file "$root/tests/integration/order-admin.php" classic
wp_test eval-file "$root/tests/integration/order-overview.php" classic
wp_test eval-file "$root/tests/integration/free-e2e.php" classic
wp_test option update woocommerce_custom_orders_table_enabled yes
wp_test eval-file "$root/tests/integration/order-admin.php" hpos
wp_test eval-file "$root/tests/integration/order-overview.php" hpos
wp_test eval-file "$root/tests/integration/free-e2e.php" hpos
wp_test eval-file "$root/tests/integration/free-settings-privacy.php"
