<?php
use WPEssential\BankTransferMatcherPro\Import\BankAccountRepository;
use WPEssential\BankTransferMatcherPro\Import\ImportBatchRepository;
use WPEssential\BankTransferMatcherPro\Import\MappingProfile;
use WPEssential\BankTransferMatcherPro\Reporting\ReportDefinition;
use WPEssential\BankTransferMatcherPro\Reporting\ReportCsvExportService;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'wbm_ci' !== DB_NAME ) {
	throw new RuntimeException( 'Finance CSV audit test requires disposable wbm_ci.' );
}
global $wpdb;
$admin = get_user_by( 'login', 'wbm_ci' );
wp_set_current_user( $admin->ID );

$bank_id = ( new BankAccountRepository( $wpdb ) )->create(
	'CSV-only synthetic account', 'Synthetic', 'USD', '0199', $admin->ID
);
$profile = MappingProfile::from_array( [
	'id' => 'csv-audit-test', 'name' => 'CSV audit fixture', 'delimiter' => ',',
	'date_header' => 'Date', 'date_format' => 'Y-m-d', 'amount_header' => 'Amount',
	'decimal_separator' => '.', 'thousands_separator' => '', 'exponent' => 2,
	'currency_header' => '', 'fixed_currency' => 'USD',
	'reference_header' => 'Reference', 'description_header' => '',
	'transaction_id_header' => 'Bank ID',
] );
$batch_id = ( new ImportBatchRepository( $wpdb ) )->create(
	$bank_id, $profile, hash( 'sha256', 'csv-audit-' . wp_generate_uuid4() ), 100,
	$admin->ID
);
$scope = ReportDefinition::from_array( [
	'report' => 'import_batches', 'from' => gmdate( 'Y-m-d' ),
	'to' => gmdate( 'Y-m-d' ), 'bank_account_id' => $bank_id,
	'currency' => 'USD', 'state' => 'pending', 'limit' => 1,
] );
$service = new ReportCsvExportService( $wpdb );
$nonce = wp_create_nonce( 'wbm_pro_export_report' );
$table = $wpdb->prefix . 'wbm_pro_report_exports';
try {
	for ( $iteration = 0; $iteration < 3; ++$iteration ) {
		$csv = $service->export( $scope, $admin->ID, $nonce );
		if ( $csv['row_count'] !== 1 || $csv['has_more'] ||
			$csv['next_before_id'] !== 0 ||
			$csv['sha256'] !== hash( 'sha256', $csv['csv'] ) ||
			! str_contains( $csv['csv'], 'valid_count' ) ||
			str_contains( $csv['csv'], 'file_sha256' ) ||
			str_contains( $csv['csv'], 'profile_snapshot' ) ) {
			throw new RuntimeException( 'Authorized export leaked importer internals or omitted exact counts.' );
		}
	}
	$audit = $wpdb->get_results( $wpdb->prepare(
		"SELECT report_key, csv_sha256, row_count, byte_count
		 FROM $table WHERE actor_id = %d AND scope_sha256 = %s",
		$admin->ID, $scope->fingerprint()
	), ARRAY_A );
	if ( count( $audit ) !== 3 ||
		(int) $audit[0]['row_count'] !== 1 ||
		(int) $audit[0]['byte_count'] !== strlen( $csv['csv'] ) ||
		$audit[0]['csv_sha256'] !== $csv['sha256'] ||
		$audit[0]['report_key'] !== 'import_batches' ) {
		throw new RuntimeException( 'Verified export was not independently audited.' );
	}
	try {
		$service->export( $scope, $admin->ID, $nonce );
		throw new RuntimeException( 'Repeated finance export bypassed rate limit.' );
	} catch ( RuntimeException $expected ) {
		if ( 'Repeated finance export bypassed rate limit.' === $expected->getMessage() ) throw $expected;
	}
	try {
		$service->export( $scope, $admin->ID, 'invalid-nonce' );
		throw new RuntimeException( 'Invalid export nonce was accepted.' );
	} catch ( RuntimeException $expected ) {
		if ( 'Invalid export nonce was accepted.' === $expected->getMessage() ) throw $expected;
	}
	wp_set_current_user( 0 );
	try {
		$service->export( $scope, $admin->ID, $nonce );
		throw new RuntimeException( 'Anonymous finance CSV export was accepted.' );
	} catch ( RuntimeException $expected ) {
		if ( 'Anonymous finance CSV export was accepted.' === $expected->getMessage() ) throw $expected;
	}
	wp_set_current_user( $admin->ID );
	echo "PASS finance-only nonce/audited CSV output, 3/minute rate guard, exact synthetic counts and denied access\n";
} finally {
	wp_set_current_user( $admin->ID );
	$wpdb->delete( $table, [ 'scope_sha256' => $scope->fingerprint() ], [ '%s' ] );
	$wpdb->delete( $wpdb->prefix . 'wbm_pro_import_batches', [ 'id' => $batch_id ], [ '%d' ] );
	$wpdb->delete( $wpdb->prefix . 'wbm_pro_bank_accounts', [ 'id' => $bank_id ], [ '%d' ] );
}
