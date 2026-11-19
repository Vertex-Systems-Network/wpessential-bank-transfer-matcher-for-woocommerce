<?php
use WPEssential\BankTransferMatcherPro\Reconciliation\PartialCreditPlanner;
require dirname( __DIR__ ) . '/plugins/wpessential-bank-transfer-matcher-pro/src/Reconciliation/PartialCreditPlanner.php';

$credit = static fn ( int $id, int $available, int $allocate ): array => [
	'source_id' => $id, 'available_minor' => $available, 'allocate_minor' => $allocate,
	'currency' => 'USD', 'exponent' => 2,
];
$partial = PartialCreditPlanner::preview( 10000, 0, 'USD', 2, [
	$credit( 1, 2500, 2500 ), $credit( 2, 3500, 3500 ),
] );
if ( 6000 !== $partial['planned_minor'] || 4000 !== $partial['order_outstanding_minor'] ||
	$partial['fully_covered'] || ! $partial['review_only'] ) {
	throw new RuntimeException( 'Two distinct partial credits were not previewed correctly.' );
}
$full = PartialCreditPlanner::preview( 10000, 6000, 'USD', 2, [ $credit( 3, 4500, 4000 ) ] );
if ( ! $full['fully_covered'] || 0 !== $full['order_outstanding_minor'] ||
	500 !== $full['credits'][0]['source_remaining_minor'] ) {
	throw new RuntimeException( 'Three-credit full coverage or source remainder failed.' );
}
$bad = [
	'duplicate source' => [ $credit( 1, 2500, 1000 ), $credit( 1, 2500, 1000 ) ],
	'overdue order' => [ $credit( 1, 12000, 10001 ) ],
	'overdrawn bank source' => [ $credit( 1, 100, 101 ) ],
	'zero allocation' => [ $credit( 1, 100, 0 ) ],
	'float' => [ array_replace( $credit( 1, 1000, 1000 ), [ 'allocate_minor' => 1000.0 ] ) ],
	'wrong currency' => [ array_replace( $credit( 1, 1000, 1000 ), [ 'currency' => 'EUR' ] ) ],
	'wrong precision' => [ array_replace( $credit( 1, 1000, 1000 ), [ 'exponent' => 0 ] ) ],
	'overflow' => [ $credit( 1, PHP_INT_MAX, PHP_INT_MAX ) ],
];
foreach ( $bad as $label => $rows ) {
	try {
		PartialCreditPlanner::preview( 10000, 0, 'USD', 2, $rows );
		throw new RuntimeException( "$label was accepted." );
	} catch ( InvalidArgumentException $expected ) {}
}
$huge = PartialCreditPlanner::preview( PHP_INT_MAX, PHP_INT_MAX - 1, 'USD', 2, [ $credit( 9, 1, 1 ) ] );
if ( ! $huge['fully_covered'] ) throw new RuntimeException( 'Integer boundary was not handled exactly.' );
echo "PASS partial multi-credit preview, exact balances, duplicate/currency/precision and overflow guards\n";
