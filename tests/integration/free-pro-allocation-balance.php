<?php
use WPEssential\BankTransferMatcher\Domain\Money;
use WPEssential\BankTransferMatcher\Evidence\AllocationRepository;
use WPEssential\BankTransferMatcher\Evidence\EvidenceRepository;
use WPEssential\BankTransferMatcherPro\Reconciliation\AllocationLedgerRepository;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'wbm_ci' !== DB_NAME ) {
	throw new RuntimeException( 'Run only against disposable wbm_ci database.' );
}
global $wpdb;
$admin = get_user_by( 'login', 'wbm_ci' );
wp_set_current_user( $admin->ID );
$order = wc_create_order();
$order->set_payment_method( 'bacs' );
$order->set_currency( 'USD' );
$order->set_total( '100.00' );
$order->set_status( 'on-hold' );
$order->save();
$expected = new Money( 10000, 'USD', 2 );
$credit = new Money( 6000, 'USD', 2 );
$evidence = ( new EvidenceRepository( $wpdb ) )->record(
	$order->get_id(), $admin->ID, gmdate( 'Y-m-d' ), $credit, 'FREE-OTHER-SOURCE', 'Synthetic separate credited transfer'
);
$ledger = new AllocationLedgerRepository( $wpdb );
$ledger->install();
$pro = $wpdb->prefix . 'wbm_pro_allocations';
// Synthetic source distinct from the merchant-entered Free evidence.
$stage_table = $wpdb->prefix . 'wbm_pro_import_rows';
$stage = $wpdb->insert( $stage_table, [
	'batch_id' => 900000000 + $order->get_id(), 'source_row' => 2,
	'canonical_json' => wp_json_encode( [
		'posted_date' => gmdate( 'Y-m-d' ), 'amount_minor' => 7000, 'currency' => 'USD',
		'exponent' => 2, 'reference' => 'UNRELATED-PRO-SOURCE',
	] ),
	'error_code' => '', 'warning_code' => '',
	'identity_fingerprint' => hash( 'sha256', 'cross-edition-other-bank-credit-' . $order->get_id() ),
] );
if ( 1 !== $stage ) throw new RuntimeException( 'Could not create synthetic distinct Pro source.' );
$source_id = (int) $wpdb->insert_id;
$approved = $wpdb->insert( $pro, [
	'transaction_row_id' => $source_id,
	'source_fingerprint' => hash( 'sha256', 'distinct-imported-source-' . $order->get_id() ),
	'bank_account_id' => 3, 'order_id' => $order->get_id(),
	'amount_minor' => 7000, 'credit_minor' => 7000, 'expected_minor' => 10000,
	'currency' => 'USD', 'exponent' => 2, 'state' => 'approved',
	'created_by' => $admin->ID, 'approved_by' => $admin->ID, 'reason' => 'Synthetic completed Pro credit',
	'created_at' => gmdate( 'Y-m-d H:i:s' ),
] );
if ( 1 !== $approved ) throw new RuntimeException( 'Could not create synthetic approved Pro allocation.' );
$free = new AllocationRepository( $wpdb );
try {
	try {
		$free->allocate( $order->get_id(), $evidence['id'], $admin->ID, $expected, $credit, 'Overpay attempt' );
		throw new RuntimeException( 'Free over-allocation ignored existing Pro credit.' );
	} catch ( InvalidArgumentException $error ) {
		if ( 'Free over-allocation ignored existing Pro credit.' === $error->getMessage() ) throw $error;
	}
	$partial = $free->allocate( $order->get_id(), $evidence['id'], $admin->ID, $expected,
		new Money( 3000, 'USD', 2 ), 'Checked independent synthetic bank credit' );
	if ( 10000 !== $partial['allocated_minor'] || 0 !== $partial['outstanding_minor'] ||
		! $partial['fully_allocated'] || $partial['order_payment_changed'] ||
		! wc_get_order( $order->get_id() )->has_status( 'on-hold' ) ) {
		throw new RuntimeException( 'Free and Pro approved allocations did not share one exact order balance.' );
	}
	try {
		$free->allocate( $order->get_id(), $evidence['id'], $admin->ID, $expected,
			new Money( 1, 'USD', 2 ), 'Attempt to replay bank credit' );
		throw new RuntimeException( 'Fully funded Free/Pro order accepted an extra allocation.' );
	} catch ( InvalidArgumentException $error ) {
		if ( 'Fully funded Free/Pro order accepted an extra allocation.' === $error->getMessage() ) throw $error;
	}
	echo "PASS Free writes count Pro approved balance; over-allocation blocked and WooCommerce remains unpaid\n";
} finally {
	$wpdb->delete( $wpdb->prefix . 'wbm_manual_allocations', [ 'order_id' => $order->get_id() ], [ '%d' ] );
	$wpdb->delete( $pro, [ 'order_id' => $order->get_id() ], [ '%d' ] );
	$wpdb->delete( $stage_table, [ 'id' => $source_id ], [ '%d' ] );
	$wpdb->delete( $wpdb->prefix . 'wbm_bank_evidence', [ 'order_id' => $order->get_id() ], [ '%d' ] );
	$order->delete( true );
}
