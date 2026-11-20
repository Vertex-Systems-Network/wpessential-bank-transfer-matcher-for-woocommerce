<?php
use WPEssential\BankTransferMatcherPro\Import\BankAccountRepository;
use WPEssential\BankTransferMatcherPro\Import\ImportBatchRepository;
use WPEssential\BankTransferMatcherPro\Import\MappingProfile;
use WPEssential\BankTransferMatcherPro\Reconciliation\AllocationLedgerRepository;
use WPEssential\BankTransferMatcherPro\Reporting\ReportDefinition;
use WPEssential\BankTransferMatcherPro\Reporting\ReportQueryRepository;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'wbm_ci' !== DB_NAME ) {
	throw new RuntimeException( 'Use disposable wbm_ci for bank report fixtures.' );
}
global $wpdb;
$admin = get_user_by( 'login', 'wbm_ci' );
wp_set_current_user( $admin->ID );
( new AllocationLedgerRepository( $wpdb ) )->install();
$account = ( new BankAccountRepository( $wpdb ) )->create(
	'Unmatched source reporting bank', 'Synthetic', 'USD', '6611', $admin->ID
);
$profile = MappingProfile::from_array( [
	'id' => 'unmatched-report', 'name' => 'Unmatched USD synthetic report',
	'delimiter' => ',', 'date_header' => 'Date', 'date_format' => 'Y-m-d',
	'amount_header' => 'Amount', 'decimal_separator' => '.', 'thousands_separator' => '',
	'exponent' => 2, 'currency_header' => '', 'fixed_currency' => 'USD',
	'reference_header' => 'Reference', 'description_header' => '',
	'transaction_id_header' => 'Bank ID',
] );
$batches = new ImportBatchRepository( $wpdb );
$batch = $batches->create( $account, $profile,
	hash( 'sha256', 'unmatched-report-' . wp_generate_uuid4() ), 100, $admin->ID );
$batches->transition( $batch, 'pending', 'processing' );
$batches->transition( $batch, 'processing', 'completed', 4 );
$rows = $wpdb->prefix . 'wbm_pro_import_rows';
$ledger = $wpdb->prefix . 'wbm_pro_allocations';
$source_ids = [];
$allocated_id = 0;
try {
	$duplicate = hash( 'sha256', 'duplicate-bank-source-' . $batch );
	foreach ( [
		[ 'clean', 1900, '', hash( 'sha256', 'clean-bank-source-' . $batch ) ],
		[ 'duplicate_a', 2100, '', $duplicate ],
		[ 'duplicate_b', 2100, 'possible_duplicate', $duplicate ],
		[ 'allocated', 3700, '', hash( 'sha256', 'allocated-source-' . $batch ) ],
	] as $i => $item ) {
		[ $tag, $minor, $warning, $hash ] = $item;
		if ( 1 !== $wpdb->insert( $rows, [
			'batch_id' => $batch, 'source_row' => $i + 2,
			'canonical_json' => wp_json_encode( [
				'posted_date' => gmdate( 'Y-m-d' ), 'amount_minor' => $minor,
				'exponent' => 2, 'currency' => 'USD',
				'reference' => 'SYN-' . $tag, 'transaction_id' => 'REPORT-' . $i,
			] ),
			'error_code' => '', 'warning_code' => $warning,
			'identity_fingerprint' => $hash,
		] ) ) throw new RuntimeException( 'Synthetic bank row insert failed.' );
		$source_ids[$tag] = (int) $wpdb->insert_id;
	}
	if ( 1 !== $wpdb->query( $wpdb->prepare(
		"INSERT INTO $ledger (transaction_row_id, source_fingerprint, bank_account_id,
		 order_id, amount_minor, credit_minor, expected_minor, currency, exponent,
		 state, created_by, reason, created_at)
		 VALUES (%d, %s, %d, %d, 3700, 3700, 3700, 'USD', 2, 'approved', %d, %s, UTC_TIMESTAMP())",
		$source_ids['allocated'], hash( 'sha256', 'allocated-source-' . $batch ),
		$account, 990022, $admin->ID, 'Synthetic allocated credit excluded from unmatched sources.'
	) ) ) throw new RuntimeException( 'Synthetic allocated source insert failed.' );
	$allocated_id = (int) $wpdb->insert_id;
	$scope = [
		'report' => 'unmatched_credits', 'from' => gmdate( 'Y-m-d' ),
		'to' => gmdate( 'Y-m-d' ), 'bank_account_id' => $account,
		'currency' => 'USD',
	];
	$reader = new ReportQueryRepository( $wpdb );
	$all = $reader->run( ReportDefinition::from_array( $scope ) );
	$clean = $reader->run( ReportDefinition::from_array( array_replace(
		$scope, [ 'state' => 'unmatched' ]
	) ) );
	$ambiguous = $reader->run( ReportDefinition::from_array( array_replace(
		$scope, [ 'state' => 'ambiguous' ]
	) ) );
	if ( count( $all['rows'] ) !== 3 || count( $clean['rows'] ) !== 1 ||
		$clean['rows'][0]['amount_minor'] !== 1900 ||
		count( $ambiguous['rows'] ) !== 2 ||
		$ambiguous['page_totals']['USD/2/ambiguous']['amount_minor'] !== 4200 ||
		$all['payment_authorized'] || $all['ledger_mutated'] ) {
		throw new RuntimeException( 'Bank report mixed duplicate, allocated, unreviewed or cleared credits.' );
	}
	foreach ( $all['rows'] as $row ) {
		if ( isset( $row['reference'] ) || isset( $row['account_number'] ) ||
			! $row['unconfirmed_clearance'] ) {
			throw new RuntimeException( 'Raw bank reference leaked or unverified credit was called cleared.' );
		}
	}
	$first = $reader->run( ReportDefinition::from_array( array_replace(
		$scope, [ 'limit' => 1 ]
	) ) );
	$second = $reader->run( ReportDefinition::from_array( array_replace(
		$scope, [ 'limit' => 1, 'before_id' => $first['next_before_id'] ]
	) ) );
	if ( count( $first['rows'] ) !== 1 || ! $first['has_more'] ||
		count( $second['rows'] ) !== 1 ||
		$first['rows'][0]['id'] === $second['rows'][0]['id'] ) {
		throw new RuntimeException( 'Imported source report pagination repeated bank rows.' );
	}
	echo "PASS native unmatched/ambiguous imported bank credit, duplicate detection, exact money, redaction and cursor\n";
} finally {
	if ( $allocated_id ) $wpdb->delete( $ledger, [ 'id' => $allocated_id ], [ '%d' ] );
	$wpdb->delete( $rows, [ 'batch_id' => $batch ], [ '%d' ] );
	$wpdb->delete( $wpdb->prefix . 'wbm_pro_import_batches', [ 'id' => $batch ], [ '%d' ] );
	$wpdb->delete( $wpdb->prefix . 'wbm_pro_bank_accounts', [ 'id' => $account ], [ '%d' ] );
}
