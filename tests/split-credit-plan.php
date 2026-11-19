<?php
use WPEssential\BankTransferMatcherPro\Reconciliation\SplitCreditPlanner;
require dirname( __DIR__ ) . '/plugins/wpessential-bank-transfer-matcher-pro/src/Reconciliation/SplitCreditPlanner.php';

$order = static fn ( int $id, int $owed, int $allocated ): array => [
	'order_id' => $id, 'outstanding_minor' => $owed, 'allocate_minor' => $allocated,
	'currency' => 'USD', 'exponent' => 2,
];
$three = SplitCreditPlanner::preview( 15000, 0, 'USD', 2, [
	$order( 10, 5000, 5000 ), $order( 11, 10000, 6000 ), $order( 12, 4000, 4000 ),
] );
if ( 15000 !== $three['planned_minor'] || 0 !== $three['credit_remaining_minor'] ||
	! $three['fully_allocated'] || ! $three['review_only'] ||
	4000 !== $three['orders'][1]['order_remaining_minor'] ) {
	throw new RuntimeException( 'Three-order full bank credit split preview is inconsistent.' );
}
$partial = SplitCreditPlanner::preview( 15000, 0, 'USD', 2, [ $order( 10, 5000, 4000 ) ] );
if ( 11000 !== $partial['credit_remaining_minor'] || $partial['fully_allocated'] ||
	1000 !== $partial['orders'][0]['order_remaining_minor'] ) {
	throw new RuntimeException( 'Unallocated remainder or partially funded order was lost.' );
}
$continued = SplitCreditPlanner::preview( 15000, 5000, 'USD', 2, [
	$order( 11, 10000, 6000 ), $order( 12, 4000, 4000 ),
] );
if ( 0 !== $continued['credit_remaining_minor'] || ! $continued['fully_allocated'] ) {
	throw new RuntimeException( 'Previously allocated source balance was double-counted.' );
}
$invalid = [
	'duplicate order' => [ $order( 10, 10000, 1000 ), $order( 10, 10000, 1000 ) ],
	'overdrawn credit' => [ $order( 10, 10000, 9000 ), $order( 11, 10000, 7000 ) ],
	'overdrawn order' => [ $order( 10, 100, 101 ) ],
	'zero allocation' => [ $order( 10, 100, 0 ) ],
	'float amount' => [ array_replace( $order( 10, 100, 100 ), [ 'allocate_minor' => 100.0 ] ) ],
	'wrong currency' => [ array_replace( $order( 10, 100, 100 ), [ 'currency' => 'EUR' ] ) ],
	'wrong precision' => [ array_replace( $order( 10, 100, 100 ), [ 'exponent' => 0 ] ) ],
	'amount overflow' => [ $order( 10, PHP_INT_MAX, PHP_INT_MAX ) ],
];
foreach ( $invalid as $label => $rows ) {
	try {
		SplitCreditPlanner::preview( 15000, 0, 'USD', 2, $rows );
		throw new RuntimeException( "$label was accepted." );
	} catch ( InvalidArgumentException $expected ) {}
}
$boundary = SplitCreditPlanner::preview( PHP_INT_MAX, PHP_INT_MAX - 1, 'USD', 2, [
	$order( 10, 1, 1 ),
] );
if ( ! $boundary['fully_allocated'] || 0 !== $boundary['credit_remaining_minor'] ) {
	throw new RuntimeException( 'Exact maximum integer boundary failed.' );
}
echo "PASS source-to-three-orders split preview, remainders, duplicate/currency/precision and overflow guards\n";
