<?php

use WPEssential\BankTransferMatcherPro\Admin\BankTransactionsPage;
use WPEssential\BankTransferMatcherPro\Admin\ImportStatementsPage;
use WPEssential\BankTransferMatcherPro\Import\BankAccountRepository;
use WPEssential\BankTransferMatcherPro\Import\ImportJob;
use WPEssential\BankTransferMatcherPro\Import\MappingProfileRepository;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'wbm_ci' !== DB_NAME ) throw new RuntimeException( 'Run against disposable wbm_ci only.' );
global $wpdb;
$actor = get_user_by( 'login', 'wbm_ci' );
wp_set_current_user( $actor->ID );
$account = ( new BankAccountRepository( $wpdb ) )->create( 'UI fixture', 'Fixture Bank', 'USD', '9876', $actor->ID );
$profile = ( new MappingProfileRepository() )->create( array(
	'name' => 'UI fixture mapping', 'delimiter' => ',', 'date_header' => 'Date', 'date_format' => 'Y-m-d',
	'amount_header' => 'Amount', 'decimal_separator' => '.', 'thousands_separator' => '', 'exponent' => 2,
	'currency_header' => '', 'fixed_currency' => 'USD', 'reference_header' => 'Reference',
	'description_header' => 'Description', 'transaction_id_header' => 'Bank ID',
) );
$source = tempnam( sys_get_temp_dir(), 'wbm-ui-' );
file_put_contents( $source, "Date,Amount,Reference,Description,Bank ID\n2026-11-19,25.00,PRIVATE-REFERENCE,Fixture payment,TX-100\n" );
$batch = 0;
try {
	ob_start(); ImportStatementsPage::render(); $initial = ob_get_clean();
	if ( ! str_contains( $initial, 'Validate and preview' ) || ! str_contains( $initial, 'Imported rows remain unverified' ) ) throw new RuntimeException( 'Import first-run form or safety copy is missing.' );
	$job = new ImportJob( $wpdb );
	$batch = $job->start( $source, $account, $profile, $actor->ID );
	$job->process( $batch );
	$_GET = array( 'page' => 'wpessential-wbm-pro-import-statements', 'batch' => $batch );
	ob_start(); ImportStatementsPage::render(); $history = ob_get_clean();
	if ( ! str_contains( $history, '#' . $batch ) || ! str_contains( $history, 'Completed' ) || ! str_contains( $history, '1 valid' ) ) throw new RuntimeException( 'Persisted import result is missing.' );
	$_GET = array( 'page' => 'wpessential-wbm-pro-bank-transactions', 'batch' => $batch );
	ob_start(); BankTransactionsPage::render(); $transactions = ob_get_clean();
	if ( ! str_contains( $transactions, '25.00 USD' ) && ! str_contains( $transactions, '2500 USD' ) ) throw new RuntimeException( 'Imported amount is missing.' );
	if ( str_contains( $transactions, 'PRIVATE-REFERENCE' ) || ! str_contains( $transactions, 'PR' ) ) throw new RuntimeException( 'Transaction reference was not masked.' );
	if ( ! str_contains( $transactions, 'unverified' ) ) throw new RuntimeException( 'Unverified evidence warning is missing.' );
	echo "PASS import UI first-run, persisted history, progress result and masked transaction evidence\n";
} finally {
	$_GET = array();
	if ( $batch ) {
		$path = $wpdb->get_var( $wpdb->prepare( "SELECT source_path FROM `{$wpdb->prefix}wbm_pro_import_jobs` WHERE batch_id = %d", $batch ) );
		if ( is_string( $path ) && is_file( $path ) ) unlink( $path );
		$wpdb->delete( $wpdb->prefix . 'wbm_pro_import_rows', array( 'batch_id' => $batch ), array( '%d' ) );
		$wpdb->delete( $wpdb->prefix . 'wbm_pro_import_jobs', array( 'batch_id' => $batch ), array( '%d' ) );
		$wpdb->delete( $wpdb->prefix . 'wbm_pro_import_batches', array( 'id' => $batch ), array( '%d' ) );
	}
	$wpdb->delete( $wpdb->prefix . 'wbm_pro_bank_accounts', array( 'id' => $account ), array( '%d' ) );
	unlink( $source );
}
