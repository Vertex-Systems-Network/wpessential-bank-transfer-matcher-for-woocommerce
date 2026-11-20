<?php
use WPEssential\BankTransferMatcherPro\Import\BankAccountRepository;
use WPEssential\BankTransferMatcherPro\Import\ImportBatchRepository;
use WPEssential\BankTransferMatcherPro\Import\MappingProfile;
use WPEssential\BankTransferMatcherPro\Reconciliation\AllocationLedgerRepository;
use WPEssential\BankTransferMatcherPro\Reporting\ReportDefinition;
use WPEssential\BankTransferMatcherPro\Reporting\ReportQueryRepository;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'wbm_ci' !== DB_NAME ) {
	throw new RuntimeException( 'Disposable wbm_ci required for native report query tests.' );
}
global $wpdb;
$admin = get_user_by( 'login', 'wbm_ci' );
wp_set_current_user( $admin->ID );
( new AllocationLedgerRepository( $wpdb ) )->install();
$banks = new BankAccountRepository( $wpdb );
$usd = $banks->create( 'Report USD fixture', 'Synthetic', 'USD', '1212', $admin->ID );
$eur = $banks->create( 'Report EUR fixture', 'Synthetic', 'EUR', '3434', $admin->ID );
$profile = static function ( string $currency ): MappingProfile {
	return MappingProfile::from_array( [
		'id' => 'report-' . strtolower( $currency ), 'name' => 'Reporting fixture ' . $currency,
		'delimiter' => ',', 'date_header' => 'Date', 'date_format' => 'Y-m-d',
		'amount_header' => 'Amount', 'decimal_separator' => '.',
		'thousands_separator' => '', 'exponent' => 2, 'currency_header' => '',
		'fixed_currency' => $currency, 'reference_header' => 'Reference',
		'description_header' => '', 'transaction_id_header' => 'Bank ID',
	] );
};
$batches = new ImportBatchRepository( $wpdb );
$batch_ids = [];
$ids = [];
$table = $wpdb->prefix . 'wbm_pro_allocations';
$today = gmdate( 'Y-m-d' );
$repository = new ReportQueryRepository( $wpdb );
try {
	$batch_ids[] = $batches->create( $usd, $profile( 'USD' ),
		hash( 'sha256', 'report-batch-usd-a-' . wp_generate_uuid4() ), 100, $admin->ID );
	$batch_ids[] = $batches->create( $usd, $profile( 'USD' ),
		hash( 'sha256', 'report-batch-usd-b-' . wp_generate_uuid4() ), 100, $admin->ID );
	$batches->transition( $batch_ids[0], 'pending', 'processing' );
	$batches->transition( $batch_ids[0], 'processing', 'completed', 2, 1, 1 );
	$batch_ids[] = $batches->create( $eur, $profile( 'EUR' ),
		hash( 'sha256', 'report-batch-eur-' . wp_generate_uuid4() ), 100, $admin->ID );
	foreach ( [
		[ $usd, 'USD', 'approved', 1234 ],
		[ $usd, 'USD', 'proposed', 5000 ],
		[ $eur, 'EUR', 'approved', 200 ],
	] as $idx => $entry ) {
		[ $account, $currency, $state, $amount ] = $entry;
		$inserted = $wpdb->query( $wpdb->prepare(
			"INSERT INTO $table (transaction_row_id, source_fingerprint, bank_account_id,
			 order_id, amount_minor, credit_minor, expected_minor, currency, exponent,
			 state, created_by, reason, created_at)
			 VALUES (%d, %s, %d, %d, %d, %d, %d, %s, 2, %s, %d, %s, UTC_TIMESTAMP())",
			990000 + $idx, hash( 'sha256', 'report-source-' . $idx ),
			$account, 990000 + $idx, $amount, $amount, $amount,
			$currency, $state, $admin->ID, 'Synthetic read-only query fixture, not allocated bank funds'
		) );
		if ( 1 !== $inserted ) throw new RuntimeException( 'Could not stage synthetic report ledger row.' );
		$ids[] = (int) $wpdb->insert_id;
	}
	$base = [ 'report' => 'allocation_audit', 'from' => $today, 'to' => $today ];
	$usd_filter = ReportDefinition::from_array( array_replace( $base, [
		'bank_account_id' => $usd, 'currency' => 'USD', 'limit' => 1,
	] ) );
	$first = $repository->run( $usd_filter );
	$second = $repository->run( ReportDefinition::from_array(
		array_replace( $base, [ 'bank_account_id' => $usd,
			'currency' => 'USD', 'limit' => 1,
			'before_id' => $first['next_before_id'] ] )
	) );
	if ( count( $first['rows'] ) !== 1 || ! $first['has_more'] ||
		$first['rows'][0]['id'] === $second['rows'][0]['id'] ||
		count( $second['rows'] ) !== 1 || $second['has_more'] ||
		$first['payment_authorized'] || $first['ledger_mutated'] ) {
		throw new RuntimeException( 'Bounded audit pagination duplicated a row or authorized money.' );
	}
	$approved = $repository->run( ReportDefinition::from_array( array_replace( $base, [
		'bank_account_id' => $usd, 'currency' => 'USD', 'state' => 'approved',
	] ) ) );
	if ( count( $approved['rows'] ) !== 1 ||
		$approved['page_totals']['USD/2/approved']['amount_minor'] !== 1234 ) {
		throw new RuntimeException( 'Approved source was mixed with proposed or other currency funds.' );
	}
	$eur_report = $repository->run( ReportDefinition::from_array( array_replace( $base, [
		'bank_account_id' => $eur, 'currency' => 'EUR', 'state' => 'approved',
	] ) ) );
	if ( count( $eur_report['rows'] ) !== 1 ||
		$eur_report['page_totals']['EUR/2/approved']['amount_minor'] !== 200 ) {
		throw new RuntimeException( 'EUR or scoped receiving bank currency was omitted.' );
	}
	$batch = $repository->run( ReportDefinition::from_array( [
		'report' => 'import_batches', 'from' => $today, 'to' => $today,
		'bank_account_id' => $usd, 'currency' => 'USD',
	] ) );
	if ( count( $batch['rows'] ) !== 2 ||
		$batch['page_totals']['USD/0/completed']['amount_minor'] !== 0 ||
		$batch['page_totals']['USD/0/completed']['count'] !== 1 ) {
		throw new RuntimeException( 'Import counts were treated as monetary report totals.' );
	}
	$first_batch = array_values( array_filter( $batch['rows'],
		static fn ( array $row ): bool => $row['state'] === 'completed'
	) );
	if ( count( $first_batch ) !== 1 || $first_batch[0]['valid_count'] !== 2 ||
		$first_batch[0]['warning_count'] !== 1 || $first_batch[0]['error_count'] !== 1 ) {
		throw new RuntimeException( 'Import progress totals did not match the completed source batch.' );
	}
	wp_set_current_user( 0 );
	try {
		$repository->run( $usd_filter );
		throw new RuntimeException( 'Anonymous finance report was exposed.' );
	} catch ( RuntimeException $expected ) {
		if ( 'Anonymous finance report was exposed.' === $expected->getMessage() ) throw $expected;
	}
	echo "PASS scoped read-only native allocation and import reports, exact page totals, cursor and finance authorization\n";
} finally {
	wp_set_current_user( $admin->ID );
	foreach ( $ids as $id ) $wpdb->delete( $table, [ 'id' => $id ], [ '%d' ] );
	foreach ( $batch_ids as $id ) {
		$wpdb->delete( $wpdb->prefix . 'wbm_pro_import_batches', [ 'id' => $id ], [ '%d' ] );
	}
	foreach ( [ $usd, $eur ] as $id ) {
		$wpdb->delete( $wpdb->prefix . 'wbm_pro_bank_accounts', [ 'id' => $id ], [ '%d' ] );
	}
}
