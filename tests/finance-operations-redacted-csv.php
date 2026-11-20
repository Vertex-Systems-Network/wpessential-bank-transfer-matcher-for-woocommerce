<?php
use WPEssential\BankTransferMatcherPro\Operations\FinanceOperationsSnapshot;

require dirname( __DIR__ ) .
	'/plugins/wpessential-bank-transfer-matcher-pro/src/Operations/FinanceOperationsSnapshot.php';

$item = [
	'job_id' => 999988887777,
	'state' => 'failed', 'age_band' => 'under-1d',
	'processed_rows' => 42, 'error_rows' => 3, 'warning_rows' => 1,
	'created_at_utc' => '2026-11-20 00:00:00',
	// Even if a future provider emits extra private metadata it can NEVER
	// be serialized by the fixed diagnosis CSV projection.
	'source_path' => '/private/finance/file.csv',
	'customer_email' => 'private@example.test',
	'account_number' => '123456789-DO-NOT-EXPORT',
	'raw_bank_reference' => '=HYPERLINK("leak")',
];
$scope = [
	'generated_at_utc' => '2026-11-20 00:00:00',
	'no_bank_clearance_claim' => true,
	'sources' => [
		'import_batches' => [ 'available' => true, 'rows' => [ $item ],
			'has_more' => false, 'page_scoped' => true ],
		'report_exports' => [ 'available' => false, 'rows' => [],
			'has_more' => null, 'page_scoped' => true ],
		'mail_delivery' => [ 'available' => true, 'rows' => [],
			'has_more' => false, 'page_scoped' => true ],
	],
];
$csv = FinanceOperationsSnapshot::to_redacted_csv( $scope );
if ( ! str_contains( $csv, 'source,state,age_band,processed_rows' ) ||
	! str_contains( $csv, 'import_batches,failed,under-1d,42,3,1,yes' ) ||
	! str_contains( $csv, 'report_exports,source_unavailable,' ) ||
	str_contains( $csv, '999988887777' ) ||
	str_contains( $csv, '/private/' ) ||
	str_contains( $csv, 'private@example' ) ||
	str_contains( $csv, '123456789' ) ||
	str_contains( $csv, '=HYPERLINK' ) ||
	str_contains( $csv, 'customer_email' ) ||
	str_contains( $csv, 'recipient_id' ) ) {
	throw new RuntimeException( 'Redacted diagnostics support CSV leaked unapproved metadata.' );
}
$bad = [
	[ 'no_bank_clearance_claim' => false ],
	[ 'sources' => [ 'import_batches' => [], 'report_exports' => [] ] ],
	[ 'sources' => array_merge( $scope['sources'], [ 'credentials' => [] ] ) ],
];
foreach ( $bad as $change ) {
	try {
		FinanceOperationsSnapshot::to_redacted_csv( array_replace( $scope, $change ) );
		throw new RuntimeException( 'Malformed support export accepted.' );
	} catch ( InvalidArgumentException $expected ) {}
}
foreach ( [
	[ 'state' => '=1+1' ], [ 'age_band' => 'over-1000d' ],
	[ 'processed_rows' => -1 ], [ 'warning_rows' => 1e30 ],
] as $change ) {
	$unsafe = $scope;
	$unsafe['sources']['import_batches']['rows'][0] = array_replace( $item, $change );
	try {
		FinanceOperationsSnapshot::to_redacted_csv( $unsafe );
		throw new RuntimeException( 'Unsafe support CSV cell was serialized.' );
	} catch ( InvalidArgumentException $expected ) {}
}
$unbounded = $scope;
$unbounded['sources']['import_batches']['rows'] = array_fill( 0, 21, $item );
try {
	FinanceOperationsSnapshot::to_redacted_csv( $unbounded );
	throw new RuntimeException( '21-row diagnostic source bypassed page limit.' );
} catch ( InvalidArgumentException $expected ) {}
echo "PASS fixed bounded operations diagnostics CSV redaction, malformed-source denial and spreadsheet injection rejection\n";
