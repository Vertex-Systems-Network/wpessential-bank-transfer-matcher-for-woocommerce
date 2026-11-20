<?php
use WPEssential\BankTransferMatcherPro\Admin\FinanceRetentionPage;
use WPEssential\BankTransferMatcherPro\Import\BankAccountRepository;
use WPEssential\BankTransferMatcherPro\Import\ImportBatchRepository;
use WPEssential\BankTransferMatcherPro\Import\ImportJob;
use WPEssential\BankTransferMatcherPro\Import\MappingProfile;
use WPEssential\BankTransferMatcherPro\Privacy\FinanceRetentionPreferences;
use WPEssential\BankTransferMatcherPro\Privacy\FinanceRetentionService;
use WPEssential\BankTransferMatcherPro\Reporting\AsyncReportExportJob;
use WPEssential\BankTransferMatcherPro\Reporting\ReportDefinition;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'wbm_ci' !== DB_NAME ) {
	throw new RuntimeException( 'Retention cleanup tests require disposable wbm_ci only.' );
}
global $wpdb;
$admin = get_user_by( 'login', 'wbm_ci' );
if ( ! $admin ) throw new RuntimeException( 'Finance manager fixture missing.' );
$old_actor = get_current_user_id();
$old_option = get_option( FinanceRetentionPreferences::OPTION, false );
$old_get = $_GET;
wp_set_current_user( $admin->ID );
$service = new FinanceRetentionService( $wpdb );
$nonce = wp_create_nonce( 'wbm_pro_retention_save' );
$sweep_nonce = wp_create_nonce( 'wbm_pro_retention_sweep' );
$bank = ( new BankAccountRepository( $wpdb ) )->create(
	'Retention synthetic bank', 'Synthetic', 'USD', '1122', $admin->ID
);
$profile = MappingProfile::from_array( [
	'id' => 'retention-fixture', 'name' => 'Synthetic retention profile',
	'delimiter' => ',', 'date_header' => 'Date', 'date_format' => 'Y-m-d',
	'amount_header' => 'Amount', 'decimal_separator' => '.',
	'thousands_separator' => '', 'exponent' => 2, 'currency_header' => '',
	'fixed_currency' => 'USD', 'reference_header' => 'Reference',
	'description_header' => '', 'transaction_id_header' => 'Bank ID',
] );
$source = tempnam( sys_get_temp_dir(), 'wbm-retention-' );
file_put_contents( $source, "Date,Amount,Reference,Bank ID\n" .
	gmdate( 'Y-m-d' ) . ",1.00,TEST-RETENTION,RET-001\n" );
$batch = 0;
$report_job = 0;
$history_ids = [];
$reject = static function ( callable $callback, string $name ): void {
	try {
		$callback();
		throw new RuntimeException( 'Unsafe financial retention action allowed: ' . $name );
	} catch ( RuntimeException|InvalidArgumentException $expected ) {
		if ( str_starts_with( $expected->getMessage(), 'Unsafe financial retention action allowed:' ) )
			throw $expected;
	}
};
try {
	if ( false === has_action( FinanceRetentionService::HOOK ) ||
		false === has_action( 'admin_menu', [ FinanceRetentionPage::class, 'menu' ] ) ||
		false !== has_action( 'admin_post_nopriv_wbm_pro_retention_sweep' ) ) {
		throw new RuntimeException( 'Retention worker or admin privacy gate not registered securely.' );
	}
	if ( $service->settings()['automatic_cleanup'] !== false ||
		$service->settings()['private_source_days'] !== 0 ||
		$service->sweep( 0, '', false ) !== [] ) {
		throw new RuntimeException( 'Unapproved retention worker started destructive cleanup.' );
	}
	$import = new ImportJob( $wpdb );
	$batch = $import->start( $source, $bank, $profile, $admin->ID );
	$batches = new ImportBatchRepository( $wpdb );
	$batches->transition( $batch, 'pending', 'processing' );
	$batches->transition( $batch, 'processing', 'completed', 1 );
	$wpdb->query( $wpdb->prepare(
		"UPDATE {$wpdb->prefix}wbm_pro_import_batches
		 SET updated_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 40 DAY) WHERE id=%d",
		$batch
	) );
	$staged = $wpdb->get_var( $wpdb->prepare(
		"SELECT source_path FROM {$wpdb->prefix}wbm_pro_import_jobs WHERE batch_id=%d",
		$batch
	) );
	if ( ! is_string( $staged ) || ! is_file( $staged ) ) {
		throw new RuntimeException( 'Synthetic bank import source was not privately staged.' );
	}
	$policy = FinanceRetentionPreferences::defaults();
	$service->save( $policy, $admin->ID, $nonce );
	$service->sweep( $admin->ID, $sweep_nonce, true );
	if ( ! is_file( $staged ) ) {
		throw new RuntimeException( 'Default zero-day sentinel incorrectly deleted private source.' );
	}
	$policy['private_source_days'] = 30;
	$policy['diagnostic_audit_days'] = 30;
	$service->save( $policy, $admin->ID, $nonce );

	$scope = ReportDefinition::from_array( [
		'report' => 'import_batches', 'from' => gmdate( 'Y-m-d' ),
		'to' => gmdate( 'Y-m-d' ), 'currency' => 'USD',
		'bank_account_id' => $bank, 'limit' => 20,
	] );
	$report_job = ( new AsyncReportExportJob( $wpdb ) )->start(
		$scope, $admin->ID, wp_create_nonce( 'wbm_pro_export_report' )
	);
	$secret = 'NONPUBLIC-CUSTOMER-BANK-SOURCE-MUST-BE-ERASED';
	$wpdb->query( $wpdb->prepare(
		"UPDATE {$wpdb->prefix}wbm_pro_report_export_jobs
		 SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 5 MINUTE),
		 status='ready',csv_data=%s,csv_sha256=%s,byte_count=%d
		 WHERE id=%d",
		$secret, hash( 'sha256', $secret ), strlen( $secret ), $report_job
	) );
	$table = $wpdb->prefix . 'wbm_pro_diagnostics_exports';
	$insert = $wpdb->query( $wpdb->prepare(
		"INSERT INTO $table (actor_id,csv_sha256,byte_count,created_at)
		 VALUES (%d,%s,100,DATE_SUB(UTC_TIMESTAMP(),INTERVAL 100 DAY))",
		$admin->ID, hash( 'sha256', 'old-synthetic-redacted-audit' )
	) );
	if ( 1 !== $insert ) throw new RuntimeException( 'Synthetic old support audit not inserted.' );
	$history_ids[] = (int) $wpdb->insert_id;
	$reject( static fn () => $service->sweep( $admin->ID, 'forged-retention-nonce', true ),
		'cleanup without explicit merchant nonce' );
	$_GET = [ 'page' => 'wpessential-wbm-pro-retention' ];
	ob_start();
	FinanceRetentionPage::render();
	$html = ob_get_clean();
	if ( ! str_contains( $html, 'Bank data retention' ) ||
		! str_contains( $html, 'name="action" value="wbm_pro_retention_save"' ) ||
		! str_contains( $html, 'name="action" value="wbm_pro_retention_sweep"' ) ||
		! str_contains( $html, 'name="consent_auto"' ) ||
		! str_contains( $html, 'name="confirm"' ) ||
		str_contains( $html, $staged ) ) {
		throw new RuntimeException( 'Retention admin screen lost explicit consent controls or leaked source file.' );
	}
	$counts = $service->sweep( $admin->ID, $sweep_nonce, true );
	if ( $counts['expired_private_report_jobs'] !== 1 ||
		$counts['private_source_files_removed'] !== 1 ||
		$counts['diagnostic_audit_days_removed'] < 1 ||
		is_file( $staged ) ) {
		throw new RuntimeException( 'Merchant-approved cleanup did not erase bounded expired/private sources.' );
	}
	$report_state = $wpdb->get_row( $wpdb->prepare(
		"SELECT status,csv_data FROM {$wpdb->prefix}wbm_pro_report_export_jobs WHERE id=%d",
		$report_job
	), ARRAY_A );
	$batch_state = $wpdb->get_row( $wpdb->prepare(
		"SELECT b.status,j.source_path FROM {$wpdb->prefix}wbm_pro_import_batches b
		 JOIN {$wpdb->prefix}wbm_pro_import_jobs j ON j.batch_id=b.id WHERE b.id=%d",
		$batch
	), ARRAY_A );
	if ( $report_state['status'] !== 'expired' || $report_state['csv_data'] !== '' ||
		$batch_state['status'] !== 'completed' || $batch_state['source_path'] !== '' ) {
		throw new RuntimeException( 'Privacy purge lost ledger state or retained nonpublic private CSV.' );
	}
	if ( $service->sweep( $admin->ID, $sweep_nonce, true )['private_source_files_removed'] !== 0 ) {
		throw new RuntimeException( 'Private file cleanup was not idempotent.' );
	}
	$policy['automatic_cleanup'] = true;
	$service->save( $policy, $admin->ID, $nonce );
	if ( ! as_has_scheduled_action( FinanceRetentionService::HOOK, [],
		'wpessential-wbm-retention' ) ) {
		throw new RuntimeException( 'Explicitly opted-in daily cleanup was not scheduled.' );
	}
	$policy['automatic_cleanup'] = false;
	$service->save( $policy, $admin->ID, $nonce );
	if ( as_has_scheduled_action( FinanceRetentionService::HOOK, [],
		'wpessential-wbm-retention' ) ) {
		throw new RuntimeException( 'Opt-out failed to remove daily cleanup.' );
	}
	wp_set_current_user( 0 );
	$reject( static fn () => $service->save( $policy, $admin->ID, $nonce ),
		'non-manager retention policy update' );
	$reject( static fn () => $service->sweep( $admin->ID, $sweep_nonce, true ),
		'non-manager manual source deletion' );
	wp_set_current_user( $admin->ID );
	echo "PASS merchant consent default-off retention, private source hash/path purge, 2h export cleanup, 30d audit cap, idempotency, role+nonce and scheduler opt-out\n";
} finally {
	wp_set_current_user( $admin->ID );
	$_GET = $old_get;
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		if ( $batch ) as_unschedule_all_actions( ImportJob::HOOK,
			[ $batch ], 'wpessential-wbm-import' );
		if ( $report_job ) {
			as_unschedule_all_actions( AsyncReportExportJob::HOOK,
				[ $report_job ], 'wpessential-wbm-report-export' );
			as_unschedule_all_actions( AsyncReportExportJob::EXPIRY_HOOK,
				[ $report_job ], 'wpessential-wbm-report-export' );
		}
		as_unschedule_all_actions( FinanceRetentionService::HOOK, [],
			'wpessential-wbm-retention' );
	}
	if ( $report_job ) $wpdb->delete( $wpdb->prefix . 'wbm_pro_report_export_jobs',
		[ 'id' => $report_job ], [ '%d' ] );
	if ( $batch ) {
		$stored = $wpdb->get_var( $wpdb->prepare(
			"SELECT source_path FROM {$wpdb->prefix}wbm_pro_import_jobs WHERE batch_id=%d",
			$batch
		) );
		if ( is_string( $stored ) && is_file( $stored ) ) unlink( $stored );
		$wpdb->delete( $wpdb->prefix . 'wbm_pro_import_rows',
			[ 'batch_id' => $batch ], [ '%d' ] );
		$wpdb->delete( $wpdb->prefix . 'wbm_pro_import_jobs',
			[ 'batch_id' => $batch ], [ '%d' ] );
		$wpdb->delete( $wpdb->prefix . 'wbm_pro_import_batches',
			[ 'id' => $batch ], [ '%d' ] );
	}
	foreach ( $history_ids as $id ) {
		$wpdb->delete( $wpdb->prefix . 'wbm_pro_diagnostics_exports', [ 'id' => $id ], [ '%d' ] );
	}
	$wpdb->delete( $wpdb->prefix . 'wbm_pro_retention_runs', [ 'actor_id' => $admin->ID ], [ '%d' ] );
	if ( false === $old_option ) delete_option( FinanceRetentionPreferences::OPTION );
	else update_option( FinanceRetentionPreferences::OPTION, $old_option, false );
	$wpdb->delete( $wpdb->prefix . 'wbm_pro_bank_accounts', [ 'id' => $bank ], [ '%d' ] );
	if ( is_file( $source ) ) unlink( $source );
	wp_set_current_user( $old_actor );
}
