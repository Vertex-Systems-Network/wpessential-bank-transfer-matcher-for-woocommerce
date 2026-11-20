<?php
// Pure CI: no WordPress runtime and no mock browser evidence.
defined( 'ABSPATH' ) || define( 'ABSPATH', '/nonproduction/wp/' );
require dirname( __DIR__ ) .
	'/plugins/wpessential-bank-transfer-matcher-pro/src/Admin/NativeAdminAssets.php';

use WPEssential\BankTransferMatcherPro\Admin\NativeAdminAssets;

$allowed = [
	'wpessential-wbm-pro-bank-accounts',
	'wpessential-wbm-pro-mapping-profiles',
	'wpessential-wbm-pro-import-statements',
	'wpessential-wbm-pro-bank-transactions',
	'wpessential-wbm-pro-review-queue',
	'wpessential-wbm-pro-allocations',
	'wpessential-wbm-pro-finance-reports',
	'wpessential-wbm-pro-notifications',
	'wpessential-wbm-pro-dashboard',
	'wpessential-wbm-pro-operations',
	'wpessential-wbm-pro-retention',
];
foreach ( $allowed as $slug ) {
	if ( ! NativeAdminAssets::is_pro_page( 'woocommerce_page_' . $slug ) ) {
		throw new RuntimeException( 'Unstyled registered Pro submenu: ' . $slug );
	}
}
foreach ( [
	'front_page',
	'post.php',
	'woocommerce_page_wc-orders',
	'woocommerce_page_wpessential-bank-transfer-matcher',
	'woocommerce_page_wpessential-wbm-pro-invented',
	'woocommerce_page_wpessential-wbm-pro-review-queue-extra',
	'toplevel_page_wpessential-wbm-pro-review-queue',
	'admin_page_wpessential-wbm-pro-dashboard',
	'woocommerce_page_WPESSENTIAL-WBM-PRO-DASHBOARD',
] as $hook ) {
	if ( NativeAdminAssets::is_pro_page( $hook ) ) {
		throw new RuntimeException( 'Pro-only stylesheet would affect unrelated wp-admin screen: ' . $hook );
	}
}
$css_path = dirname( __DIR__ ) .
	'/plugins/wpessential-bank-transfer-matcher-pro/assets/admin.css';
$css = is_file( $css_path ) ? file_get_contents( $css_path ) : false;
if ( ! is_string( $css ) || strlen( $css ) < 4500 ||
	! str_contains( $css, 'body[class*="woocommerce_page_wpessential-wbm-pro-"]' ) ||
	! str_contains( $css, '.wbm-pro-table-scroll' ) ||
	! str_contains( $css, ':focus-visible' ) ||
	! str_contains( $css, 'forced-colors: active' ) ||
	! str_contains( $css, 'max-width: 782px' ) ||
	! str_contains( $css, 'grid-template-columns: 1fr' ) ||
	str_contains( $css, 'display: none' ) ) {
	throw new RuntimeException( 'Native Pro UI CSS is missing required scope, focus, horizontal scroll or accessible breakpoints.' );
}
$free_css = dirname( __DIR__ ) .
	'/plugins/wpessential-bank-transfer-matcher/assets/admin.css';
$free_source = is_file( $free_css ) ? file_get_contents( $free_css ) : false;
if ( ! is_string( $free_source ) ||
	! str_contains( $free_source, '.wbm-admin .wbm-table-scroll:focus-visible' ) ||
	! str_contains( $free_source, '@media (max-width: 782px)' ) ||
	! str_contains( $free_source, '@media (forced-colors: active)' ) ) {
	throw new RuntimeException( 'Free native responsive/focus styles missing or unscoped.' );
}
echo "PASS exact WooCommerce Pro submenu stylesheet allowlist, Free/front-end isolation, focus, RTL-logical and mobile CSS contracts\n";
