<?php

use WPEssential\BankTransferMatcherPro\Access\Capabilities;
use WPEssential\BankTransferMatcherPro\Admin\BankAccountsPage;
use WPEssential\BankTransferMatcherPro\Import\BankAccountRepository;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'wbm_ci' !== DB_NAME ) throw new RuntimeException( 'Run against the disposable wbm_ci database.' );
global $wpdb;
require_once ABSPATH . 'wp-admin/includes/user.php';
$admin = get_user_by( 'login', 'wbm_ci' );
$manager = get_users( [ 'role' => 'shop_manager', 'number' => 1 ] )[0] ?? null;
if ( ! $manager ) {
	$manager_id = wp_insert_user( [ 'user_login' => 'wbm_pro_manager', 'user_pass' => wp_generate_password( 24 ), 'user_email' => 'wbm-pro-manager@example.test', 'role' => 'shop_manager' ] );
	$manager = get_user_by( 'id', $manager_id );
}
$created_manager = 'wbm_pro_manager' === $manager->user_login;
$account_id = 0;
try {
	wp_set_current_user( $admin->ID );
	if ( ! current_user_can( Capabilities::IMPORT ) || ! current_user_can( Capabilities::MANAGE_ACCOUNTS ) ) throw new RuntimeException( 'Administrator Pro capabilities are missing.' );
	$account_id = BankAccountsPage::process_create( [ 'nickname' => 'Primary USD', 'bank_name' => 'Fixture Bank', 'currency' => 'usd', 'identifier_last4' => 'A123' ] );
	if ( is_wp_error( $account_id ) || $account_id < 1 ) throw new RuntimeException( 'Administrator could not create safe bank account metadata.' );
	$invalid = BankAccountsPage::process_create( [ 'nickname' => 'Unsafe', 'bank_name' => 'Fixture Bank', 'currency' => 'USD', 'identifier_last4' => 'FULL-ACCOUNT-1234' ] );
	if ( ! is_wp_error( $invalid ) || 'wbm_pro_invalid_account' !== $invalid->get_error_code() ) throw new RuntimeException( 'Unsafe full identifier was accepted.' );
	$columns = $wpdb->get_col( "SHOW COLUMNS FROM `{$wpdb->prefix}wbm_pro_bank_accounts`" );
	foreach ( [ 'account_number', 'iban', 'bic', 'routing_number' ] as $forbidden ) if ( in_array( $forbidden, $columns, true ) ) throw new RuntimeException( 'Sensitive bank identifier column exists: ' . $forbidden );
	ob_start(); BankAccountsPage::render(); $admin_html = ob_get_clean();
	if ( ! str_contains( $admin_html, '•••• A123' ) || ! str_contains( $admin_html, 'wbm_pro_save_bank_account' ) || ! str_contains( $admin_html, 'Bank account metadata' ) || ! str_contains( $admin_html, 'wbm-pro-table-scroll' ) || str_contains( $admin_html, 'FULL-ACCOUNT' ) ) throw new RuntimeException( 'Administrator UI did not mask or scope bank metadata correctly.' );

	wp_set_current_user( $manager->ID );
	if ( ! current_user_can( Capabilities::IMPORT ) || current_user_can( Capabilities::MANAGE_ACCOUNTS ) ) throw new RuntimeException( 'Shop manager Pro capability boundary is incorrect.' );
	$forbidden = BankAccountsPage::process_create( [ 'nickname' => 'Blocked', 'bank_name' => 'Bank', 'currency' => 'USD', 'identifier_last4' => '1234' ] );
	if ( ! is_wp_error( $forbidden ) || 403 !== $forbidden->get_error_data() ) throw new RuntimeException( 'Shop manager changed bank account metadata.' );
	ob_start(); BankAccountsPage::render(); $manager_html = ob_get_clean();
	if ( ! str_contains( $manager_html, '•••• A123' ) || str_contains( $manager_html, 'wbm_pro_save_bank_account' ) || str_contains( $manager_html, '>Archive<' ) ) throw new RuntimeException( 'Read-only bank account view exposed management actions.' );

	wp_set_current_user( $admin->ID );
	if ( true !== BankAccountsPage::process_archive( $account_id ) ) throw new RuntimeException( 'Administrator could not archive bank account metadata.' );
	$rows = ( new BankAccountRepository( $wpdb ) )->all();
	if ( 'archived' !== ( $rows[0]['status'] ?? '' ) ) throw new RuntimeException( 'Archive status was not retained.' );
	echo "PASS Pro safe bank metadata schema, masking, archive lifecycle and administrator/shop-manager permission boundary\n";
} finally {
	if ( $account_id ) $wpdb->delete( $wpdb->prefix . 'wbm_pro_bank_accounts', [ 'id' => $account_id ], [ '%d' ] );
	if ( $created_manager ) wp_delete_user( $manager->ID );
}
