<?php
use WPEssential\BankTransferMatcherPro\Reconciliation\AllocationLedgerRepository;
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'wbm_ci' !== DB_NAME ) throw new RuntimeException( 'Disposable wbm_ci database required.' );
global $wpdb;
$repo = new AllocationLedgerRepository( $wpdb );
$repo->install();
$table = $wpdb->prefix . 'wbm_pro_allocations';
$fields = $wpdb->get_col( "SHOW COLUMNS FROM $table", 0 );
foreach ( [ 'transaction_row_id', 'source_fingerprint', 'bank_account_id', 'order_id',
	'amount_minor', 'credit_minor', 'expected_minor', 'state', 'approved_by', 'version', 'reversed_at', 'idempotency_key' ] as $field ) {
	if ( ! in_array( $field, $fields, true ) ) throw new RuntimeException( "Missing schema field: $field" );
}
$order = 800000000 + random_int( 1, 100000 );
$source = 900000000 + random_int( 1, 100000 );
try {
	$empty = $repo->snapshot( $order, $source, 10000, 12000, 'USD', 2, 3 );
	if ( 10000 !== $empty['order_outstanding_minor'] ) throw new RuntimeException( 'Empty ledger invalid.' );
	$insert = $wpdb->insert( $table, [
		'transaction_row_id' => $source, 'source_fingerprint' => str_repeat( 'a', 64 ),
		'bank_account_id' => 3, 'order_id' => $order, 'amount_minor' => 2500,
		'credit_minor' => 12000, 'expected_minor' => 10000, 'currency' => 'USD',
		'exponent' => 2, 'state' => 'approved', 'created_by' => 1, 'reason' => 'Synthetic fixture',
		'created_at' => gmdate( 'Y-m-d H:i:s' ),
	] );
	if ( 1 !== $insert ) throw new RuntimeException( 'Could not insert synthetic test row.' );
	$balance = $repo->snapshot( $order, $source, 10000, 12000, 'USD', 2, 3 );
	if ( 7500 !== $balance['order_outstanding_minor'] || 9500 !== $balance['credit_unallocated_minor'] ) {
		throw new RuntimeException( 'Persisted balances incorrect.' );
	}
	echo "PASS Pro ledger schema and read-only persisted recomputation\n";
} finally {
	$wpdb->delete( $table, [ 'order_id' => $order ], [ '%d' ] );
}
