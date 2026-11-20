<?php
use WPEssential\BankTransferMatcherPro\Admin\FinanceOperationsPage;
use WPEssential\BankTransferMatcherPro\Import\BankAccountRepository;
use WPEssential\BankTransferMatcherPro\Import\ImportBatchRepository;
use WPEssential\BankTransferMatcherPro\Import\ImportJob;
use WPEssential\BankTransferMatcherPro\Import\MappingProfile;
use WPEssential\BankTransferMatcherPro\Operations\FinanceJobRecovery;
use WPEssential\BankTransferMatcherPro\Operations\FinanceOperationsSnapshot;
use WPEssential\BankTransferMatcherPro\Reporting\AsyncReportExportJob;
use WPEssential\BankTransferMatcherPro\Reporting\ReportDefinition;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'wbm_ci' !== DB_NAME ) {
	throw new RuntimeException( 'Operations integration requires disposable wbm_ci.' );
}
global $wpdb;
$admin = get_user_by( 'login', 'wbm_ci' );
if ( ! $admin ) throw new RuntimeException( 'Missing finance operator fixture.' );
$prior_user = get_current_user_id();
$prior_get = $_GET;
wp_set_current_user( $admin->ID );
$bank = ( new BankAccountRepository( $wpdb ) )->create(
	'Operations synthetic USD bank', 'Synthetic', 'USD', '7755', $admin->ID
);
$profile = MappingProfile::from_array( [
	'id' => 'ops-fixture', 'name' => 'Operations synthetic fixture',
	'delimiter' => ',', 'date_header' => 'Date', 'date_format' => 'Y-m-d',
	'amount_header' => 'Amount', 'decimal_separator' => '.',
	'thousands_separator' => '', 'exponent' => 2, 'currency_header' => '',
	'fixed_currency' => 'USD', 'reference_header' => 'Reference',
	'description_header' => '', 'transaction_id_header' => 'Bank ID',
] );
$source = tempnam( sys_get_temp_dir(), 'wbm-ops-fixture-' );
file_put_contents( $source, "Date,Amount,Reference,Bank ID\n" .
	gmdate( 'Y-m-d' ) . ",10.00,SYNTH-OPS,OPS-0001\n" );
$batch = 0;
$jobs = [];
$recovery = new FinanceJobRecovery( $wpdb );
$snapshot = new FinanceOperationsSnapshot( $wpdb );
$nonce = wp_create_nonce( 'wbm_pro_ops_recovery' );
$diag_nonce = wp_create_nonce( 'wbm_pro_ops_diagnostics_export' );
$reject = static function ( callable $call, string $why ): void {
	try {
		$call();
		throw new RuntimeException( 'Unsafe finance operations action accepted: ' . $why );
	} catch ( InvalidArgumentException|RuntimeException $expected ) {
		if ( str_starts_with( $expected->getMessage(), 'Unsafe finance operations action accepted:' ) ) {
			throw $expected;
		}
	}
};
try {
	if ( false === has_action( 'admin_menu', [ FinanceOperationsPage::class, 'menu' ] ) ||
		false === has_action( 'admin_post_wbm_pro_ops_diagnostics_export' ) ||
		false === has_action( 'admin_post_wbm_pro_ops_recovery' ) ||
		false !== has_action( 'admin_post_nopriv_wbm_pro_ops_recovery' ) ) {
		throw new RuntimeException( 'Private operations admin hooks are missing or publicly exposed.' );
	}
	$import = new ImportJob( $wpdb );
	$batch = $import->start( $source, $bank, $profile, $admin->ID );
	( new ImportBatchRepository( $wpdb ) )->transition( $batch, 'pending', 'failed' );
	$staged = $wpdb->get_var( $wpdb->prepare(
		"SELECT source_path FROM {$wpdb->prefix}wbm_pro_import_jobs WHERE batch_id=%d",
		$batch
	) );
	if ( ! is_string( $staged ) || ! is_file( $staged ) ) {
		throw new RuntimeException( 'Synthetic import missing private staged source.' );
	}
	file_put_contents( $staged, 'tampered source,do not retry' );
	$reject( static fn () => $recovery->recover(
		'retry_import', $batch, $admin->ID, $nonce,
		'Verify synthetic bank import integrity before explicit retry.'
	), 'changed bank source was retried' );
	if ( $wpdb->get_var( $wpdb->prepare(
		"SELECT status FROM {$wpdb->prefix}wbm_pro_import_batches WHERE id=%d", $batch
	) ) !== 'failed' ) {
		throw new RuntimeException( 'Corrupted import was moved out of failed state.' );
	}
	copy( $source, $staged );
	$reject( static fn () => $recovery->recover(
		'retry_import', $batch, $admin->ID, 'forged-nonce',
		'Guarded finance retry requires a valid nonce and manager.'
	), 'forged source recovery' );
	$retry = $recovery->recover(
		'retry_import', $batch, $admin->ID, $nonce,
		'Exact source SHA256 restored and approved for explicit retry.'
	);
	if ( $retry['new_job_id'] !== 0 ||
		$wpdb->get_var( $wpdb->prepare(
			"SELECT status FROM {$wpdb->prefix}wbm_pro_import_batches WHERE id=%d", $batch
		) ) !== 'processing' ) {
		throw new RuntimeException( 'Verified failed import did not resume its checkpoint.' );
	}
	$scope = ReportDefinition::from_array( [
		'report' => 'import_batches', 'from' => gmdate( 'Y-m-d' ),
		'to' => gmdate( 'Y-m-d' ), 'currency' => 'USD',
		'bank_account_id' => $bank, 'state' => 'all',
		'limit' => 100,
	] );
	$exports = new AsyncReportExportJob( $wpdb );
	$original = $exports->start(
		$scope, $admin->ID, wp_create_nonce( 'wbm_pro_export_report' )
	);
	$jobs[] = $original;
	$wpdb->query( $wpdb->prepare(
		"UPDATE {$wpdb->prefix}wbm_pro_report_export_jobs
		 SET status='failed' WHERE id=%d AND status='queued'",
		$original
	) );
	$requeued = $recovery->recover(
		'requeue_export', $original, $admin->ID, $nonce,
		'Source-backed report is read-only; rebuild new bounded private export.'
	);
	$jobs[] = $requeued['new_job_id'];
	if ( $requeued['new_job_id'] < 1 ||
		$requeued['new_job_id'] === $original ||
		'failed' !== $wpdb->get_var( $wpdb->prepare(
			"SELECT status FROM {$wpdb->prefix}wbm_pro_report_export_jobs WHERE id=%d",
			$original
		) ) ) {
		throw new RuntimeException( 'Recovery mutated the original failed export or did not create a new job.' );
	}
	$reject( static fn () => $recovery->recover(
		'requeue_export', $original, $admin->ID, $nonce,
		'Duplicate request must not create a second finance export.'
	), 'duplicate failed export recovery' );
	$report = $snapshot->inspect( $admin->ID );
	foreach ( [ 'import_batches','report_exports','mail_delivery' ] as $type ) {
		if ( ! array_key_exists( $type, $report['sources'] ) ||
			$report['sources'][$type]['page_scoped'] !== true ) {
			throw new RuntimeException( 'Missing or unbounded finance operations diagnostic group.' );
		}
	}
	$csv = $snapshot->export_redacted( $admin->ID, $diag_nonce );
	if ( ! str_contains( $csv['csv'], 'source,state,age_band,processed_rows' ) ||
		! str_contains( $csv['csv'], 'report_exports' ) ||
		str_contains( $csv['csv'], $staged ) ||
		str_contains( $csv['csv'], 'OPS-0001' ) ||
		str_contains( $csv['csv'], 'receiver_id' ) ||
		str_contains( $csv['csv'], 'recipient_id' ) ||
		$csv['sha256'] !== hash( 'sha256', $csv['csv'] ) ) {
		throw new RuntimeException( 'Diagnostics CSV was not redacted or exact hashed.' );
	}
	$snapshot->export_redacted( $admin->ID, $diag_nonce );
	$snapshot->export_redacted( $admin->ID, $diag_nonce );
	$reject( static fn () => $snapshot->export_redacted( $admin->ID, $diag_nonce ),
		'fourth diagnostics CSV per hour' );
	$reject( static fn () => $snapshot->export_redacted( $admin->ID, 'invalid-nonce' ),
		'nonce-less redacted diagnostic export' );
	$_GET = [ 'page' => 'wpessential-wbm-pro-operations' ];
	ob_start();
	FinanceOperationsPage::render();
	$html = ob_get_clean();
	if ( ! str_contains( $html, 'Bank operations diagnostics' ) ||
		! str_contains( $html, 'Redacted support diagnostics' ) ||
		! str_contains( $html, 'name="action" value="wbm_pro_ops_diagnostics_export"' ) ||
		! str_contains( $html, 'name="action" value="wbm_pro_ops_recovery"' ) ||
		str_contains( $html, $staged ) ) {
		throw new RuntimeException( 'Native operations admin UI lost gated controls or leaked private import path.' );
	}
	wp_set_current_user( 0 );
	$reject( static fn () => $snapshot->inspect( $admin->ID ),
		'public diagnostic information disclosure' );
	$reject( static fn () => $recovery->recover(
		'retry_import', $batch, $admin->ID, $nonce,
		'Anonymous actor must not retry failed source.'
	), 'anonymous finance recovery' );
	wp_set_current_user( $admin->ID );
	$audit = $wpdb->get_results( $wpdb->prepare(
		"SELECT operation,outcome,reason_sha256,new_job_id
		 FROM {$wpdb->prefix}wbm_pro_ops_recovery WHERE actor_id=%d",
		$admin->ID
	), ARRAY_A );
	if ( count( $audit ) !== 3 ||
		count( array_filter( $audit, static fn ( array $x ): bool =>
			$x['outcome'] === 'queued' ) ) !== 2 ) {
		throw new RuntimeException( 'Denied/queued finance recovery outcomes not independently audited.' );
	}
	echo "PASS private source-hashed import retry, new bounded export requeue, duplicate/nonce/anonymous guards, audit, redacted CSV + 3/hour cap and finance manager admin\n";
} finally {
	wp_set_current_user( $admin->ID );
	$_GET = $prior_get;
	if ( $batch ) {
		if ( function_exists( 'as_unschedule_all_actions' ) )
			as_unschedule_all_actions( ImportJob::HOOK, [ $batch ], 'wpessential-wbm-import' );
		$stored = $wpdb->get_var( $wpdb->prepare(
			"SELECT source_path FROM {$wpdb->prefix}wbm_pro_import_jobs WHERE batch_id=%d",
			$batch
		) );
		if ( is_string( $stored ) && is_file( $stored ) ) unlink( $stored );
		$wpdb->delete( $wpdb->prefix . 'wbm_pro_import_rows', [ 'batch_id' => $batch ], [ '%d' ] );
		$wpdb->delete( $wpdb->prefix . 'wbm_pro_import_jobs', [ 'batch_id' => $batch ], [ '%d' ] );
		$wpdb->delete( $wpdb->prefix . 'wbm_pro_import_batches', [ 'id' => $batch ], [ '%d' ] );
	}
	foreach ( $jobs as $id ) {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( AsyncReportExportJob::HOOK, [ $id ],
				'wpessential-wbm-report-export' );
			as_unschedule_all_actions( AsyncReportExportJob::EXPIRY_HOOK, [ $id ],
				'wpessential-wbm-report-export' );
		}
		$wpdb->delete( $wpdb->prefix . 'wbm_pro_report_export_jobs', [ 'id' => $id ], [ '%d' ] );
	}
	$wpdb->delete( $wpdb->prefix . 'wbm_pro_ops_recovery', [ 'actor_id' => $admin->ID ], [ '%d' ] );
	$wpdb->delete( $wpdb->prefix . 'wbm_pro_diagnostics_exports', [ 'actor_id' => $admin->ID ], [ '%d' ] );
	$wpdb->delete( $wpdb->prefix . 'wbm_pro_bank_accounts', [ 'id' => $bank ], [ '%d' ] );
	if ( is_file( $source ) ) unlink( $source );
	wp_set_current_user( $prior_user );
}
