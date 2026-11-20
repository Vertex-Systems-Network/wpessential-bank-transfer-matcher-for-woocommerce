<?php
use WPEssential\BankTransferMatcherPro\Reporting\ReportDefinition;
use WPEssential\BankTransferMatcherPro\Reporting\PerCurrencyTotals;
require dirname( __DIR__ ) . '/plugins/wpessential-bank-transfer-matcher-pro/src/Reporting/ReportDefinition.php';
require dirname( __DIR__ ) . '/plugins/wpessential-bank-transfer-matcher-pro/src/Reporting/PerCurrencyTotals.php';

$base = [ 'report' => 'allocation_audit', 'from' => '2024-02-29', 'to' => '2025-02-28' ];
$one = ReportDefinition::from_array( $base );
$two = ReportDefinition::from_array( [
	'limit' => '100', 'state' => 'all', 'currency' => 'ALL',
	'to' => '2025-02-28', 'report' => 'allocation_audit',
	'before_id' => '0', 'bank_account_id' => '0', 'from' => '2024-02-29',
] );
if ( $one->canonical() !== $two->canonical() ||
	$one->fingerprint() !== $two->fingerprint() ||
	strlen( $one->fingerprint() ) !== 64 ||
	$one->is_bank_posting_date() ) {
	throw new RuntimeException( 'Identical reporting filters were not canonical or deterministic.' );
}
$bank = ReportDefinition::from_array( [
	'report' => 'unmatched_credits', 'from' => '2026-01-01', 'to' => '2026-01-31',
	'bank_account_id' => '77', 'currency' => 'USD', 'state' => 'ambiguous',
	'before_id' => '9000', 'limit' => '50',
] );
if ( ! $bank->is_bank_posting_date() ||
	$bank->canonical()['bank_account_id'] !== 77 ||
	$bank->canonical()['before_id'] !== 9000 ||
	$one->fingerprint() === $bank->fingerprint() ) {
	throw new RuntimeException( 'Currency/source reporting scope was lost.' );
}
$reject = static function ( callable $fn, string $name ): void {
	try {
		$fn();
		throw new RuntimeException( 'Unsafe reporting filter accepted: ' . $name );
	} catch ( InvalidArgumentException $expected ) {}
};
foreach ( [
	'SQL-like report type' => [ 'report' => 'allocation_audit; DROP TABLE orders' ],
	'unknown filter key' => [ 'unexpected' => 'anything' ],
	'impossible leap date' => [ 'from' => '2025-02-29' ],
	'inverted bounds' => [ 'from' => '2025-02-28', 'to' => '2024-02-29' ],
	'oversized interval' => [ 'to' => '2027-02-28' ],
	'invalid currency case' => [ 'currency' => 'usd' ],
	'negative account' => [ 'bank_account_id' => '-1' ],
	'unsafe float account' => [ 'bank_account_id' => 1.1 ],
	'overflow ID' => [ 'before_id' => '99999999999999999999999999999999' ],
	'limit too high' => [ 'limit' => '501' ],
	'incompatible state' => [ 'state' => 'processing' ],
	'non-calendar timestamp' => [ 'from' => '2024-02-29T00:00:00Z' ],
] as $name => $changes ) {
	$reject( static fn () => ReportDefinition::from_array(
		array_replace( $base, $changes )
	), $name );
}
$rows = [
	[ 'currency' => 'USD', 'exponent' => 2, 'state' => 'approved', 'amount_minor' => 1000 ],
	[ 'currency' => 'USD', 'exponent' => '2', 'state' => 'approved', 'amount_minor' => '2500' ],
	[ 'currency' => 'USD', 'exponent' => 2, 'state' => 'proposed', 'amount_minor' => 1000 ],
	[ 'currency' => 'EUR', 'exponent' => 2, 'state' => 'approved', 'amount_minor' => 1100 ],
	[ 'currency' => 'USD', 'exponent' => 3, 'state' => 'approved', 'amount_minor' => 1234 ],
	[ 'currency' => 'JPY', 'exponent' => 0, 'state' => 'committed', 'amount_minor' => '999' ],
];
$totals = PerCurrencyTotals::summarize( $rows );
if ( count( $totals ) !== 5 ||
	$totals['USD/2/approved']['amount_minor'] !== 3500 ||
	$totals['USD/2/approved']['count'] !== 2 ||
	$totals['USD/2/proposed']['amount_minor'] !== 1000 ||
	$totals['USD/3/approved']['amount_minor'] !== 1234 ||
	$totals['EUR/2/approved']['amount_minor'] !== 1100 ||
	$totals['JPY/0/committed']['amount_minor'] !== 999 ||
	array_keys( $totals ) !== array_keys( PerCurrencyTotals::summarize( array_reverse( $rows ) ) ) ) {
	throw new RuntimeException( 'Report totals mixed currencies, precision or ledger states.' );
}
foreach ( [
	[ [ 'currency' => 'USD', 'exponent' => 2, 'state' => 'approved', 'amount_minor' => 0.01 ] ],
	[ [ 'currency' => 'USD', 'exponent' => 2, 'state' => 'approved', 'amount_minor' => '1e4' ] ],
	[ [ 'currency' => 'usd', 'exponent' => 2, 'state' => 'approved', 'amount_minor' => 10 ] ],
	[ [ 'currency' => 'USD', 'exponent' => 9, 'state' => 'approved', 'amount_minor' => 10 ] ],
	[ [ 'currency' => 'USD', 'exponent' => 2, 'state' => 'approved', 'amount_minor' => -1 ] ],
	[
		[ 'currency' => 'USD', 'exponent' => 2, 'state' => 'approved', 'amount_minor' => PHP_INT_MAX ],
		[ 'currency' => 'USD', 'exponent' => 2, 'state' => 'approved', 'amount_minor' => 1 ],
	],
] as $invalid ) {
	$reject( static fn () => PerCurrencyTotals::summarize( $invalid ), 'incorrect money or overflow' );
}
if ( [] !== PerCurrencyTotals::summarize( [] ) ) throw new RuntimeException( 'Empty report needs empty totals.' );
echo "PASS canonical bounded date/currency/bank report filters, stable fingerprints, strict minor-unit status totals and overflow rejection\n";
