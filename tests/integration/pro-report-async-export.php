<?php
use WPEssential\BankTransferMatcherPro\Import\BankAccountRepository;
use WPEssential\BankTransferMatcherPro\Import\ImportBatchRepository;
use WPEssential\BankTransferMatcherPro\Import\MappingProfile;
use WPEssential\BankTransferMatcherPro\Reporting\AsyncReportExportJob;
use WPEssential\BankTransferMatcherPro\Reporting\ReportDefinition;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'wbm_ci' !== DB_NAME ) {
	throw new RuntimeException( 'Queued export fixture must run in disposable wbm_ci.' );
}
global $wpdb;
$admin = get_user_by( 'login', 'wbm_ci' );
if ( ! $admin ) throw new RuntimeException( 'No finance test admin.' );
$prior = get_current_user_id();
wp_set_current_user( $admin->ID );
$bank = ( new BankAccountRepository( $wpdb ) )->create(
	'Async CSV fixture account', 'Synthetic', 'USD', '2899', $admin->ID
);
$profile = MappingProfile::from_array( [
	'id' => 'async-audit-test', 'name' => 'Async CSV fixture', 'delimiter' => ',',
	'date_header' => 'Date', 'date_format' => 'Y-m-d', 'amount_header' => 'Amount',
	'decimal_separator' => '.', 'thousands_separator' => '', 'exponent' => 2,
	'currency_header' => '', 'fixed_currency' => 'USD', 'reference_header' => 'Reference',
	'description_header' => '', 'transaction_id_header' => 'Bank ID',
] );
$batch = ( new ImportBatchRepository( $wpdb ) )->create(
	$bank, $profile, hash( 'sha256', 'async-export-fixture/' . wp_generate_uuid4() ),
	100, $admin->ID
);
$scope = ReportDefinition::from_array( [
	'report' => 'import_batches', 'from' => gmdate( 'Y-m-d' ),
	'to' => gmdate( 'Y-m-d' ), 'bank_account_id' => $bank,
	'currency' => 'USD', 'state' => 'pending', 'limit' => 10,
] );
$job = new AsyncReportExportJob( $wpdb );
$nonce = wp_create_nonce( 'wbm_pro_export_report' );
$cancel_nonce = wp_create_nonce( 'wbm_pro_cancel_queued_export' );
$download_nonce = wp_create_nonce( 'wbm_pro_download_queued_export' );
$ids = [];
$extra_batches = [];
$reject = static function ( callable $call, string $name ): void {
	try {
		$call();
		throw new RuntimeException( 'Unsafe queued export accepted: ' . $name );
	} catch ( RuntimeException $expected ) {
		if ( str_starts_with( $expected->getMessage(), 'Unsafe queued export accepted:' ) ) {
			throw $expected;
		}
	}
};
try {
	$reject( static fn () => $job->start( $scope, $admin->ID, 'invalid-nonce' ),
		'unguarded Action Scheduler enqueue' );
	$first = $job->start( $scope, $admin->ID, $nonce );
	$ids[] = $first;
	$second = $job->start( $scope, $admin->ID, $nonce );
	$ids[] = $second;
	$reject( static fn () => $job->start( $scope, $admin->ID, $nonce ),
		'third live export job per finance actor' );
	$job->process( $first );
	$status = $job->status( $first, $admin->ID );
	if ( $status['status'] !== 'ready' || $status['row_count'] !== 1 ||
		$status['pages_done'] !== 1 || ! $status['can_download'] ) {
		throw new RuntimeException( 'Scheduled report did not create bounded one-page CSV.' );
	}
	$job->process( $first ); // Duplicate worker action must be idempotent.
	$reject( static fn () => $job->download( $first, $admin->ID, 'bad-nonce' ),
		'ungated queued CSV download' );
	$data = $job->download( $first, $admin->ID, $download_nonce );
	if ( ! str_contains( $data['csv'], 'valid_count' ) ||
		! str_contains( $data['csv'], 'pending' ) ||
		str_contains( $data['csv'], 'profile_snapshot' ) ||
		str_contains( $data['csv'], 'file_sha256' ) ) {
		throw new RuntimeException( 'Queued CSV contained unapproved sensitive fields.' );
	}
	// Download-rate controls apply to queued CSVs too, not just the
	// immediate export endpoint. The audit table records exactly 3.
	$job->download( $first, $admin->ID, $download_nonce );
	$job->download( $first, $admin->ID, $download_nonce );
	$reject( static fn () => $job->download( $first, $admin->ID, $download_nonce ),
		'fourth queued CSV download in one minute' );
	$job->cancel( $second, $admin->ID, $cancel_nonce );
	if ( $job->status( $second, $admin->ID )['status'] !== 'cancelled' ) {
		throw new RuntimeException( 'Queued cancellation did not clear job.' );
	}
	$job->process( $second ); // A cancelled action must remain inactive.
	// Synthetic 105-row set exercises real keyset pagination and the
	// single-header multi-page CSV merge, not just a happy one-row job.
	for ( $n = 0; $n < 105; ++$n ) {
		$extra_batches[] = ( new ImportBatchRepository( $wpdb ) )->create(
			$bank, $profile, hash( 'sha256', 'async-csv-page-' . wp_generate_uuid4() ),
			100, $admin->ID
		);
	}
	$third = $job->start( $scope, $admin->ID, $nonce );
	$ids[] = $third;
	$job->process( $third );
	$partial = $job->status( $third, $admin->ID );
	if ( $partial['status'] !== 'running' || $partial['pages_done'] !== 1 ||
		$partial['row_count'] !== 100 || $partial['can_download'] ) {
		throw new RuntimeException( 'Multi-page job prematurely advertised incomplete CSV.' );
	}
	$job->process( $third );
	$final = $job->status( $third, $admin->ID );
	if ( $final['status'] !== 'ready' || $final['pages_done'] !== 2 ||
		$final['row_count'] !== 106 ) {
		throw new RuntimeException( 'Queued report cursor lost rows across page boundary.' );
	}
	$contents = $wpdb->get_var( $wpdb->prepare(
		"SELECT csv_data FROM {$wpdb->prefix}wbm_pro_report_export_jobs WHERE id=%d",
		$third
	) );
	$all = array_filter( explode( "\n", trim( (string) $contents ) ) );
	if ( count( $all ) !== 107 ||
		substr_count( (string) $contents, "id,bank_account_id,currency,state," ) !== 1 ) {
		throw new RuntimeException( 'Multi-page queued CSV duplicated its header or missed a source row.' );
	}
	wp_set_current_user( 0 );
	$reject( static fn () => $job->status( $first, $admin->ID ),
		'anonymous queued export status' );
	$reject( static fn () => $job->download( $first, $admin->ID, $download_nonce ),
		'anonymous private queued export download' );
	wp_set_current_user( $admin->ID );
	$wpdb->query( $wpdb->prepare(
		"UPDATE {$wpdb->prefix}wbm_pro_report_export_jobs
		 SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 10 SECOND) WHERE id=%d",
		$first
	) );
	$job->expire( $first );
	if ( $job->status( $first, $admin->ID )['can_download'] ) {
		throw new RuntimeException( 'Expired private export was still downloadable.' );
	}
	$reject( static fn () => $job->download( $first, $admin->ID, $download_nonce ),
		'read expired finance CSV' );
	$job->cancel( $third, $admin->ID, $cancel_nonce );
	$reject( static fn () => $job->start( $scope, $admin->ID, $nonce ),
		'fourth export job queued within one minute even with cancelled earlier jobs' );
	echo "PASS queued finance CSV owner auth, nonce, Action Scheduler, 2-active cap, idempotent worker, cancellation, expiry, redaction\n";
} finally {
	wp_set_current_user( $admin->ID );
	foreach ( $ids as $id ) {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( AsyncReportExportJob::HOOK,
				[ $id ], 'wpessential-wbm-report-export' );
			as_unschedule_all_actions( AsyncReportExportJob::EXPIRY_HOOK,
				[ $id ], 'wpessential-wbm-report-export' );
		}
		$wpdb->delete( $wpdb->prefix . 'wbm_pro_report_export_jobs', [ 'id' => $id ], [ '%d' ] );
	}
	$export_scope = $scope->canonical();
	unset( $export_scope['version'] );
	$export_scope['limit'] = 100;
	$export_scope = ReportDefinition::from_array( $export_scope );
	$wpdb->delete( $wpdb->prefix . 'wbm_pro_report_exports',
		[ 'scope_sha256' => $export_scope->fingerprint() ], [ '%s' ] );
	foreach ( $extra_batches as $extra_id ) {
		$wpdb->delete( $wpdb->prefix . 'wbm_pro_import_batches',
			[ 'id' => $extra_id ], [ '%d' ] );
	}
	$wpdb->delete( $wpdb->prefix . 'wbm_pro_import_batches', [ 'id' => $batch ], [ '%d' ] );
	$wpdb->delete( $wpdb->prefix . 'wbm_pro_bank_accounts', [ 'id' => $bank ], [ '%d' ] );
	wp_set_current_user( $prior );
}
