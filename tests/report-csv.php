<?php
use WPEssential\BankTransferMatcherPro\Reporting\ReportDefinition;
use WPEssential\BankTransferMatcherPro\Reporting\ReportCsvEncoder;

require dirname( __DIR__ ) . '/plugins/wpessential-bank-transfer-matcher-pro/src/Reporting/ReportDefinition.php';
require dirname( __DIR__ ) . '/plugins/wpessential-bank-transfer-matcher-pro/src/Reporting/ReportCsvEncoder.php';

$examples = [
	'allocation_audit' => [
		'id' => 9, 'order_id' => 31, 'source_id' => 51,
		'bank_account_id' => 2, 'currency' => 'USD', 'exponent' => 2,
		'state' => 'approved', 'amount_minor' => 5000,
		'created_at_utc' => "\t=HYPERLINK(\"https://invalid.example.test\", \"x\")",
	],
	'import_batches' => [
		'id' => 7, 'bank_account_id' => 2, 'currency' => 'USD',
		'state' => 'completed', 'valid_count' => 5,
		'error_count' => 1, 'warning_count' => 2,
		'created_at_utc' => '2026-11-20 00:00:00',
	],
	'outstanding_orders' => [
		'order_id' => 32, 'bank_account_id' => 2, 'currency' => 'USD',
		'exponent' => 2, 'state' => 'on-hold', 'expected_minor' => 10000,
		'funded_minor' => 4000, 'amount_minor' => 6000,
		'created_at_utc' => '2026-11-20 00:00:00',
		'receiving_account_confirmed' => true, 'woocommerce_paid' => false,
	],
	'partial_funding' => [
		'order_id' => 32, 'bank_account_id' => 2, 'currency' => 'USD',
		'exponent' => 2, 'state' => 'on-hold', 'expected_minor' => 10000,
		'funded_minor' => 4000, 'amount_minor' => 6000,
		'created_at_utc' => '2026-11-20 00:00:00',
		'receiving_account_confirmed' => true, 'woocommerce_paid' => false,
	],
	'unmatched_credits' => [
		'source_id' => 51, 'bank_account_id' => 2, 'currency' => 'USD',
		'exponent' => 2, 'state' => 'ambiguous', 'amount_minor' => 1000,
		'posted_date' => '2026-11-20', 'ambiguous_bank_source' => true,
		'pending_review_not_paid' => false, 'unconfirmed_clearance' => true,
	],
	'exception_aging' => [
		'case_id' => 14, 'case_type' => 'correction',
		'order_id' => 32, 'bank_account_id' => 2,
		'currency' => 'USD', 'exponent' => 2, 'state' => 'open',
		'age_days' => 4, 'age_band' => '3-7d',
		'disputed_minor_non_additive' => 5000, 'financial_hold' => true,
	],
];

$reject = static function ( callable $attempt, string $reason ): void {
	try {
		$attempt();
		throw new RuntimeException( 'Unsafe CSV export accepted: ' . $reason );
	} catch ( InvalidArgumentException $expected ) {}
};
foreach ( $examples as $report => $fixture ) {
	$definition = ReportDefinition::from_array( [
		'report' => $report, 'from' => '2026-11-20',
		'to' => '2026-11-20', 'limit' => 1,
	] );
	$fixture['reference'] = 'PRIVATE BANK REFERENCE NEVER EXPORT';
	$fixture['account_number'] = '123456789012';
	$fixture['customer_email'] = 'private@example.test';
	$result = [
		'definition' => $definition->canonical(),
		'fingerprint' => $definition->fingerprint(),
		'rows' => [ $fixture ], 'payment_authorized' => false,
		'ledger_mutated' => false, 'has_more' => true,
	];
	$csv = ReportCsvEncoder::encode( $definition, $result );
	if ( str_contains( $csv, 'PRIVATE BANK REFERENCE' ) ||
		str_contains( $csv, '123456789012' ) ||
		str_contains( $csv, 'private@example.test' ) ||
		str_contains( $csv, 'reference' ) ||
		str_contains( $csv, 'customer_email' ) ) {
		throw new RuntimeException( 'Sensitive or unapproved finance fields were serialized.' );
	}
	$handle = fopen( 'php://temp', 'w+' );
	fwrite( $handle, $csv );
	rewind( $handle );
	$columns = fgetcsv( $handle, 0, ',', '"', '' );
	$values = fgetcsv( $handle, 0, ',', '"', '' );
	$end = fgetcsv( $handle, 0, ',', '"', '' );
	fclose( $handle );
	if ( ! is_array( $columns ) || ! is_array( $values ) ||
		count( $columns ) !== count( $values ) || false !== $end ) {
		throw new RuntimeException( 'CSV does not decode to exact authorized rows.' );
	}
	if ( 'allocation_audit' === $report &&
		! str_starts_with( (string) $values[ array_search( 'created_at_utc', $columns, true ) ], "'\t=" ) ) {
		throw new RuntimeException( 'Spreadsheet formula with leading control whitespace not neutralized.' );
	}
	$reject( static fn () => ReportCsvEncoder::encode( $definition, array_replace(
		$result, [ 'payment_authorized' => true ]
	) ), 'payment-authorized page' );
	$reject( static fn () => ReportCsvEncoder::encode( $definition, array_replace(
		$result, [ 'fingerprint' => str_repeat( '0', 64 ) ]
	) ), 'wrong scope fingerprint' );
	$reject( static fn () => ReportCsvEncoder::encode( $definition, array_replace(
		$result, [ 'rows' => [ $fixture, $fixture ] ]
	) ), 'out-of-scope extra row' );
	$bad = $fixture;
	unset( $bad[$columns[0]] );
	$reject( static fn () => ReportCsvEncoder::encode( $definition, array_replace(
		$result, [ 'rows' => [ $bad ] ]
	) ), 'missing allowlisted field' );
}
echo "PASS six allowlisted bounded finance CSV reports, redaction, formula guard, canonical scope and fail-closed checks\n";
