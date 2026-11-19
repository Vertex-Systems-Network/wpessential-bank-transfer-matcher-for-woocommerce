<?php
use WPEssential\BankTransferMatcherPro\Reconciliation\LedgerBalance;
require dirname( __DIR__ ) . '/plugins/wpessential-bank-transfer-matcher-pro/src/Reconciliation/LedgerBalance.php';
$row = static fn ( int $order, int $source, int $amount, string $state = 'approved' ): array => [
	'order_id' => $order, 'transaction_row_id' => $source, 'bank_account_id' => 3,
	'amount_minor' => $amount, 'credit_minor' => 12000, 'expected_minor' => 10000,
	'currency' => 'USD', 'exponent' => 2, 'state' => $state,
];
$rows = [ $row( 10, 20, 3000 ), $row( 11, 20, 2000 ), $row( 10, 21, 1000 ),
	$row( 10, 20, 6000, 'proposed' ), $row( 10, 20, 5000, 'reversed' ) ];
$balance = LedgerBalance::summarize( 10, 20, 10000, 12000, 'USD', 2, 3, $rows );
if ( 4000 !== $balance['order_allocated_minor'] || 6000 !== $balance['order_outstanding_minor'] ||
	5000 !== $balance['credit_allocated_minor'] || 7000 !== $balance['credit_unallocated_minor'] ) {
	throw new RuntimeException( 'Partial, split, proposed or reversed balance regression.' );
}
$manual = [ [ 'amount_minor' => '2500', 'expected_minor' => '10000', 'currency' => 'USD', 'exponent' => '2' ] ];
$combined = LedgerBalance::summarize( 10, 20, 10000, 12000, 'USD', 2, 3, [ $row( 10, 20, 7000 ) ], $manual );
if ( 2500 !== $combined['manual_allocated_minor'] || 500 !== $combined['order_outstanding_minor'] ) {
	throw new RuntimeException( 'Existing Free allocations were omitted.' );
}
$invalid = [
	'overdrawn order' => [ [ $row( 10, 20, 9000 ) ], $manual ],
	'overdrawn source' => [ [ $row( 10, 20, 8000 ), $row( 11, 20, 5000 ) ], [] ],
	'changed total' => [ [ array_replace( $row( 10, 20, 1000 ), [ 'expected_minor' => 10500 ] ) ], [] ],
	'changed account' => [ [ array_replace( $row( 10, 20, 1000 ), [ 'bank_account_id' => 4 ] ) ], [] ],
	'unsupported status' => [ [ $row( 10, 20, 1000, 'paid' ) ], [] ],
	'float' => [ [ array_replace( $row( 10, 20, 1000 ), [ 'amount_minor' => 1000.0 ] ) ], [] ],
	'overflow' => [ [ array_replace( $row( 10, 20, 1000 ), [ 'amount_minor' => '18446744073709551615' ] ) ], [] ],
	'unrelated source' => [ [ $row( 11, 21, 1000 ) ], [] ],
];
foreach ( $invalid as $label => [ $allocations, $free ] ) {
	try {
		LedgerBalance::summarize( 10, 20, 10000, 12000, 'USD', 2, 3, $allocations, $free );
		throw new RuntimeException( "$label was accepted." );
	} catch ( InvalidArgumentException $expected ) {}
}
echo "PASS exact Pro/Free balances, splits, reversals, drift and overflow guards\n";
