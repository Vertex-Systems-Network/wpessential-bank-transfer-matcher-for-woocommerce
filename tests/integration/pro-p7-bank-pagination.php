<?php
/**
 * P7-T005 actual source-staged CSV pagination and stale-page recovery.
 * No real bank inputs, payment statuses or customer claims are touched.
 */
use WPEssential\BankTransferMatcherPro\Admin\BankTransactionsPage;
use WPEssential\BankTransferMatcherPro\Import\BankAccountRepository;
use WPEssential\BankTransferMatcherPro\Import\MappingProfile;
use WPEssential\BankTransferMatcherPro\Import\ImportJob;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'wbm_ci' !== DB_NAME ) {
	throw new RuntimeException( 'Bank pagination requires disposable WordPress wbm_ci.' );
}
global $wpdb;
$admin = get_user_by( 'login', 'wbm_ci' );
if ( ! $admin ) throw new RuntimeException( 'Missing disposable finance operator.' );
$prior_user = get_current_user_id();
$prior_get = $_GET;
wp_set_current_user( $admin->ID );
$account_id = 0;
$batch_ids = [];
$source_files = [];
$job = new ImportJob( $wpdb );
$render = static function ( int $batch_id, int $page ): string {
	$_GET = [
		'page' => 'wpessential-wbm-pro-bank-transactions',
		'batch' => (string) $batch_id, 'per_page' => '20',
		'paged' => (string) $page,
	];
	ob_start();
	try {
		BankTransactionsPage::render();
		return (string) ob_get_contents();
	} finally {
		ob_end_clean();
	}
};
try {
	$account_id = ( new BankAccountRepository( $wpdb ) )->create(
		'P7 synthetic page test USD', 'CI Fixture', 'USD', 'P705', $admin->ID
	);
	$profile = MappingProfile::from_array( [
		'id' => 'p7-pages-synthetic', 'name' => 'Synthetic bank page test',
		'delimiter' => ',', 'date_header' => 'Date', 'date_format' => 'Y-m-d',
		'amount_header' => 'Amount', 'decimal_separator' => '.',
		'thousands_separator' => '', 'exponent' => 2,
		'currency_header' => '', 'fixed_currency' => 'USD',
		'reference_header' => 'Reference', 'description_header' => '',
		'transaction_id_header' => 'Bank ID',
	] );
	foreach ( [ 20, 21 ] as $count ) {
		$path = tempnam( sys_get_temp_dir(), 'wbm-p7-bank-page-' );
		if ( ! is_string( $path ) ) throw new RuntimeException( 'No bank page temp fixture.' );
		$source_files[] = $path;
		$csv = "Date,Amount,Reference,Bank ID\n";
		for ( $i = 1; $i <= $count; ++$i ) {
			$csv .= gmdate( 'Y-m-d' ) . ',1.00,SYNTH-' . $count .
				'-' . $i . ',P7-PAGE-' . $count . '-' . $i . "\n";
		}
		file_put_contents( $path, $csv );
		$batch_id = $job->start( $path, $account_id, $profile, $admin->ID );
		$batch_ids[$count] = $batch_id;
		$job->process( $batch_id );
	}
	$twenty = $render( $batch_ids[20], 1 );
	if ( str_contains( $twenty, '>Next</a>' ) ||
		! str_contains( $twenty, 'Imported statement rows' ) ||
		! str_contains( $twenty, 'role="region"' ) ||
		! str_contains( $twenty, 'SYNTH-' ) === false ) {
		throw new RuntimeException( 'Exact 20-row imported bank page had phantom Next or inaccessible source table.' );
	}
	$first = $render( $batch_ids[21], 1 );
	$second = $render( $batch_ids[21], 2 );
	$stale = $render( $batch_ids[21], 3 );
	if ( ! str_contains( $first, '>Next</a>' ) ||
		! str_contains( $second, '>Previous</a>' ) ||
		str_contains( $second, '>Next</a>' ) ||
		! str_contains( $stale, 'Previous source page' ) ||
		str_contains( $stale, '>Next</a>' ) ||
		! str_contains( $stale, 'no staged rows on the selected page' ) ) {
		throw new RuntimeException( 'Imported bank pagination lost exact 20/21 page boundary or stale-page recovery.' );
	}
	echo "PASS real staged bank 20-row no phantom Next, 21-row second page, stale-page Previous and masked-source table controls\n";
} finally {
	$_GET = $prior_get;
	wp_set_current_user( $admin->ID );
	foreach ( $batch_ids as $batch_id ) {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( ImportJob::HOOK, [ $batch_id ], 'wpessential-wbm-import' );
		}
		$staged = $wpdb->get_var( $wpdb->prepare(
			"SELECT source_path FROM {$wpdb->prefix}wbm_pro_import_jobs WHERE batch_id=%d",
			$batch_id
		) );
		if ( is_string( $staged ) && is_file( $staged ) ) unlink( $staged );
		$wpdb->delete( $wpdb->prefix . 'wbm_pro_import_rows', [ 'batch_id' => $batch_id ], [ '%d' ] );
		$wpdb->delete( $wpdb->prefix . 'wbm_pro_import_jobs', [ 'batch_id' => $batch_id ], [ '%d' ] );
		$wpdb->delete( $wpdb->prefix . 'wbm_pro_import_batches', [ 'id' => $batch_id ], [ '%d' ] );
	}
	if ( $account_id ) {
		$wpdb->delete( $wpdb->prefix . 'wbm_pro_bank_accounts', [ 'id' => $account_id ], [ '%d' ] );
	}
	foreach ( $source_files as $source ) if ( is_file( $source ) ) unlink( $source );
	wp_set_current_user( $prior_user );
}
