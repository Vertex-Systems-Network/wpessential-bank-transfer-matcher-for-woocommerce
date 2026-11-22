<?php

use WPEssential\BankTransferMatcherPro\Import\BankAccountRepository;
use WPEssential\BankTransferMatcherPro\Import\ImportJob;
use WPEssential\BankTransferMatcherPro\Import\MappingProfile;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'wbm_ci' !== DB_NAME ) throw new RuntimeException( 'Run against disposable wbm_ci only.' );
global $wpdb;
$actor = get_user_by( 'login', 'wbm_ci' ); wp_set_current_user( $actor->ID );
$account = ( new BankAccountRepository( $wpdb ) )->create( 'Job fixture', 'Fixture Bank', 'USD', '1234', $actor->ID );
$profile = MappingProfile::from_array( [ 'id' => 'job-fixture', 'name' => 'Job fixture', 'delimiter' => ',', 'date_header' => 'Date', 'date_format' => 'Y-m-d', 'amount_header' => 'Amount', 'decimal_separator' => '.', 'thousands_separator' => '', 'exponent' => 2, 'currency_header' => '', 'fixed_currency' => 'USD', 'reference_header' => 'Reference', 'description_header' => '', 'transaction_id_header' => 'Bank ID' ] );
$source = tempnam( sys_get_temp_dir(), 'wbm-job-' );
$row_total = 1005;
$handle = fopen( $source, 'wb' ); fwrite( $handle, "Date,Amount,Reference,Bank ID\n" );
for ( $i = 1; $i <= $row_total; ++$i ) fputcsv( $handle, [ '2026-11-18', '1.00', 'REF-' . $i, sprintf( '%06d', $i ) ], ',', '"', '' );
fclose( $handle );
$job = new ImportJob( $wpdb ); $batch = 0; $path = ''; $third = 0; $second_source = ''; $third_source = '';
try {
	$batch = $job->start( $source, $account, $profile, $actor->ID );
	$jobs = $wpdb->prefix . 'wbm_pro_import_jobs'; $rows = $wpdb->prefix . 'wbm_pro_import_rows'; $batches = $wpdb->prefix . 'wbm_pro_import_batches';
	$path = $wpdb->get_var( $wpdb->prepare( "SELECT source_path FROM `$jobs` WHERE batch_id = %d", $batch ) );
	if ( ! is_file( $path ) || str_starts_with( realpath( $path ), realpath( ABSPATH ) ) ) throw new RuntimeException( 'Statement was not staged privately.' );
	$job->process( $batch );
	if ( 100 !== (int) $wpdb->get_var( $wpdb->prepare( "SELECT valid_count FROM `$jobs` WHERE batch_id = %d", $batch ) ) ) throw new RuntimeException( 'First chunk did not checkpoint.' );
	for ( $i = 0; $i < 20 && 'completed' !== $wpdb->get_var( $wpdb->prepare( "SELECT status FROM `$batches` WHERE id = %d", $batch ) ); ++$i ) $job->process( $batch );
	$state = $wpdb->get_row( $wpdb->prepare( "SELECT status, valid_count FROM `$batches` WHERE id = %d", $batch ), ARRAY_A );
	if ( 'completed' !== $state['status'] || $row_total !== (int) $state['valid_count'] ) throw new RuntimeException( 'Batch did not finish three chunks.' );
	$job->process( $batch );
	if ( $row_total !== (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `$rows` WHERE batch_id = %d", $batch ) ) ) throw new RuntimeException( 'Replay duplicated staged rows.' );
	$first = $wpdb->get_var( $wpdb->prepare( "SELECT canonical_json FROM `$rows` WHERE batch_id = %d AND source_row = 2", $batch ) );
	if ( '000001' !== ( json_decode( $first, true )['transaction_id'] ?? '' ) ) throw new RuntimeException( 'Bank transaction ID lost leading zeroes.' );
	for ( $attempt = 0; $attempt < 5; ++$attempt ) {
		try {
			$job->start( $source, $account, $profile, $actor->ID );
		} catch ( InvalidArgumentException $expected ) {
			if ( 'This exact statement file was already imported for the selected account.' !== $expected->getMessage() ) {
				throw $expected;
			}
			continue;
		}
		throw new RuntimeException( 'Exact file reimport was accepted.' );
	}
	// A changed private source must fail closed before any additional row is written.
	$second_source = tempnam( sys_get_temp_dir(), 'wbm-job-overlap-' );
	copy( $source, $second_source ); file_put_contents( $second_source, "\n", FILE_APPEND );
	$second = $job->start( $second_source, $account, $profile, $actor->ID );
	$second_path = $wpdb->get_var( $wpdb->prepare( "SELECT source_path FROM `$jobs` WHERE batch_id = %d", $second ) );
	file_put_contents( $second_path, 'tampered' );
	try { $job->process( $second ); throw new RuntimeException( 'Changed source was accepted.' ); }
	catch ( RuntimeException $error ) { if ( 'Changed source was accepted.' === $error->getMessage() ) throw $error; }
	if ( 'failed' !== $wpdb->get_var( $wpdb->prepare( "SELECT status FROM `$batches` WHERE id = %d", $second ) ) ) throw new RuntimeException( 'Failed job was not recorded.' );
	copy( $second_source, $second_path );
	$job->retry( $second );
	for ( $i = 0; $i < 20 && 'completed' !== $wpdb->get_var( $wpdb->prepare( "SELECT status FROM `$batches` WHERE id = %d", $second ) ); ++$i ) $job->process( $second );
	$second_state = $wpdb->get_row( $wpdb->prepare( "SELECT status, warning_count FROM `$batches` WHERE id = %d", $second ), ARRAY_A );
	if ( 'completed' !== $second_state['status'] || $row_total !== (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `$rows` WHERE batch_id = %d", $second ) ) ) throw new RuntimeException( 'Explicit retry did not resume safely.' );
	if ( $row_total !== (int) $second_state['warning_count'] || $row_total !== (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `$rows` WHERE batch_id = %d AND warning_code = 'possible_duplicate'", $second ) ) ) throw new RuntimeException( 'Overlapping-file duplicate warnings were not persisted.' );
	$third_source = tempnam( sys_get_temp_dir(), 'wbm-job-cancel-' );
	copy( $source, $third_source ); file_put_contents( $third_source, "\n\n", FILE_APPEND );
	$third = $job->start( $third_source, $account, $profile, $actor->ID );
	$third_path = $wpdb->get_var( $wpdb->prepare( "SELECT source_path FROM `$jobs` WHERE batch_id = %d", $third ) );
	$job->cancel( $third );
	if ( 'cancelled' !== $wpdb->get_var( $wpdb->prepare( "SELECT status FROM `$batches` WHERE id = %d", $third ) ) || is_file( $third_path ) ) throw new RuntimeException( 'Cancelled import retained an active source or state.' );
	$job->process( $third );
	if ( 0 !== (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `$rows` WHERE batch_id = %d", $third ) ) ) throw new RuntimeException( 'Cancelled import processed rows.' );
	echo "PASS 100-row transactional chunks over 1,005 rows, progress, replay, source integrity, retry, cancel and private staging\n";
} finally {
	foreach ( [ $batch, $second ?? 0, $third ] as $id ) if ( $id ) {
		$staged = $wpdb->get_var( $wpdb->prepare( "SELECT source_path FROM `{$wpdb->prefix}wbm_pro_import_jobs` WHERE batch_id = %d", $id ) );
		if ( is_string( $staged ) && is_file( $staged ) ) unlink( $staged );
		$wpdb->delete( $wpdb->prefix . 'wbm_pro_import_rows', [ 'batch_id' => $id ], [ '%d' ] );
		$wpdb->delete( $wpdb->prefix . 'wbm_pro_import_jobs', [ 'batch_id' => $id ], [ '%d' ] );
		$wpdb->delete( $wpdb->prefix . 'wbm_pro_import_batches', [ 'id' => $id ], [ '%d' ] );
	}
	$wpdb->delete( $wpdb->prefix . 'wbm_pro_bank_accounts', [ 'id' => $account ], [ '%d' ] );
	unlink( $source ); if ( $second_source && is_file( $second_source ) ) unlink( $second_source ); if ( $third_source && is_file( $third_source ) ) unlink( $third_source );
}
