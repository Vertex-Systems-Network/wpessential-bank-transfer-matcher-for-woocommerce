<?php
/**
 * P7-T006: actual WordPress submenu registration across Free/Pro and split
 * finance capabilities. Synthetic users only; no financial mutations.
 *
 * Execute in separate disposable WP-CLI processes for:
 *   no-free, free-only, paired.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'wbm_ci' !== DB_NAME ) {
	throw new RuntimeException( 'P7 menu checks require disposable WordPress/MariaDB wbm_ci.' );
}
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

$mode = $args[0] ?? '';
if ( ! in_array( $mode, [ 'no-free', 'free-only', 'paired' ], true ) ) {
	throw new RuntimeException( 'Specify exactly one P7 navigation mode.' );
}
$free_ready = did_action( 'wpessential_bank_transfer_matcher_ready' ) > 0;
$pro_ready = did_action( 'wpessential_bank_transfer_matcher_pro_ready' ) > 0;
if ( ( 'no-free' !== $mode ) !== $free_ready ||
	( 'paired' === $mode ) !== $pro_ready ) {
	throw new RuntimeException( 'Plugin bootstrap state does not match P7 mode: ' . $mode );
}

$free_class = \WPEssential\BankTransferMatcher\Bootstrap\Plugin::class;
$pro_classes = [
	\WPEssential\BankTransferMatcherPro\Admin\BankAccountsPage::class,
	\WPEssential\BankTransferMatcherPro\Admin\MappingProfilesPage::class,
	\WPEssential\BankTransferMatcherPro\Admin\ImportStatementsPage::class,
	\WPEssential\BankTransferMatcherPro\Admin\BankTransactionsPage::class,
	\WPEssential\BankTransferMatcherPro\Admin\ReviewQueuePage::class,
	\WPEssential\BankTransferMatcherPro\Admin\AllocationReviewPage::class,
	\WPEssential\BankTransferMatcherPro\Admin\ReportExportsPage::class,
	\WPEssential\BankTransferMatcherPro\Admin\FinanceNotificationsPage::class,
	\WPEssential\BankTransferMatcherPro\Admin\FinanceDashboardPage::class,
	\WPEssential\BankTransferMatcherPro\Admin\FinanceOperationsPage::class,
	\WPEssential\BankTransferMatcherPro\Admin\FinanceRetentionPage::class,
];
if ( $free_ready && false === has_action( 'admin_menu', [ $free_class, 'register_menu' ] ) ) {
	throw new RuntimeException( 'Free WordPress submenu callback is unregistered.' );
}
if ( $pro_ready ) {
	foreach ( $pro_classes as $class ) {
		if ( ! is_callable( [ $class, 'menu' ] ) ||
			false === has_action( 'admin_menu', [ $class, 'menu' ] ) ) {
			throw new RuntimeException( 'Pro submenu callback is unregistered: ' . $class );
		}
	}
}

$user_name = 'wbm_nav_ci_' . getmypid();
$user_id = wp_create_user( $user_name, wp_generate_password( 32, true, true ), $user_name . '@example.test' );
if ( is_wp_error( $user_id ) ) {
	throw new RuntimeException( 'Unable to create disposable P7 navigation fixture.' );
}
$original_user = get_current_user_id();
try {
	$user = new WP_User( (int) $user_id );
	$user->set_role( 'subscriber' );
	$user->add_cap( 'manage_woocommerce', true );
	$user->add_cap( 'wbm_view', true );
	$grantable = [ 'wbm_import', 'wbm_approve', 'wbm_manage_bank_accounts' ];
	$cases = [
		'viewer' => [],
		'importer' => [ 'wbm_import' ],
		'finance' => [ 'wbm_import', 'wbm_approve' ],
		'manager' => $grantable,
	];
	$base = [ 'wpessential-bank-transfer-matcher' ];
	$review = [ 'wpessential-wbm-pro-review-queue' ];
	$import = [
		'wpessential-wbm-pro-bank-accounts',
		'wpessential-wbm-pro-mapping-profiles',
		'wpessential-wbm-pro-import-statements',
		'wpessential-wbm-pro-bank-transactions',
	];
	$finance = [
		'wpessential-wbm-pro-dashboard',
		'wpessential-wbm-pro-allocations',
		'wpessential-wbm-pro-finance-reports',
	];
	$managed = [
		'wpessential-wbm-pro-notifications',
		'wpessential-wbm-pro-operations',
		'wpessential-wbm-pro-retention',
	];
	foreach ( $cases as $name => $granted ) {
		foreach ( $grantable as $capability ) {
			$user->add_cap( $capability, in_array( $capability, $granted, true ) );
		}
		wp_set_current_user( (int) $user_id );
		// Invoke the registered admin_menu callbacks against genuine WordPress
		// add_submenu_page() with isolated admin globals.
		global $submenu, $menu, $_registered_pages, $_parent_pages, $admin_page_hooks;
		$submenu = [];
		$menu = [];
		$_registered_pages = [];
		$_parent_pages = [];
		$admin_page_hooks = [ 'woocommerce' => 'woocommerce' ];
		if ( $free_ready ) {
			$free_class::register_menu();
		}
		if ( $pro_ready ) {
			foreach ( $pro_classes as $class ) {
				$class::menu();
			}
		}
		$actual = [];
		foreach ( $submenu['woocommerce'] ?? [] as $entry ) {
			if ( isset( $entry[2] ) && is_string( $entry[2] ) &&
				str_starts_with( $entry[2], 'wpessential-' ) ) {
				$actual[] = $entry[2];
			}
		}
		if ( count( $actual ) !== count( array_unique( $actual ) ) ) {
			throw new RuntimeException( 'Duplicate WooCommerce submenu slug for ' . $name );
		}
		$expected = $free_ready ? $base : [];
		if ( $pro_ready ) {
			$expected = array_merge( $expected, $review );
			if ( in_array( 'wbm_import', $granted, true ) ) {
				$expected = array_merge( $expected, $import );
			}
			if ( in_array( 'wbm_import', $granted, true ) &&
				in_array( 'wbm_approve', $granted, true ) ) {
				$expected = array_merge( $expected, $finance );
			}
			if ( count( $granted ) === 3 ) {
				$expected = array_merge( $expected, $managed );
			}
		}
		sort( $actual );
		sort( $expected );
		if ( $actual !== $expected ) {
			throw new RuntimeException( 'Inaccessible or missing menu in ' . $mode .
				'/' . $name . ': got ' . implode( ',', $actual ) .
				'; expected ' . implode( ',', $expected ) );
		}
	}
	echo "PASS P7-T006 WordPress submenu role gates and unique real slugs: {$mode}\n";
} finally {
	wp_set_current_user( $original_user );
	wp_delete_user( (int) $user_id );
}
