<?php
use WPEssential\BankTransferMatcherPro\Admin\NativeAdminAssets;
use WPEssential\BankTransferMatcherPro\Admin\ReviewQueuePage;
use WPEssential\BankTransferMatcherPro\Admin\BankTransactionsPage;
use WPEssential\BankTransferMatcherPro\Admin\FinanceDashboardPage;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'wbm_ci' !== DB_NAME ) {
	throw new RuntimeException( 'P7 visual shell checks require disposable native WordPress/MariaDB.' );
}
$admin = get_user_by( 'login', 'wbm_ci' );
if ( ! $admin ) throw new RuntimeException( 'No disposable finance manager actor.' );
$prev = get_current_user_id();
$get = $_GET;
wp_set_current_user( $admin->ID );
try {
	if ( false === has_action( 'admin_enqueue_scripts', [ NativeAdminAssets::class, 'enqueue' ] ) ) {
		throw new RuntimeException( 'Pro native CSS is not registered to the WordPress enqueue hook.' );
	}
	wp_dequeue_style( 'wpessential-wbm-pro-admin' );
	NativeAdminAssets::enqueue( 'woocommerce_page_wc-orders' );
	if ( wp_style_is( 'wpessential-wbm-pro-admin', 'enqueued' ) ) {
		throw new RuntimeException( 'Pro CSS was loaded on core WooCommerce order editor.' );
	}
	NativeAdminAssets::enqueue( 'woocommerce_page_wpessential-bank-transfer-matcher' );
	if ( wp_style_is( 'wpessential-wbm-pro-admin', 'enqueued' ) ) {
		throw new RuntimeException( 'Pro CSS was loaded on independent Free admin.' );
	}
	NativeAdminAssets::enqueue( 'woocommerce_page_wpessential-wbm-pro-review-queue' );
	if ( ! wp_style_is( 'wpessential-wbm-pro-admin', 'enqueued' ) ) {
		throw new RuntimeException( 'Pro native CSS not enqueued on review queue.' );
	}
	$style = wp_styles()->registered['wpessential-wbm-pro-admin'] ?? null;
	if ( ! $style || ! str_contains( $style->src, '/wpessential-bank-transfer-matcher-pro/assets/admin.css' ) ) {
		throw new RuntimeException( 'Pro native CSS URL did not resolve to bundled plugin asset.' );
	}
	$_GET = [ 'page' => 'wpessential-wbm-pro-review-queue' ];
	ob_start();
	ReviewQueuePage::render();
	$queue = ob_get_clean();
	foreach ( [
		'wbm-pro-toolbar', 'role="search"', 'id="wbm-filter-status"',
		'id="wbm-filter-priority"', 'id="wbm-filter-assignee"',
		'id="wbm-review-page-size"', 'Rows per page',
	] as $word ) {
		if ( ! str_contains( $queue, $word ) ) {
			throw new RuntimeException( 'Rendered Pro review filters missing accessible native control: ' . $word );
		}
	}
	if ( str_contains( $queue, '<style>.wbm-review-grid' ) ) {
		throw new RuntimeException( 'Legacy inline review stylesheet was not removed.' );
	}
	$_GET = [ 'page' => 'wpessential-wbm-pro-bank-transactions' ];
	ob_start();
	BankTransactionsPage::render();
	$bank = ob_get_clean();
	if ( ! str_contains( $bank, 'Bank transactions' ) ||
		! str_contains( $bank, 'wbm-pro-screen' ) ) {
		throw new RuntimeException( 'Bank transactions screen was not rendered as native Pro shell.' );
	}
	if ( str_contains( $bank, 'name="per_page"' ) ) {
		foreach ( [ 'id="wbm-bank-page-size"', 'wbm-pro-toolbar',
			'role="search"' ] as $word ) {
			if ( ! str_contains( $bank, $word ) ) {
				throw new RuntimeException( 'Bank transaction filter missing real accessible label: ' . $word );
			}
		}
	}
	$_GET = [ 'page' => 'wpessential-wbm-pro-dashboard' ];
	ob_start();
	FinanceDashboardPage::render();
	$dash = ob_get_clean();
	if ( ! str_contains( $dash, 'Bank finance dashboard' ) ||
		! str_contains( $dash, 'wbm-pro-screen' ) ) {
		throw new RuntimeException( 'Native source-backed finance dashboard is missing.' );
	}
	if ( str_contains( $dash, 'name="focus_report"' ) &&
		( ! str_contains( $dash, 'wbm-pro-filter-panel' ) ||
		! str_contains( $dash, 'wbm-pro-cards' ) ||
		str_contains( $dash, 'style="display:grid' ) ) ) {
		throw new RuntimeException( 'Dashboard still has unscoped inline card grid or inaccessible native filters.' );
	}
	echo "PASS native Pro-only enqueue, independent Free/HPOS isolation, labeled review/import forms and source-backed dashboard responsive shell\n";
} finally {
	$_GET = $get;
	wp_set_current_user( $prev );
}
