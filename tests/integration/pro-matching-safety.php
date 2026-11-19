<?php

use WPEssential\BankTransferMatcherPro\Matching\DeterministicMatcher;
use WPEssential\BankTransferMatcherPro\Matching\EligibilityPolicy;
use WPEssential\BankTransferMatcherPro\Matching\ExactMatchFacts;
use WPEssential\BankTransferMatcherPro\Matching\WooOrderEligibility;

$created_orders = [];
$make_order = static function ( string $status = 'pending', string $gateway = 'bacs' ) use ( &$created_orders ): WC_Order {
	$order = wc_create_order();
	if ( is_wp_error( $order ) ) throw new RuntimeException( $order->get_error_message() );
	$order->set_currency( 'USD' );
	$order->set_total( '125.00' );
	$order->set_payment_method( $gateway );
	$order->set_status( $status );
	$order->save();
	$created_orders[] = $order;
	return $order;
};

$adapter = new WooOrderEligibility();
$policy = new EligibilityPolicy();
$matcher = new DeterministicMatcher();
$pending = $make_order();
$eligible = $adapter->context( 1001, 12500, 'USD', 7, false, $pending );
if ( ! $policy->evaluate( $eligible )->eligible ) throw new RuntimeException( 'Pending BACS order was not eligible.' );

$exact = new ExactMatchFacts( 'WBM-1001', 'WBM-1001', 12500, 12500, 'USD', 'USD', 7, 7, 1 );
if ( 'suggested' !== $matcher->match( $eligible, $policy->evaluate( $eligible ), $exact )->status ) throw new RuntimeException( 'Safe exact fixture was not suggested.' );
$false_reference = new ExactMatchFacts( 'WBM-1001', 'WBM-9999', 12500, 12500, 'USD', 'USD', 7, 7, 0 );
if ( 'review_required' !== $matcher->match( $eligible, $policy->evaluate( $eligible ), $false_reference )->status ) throw new RuntimeException( 'Reference mismatch was suggested.' );
$false_amount = new ExactMatchFacts( 'WBM-1001', 'WBM-1001', 12499, 12500, 'USD', 'USD', 7, 7, 1 );
if ( 'review_required' !== $matcher->match( $eligible, $policy->evaluate( $eligible ), $false_amount )->status ) throw new RuntimeException( 'Amount mismatch was suggested.' );
try {
	$missing = new ExactMatchFacts( '', 'WBM-1001', 12500, 12500, 'USD', 'USD', 7, 7, 0 );
	$matcher->match( $eligible, $policy->evaluate( $eligible ), $missing );
	throw new RuntimeException( 'Missing bank reference was accepted.' );
} catch ( InvalidArgumentException $expected ) {
}

foreach ( [
	[ $make_order( 'cancelled' ), false, 'cancelled' ],
	[ $make_order( 'refunded' ), false, 'refunded' ],
	[ $make_order( 'processing' ), false, 'paid' ],
	[ $make_order( 'pending', 'cod' ), false, 'non-BACS' ],
	[ $pending, true, 'duplicate' ],
] as [ $order, $duplicate, $label ] ) {
	$context = $adapter->context( 2000 + $order->get_id(), 12500, 'USD', 7, $duplicate, $order );
	$decision = $policy->evaluate( $context );
	if ( $decision->eligible || 'blocked' !== $matcher->match( $context, $decision, $exact )->status ) throw new RuntimeException( "$label order/evidence was matchable." );
}

foreach ( $created_orders as $created_order ) $created_order->delete( true );
echo "PASS false-match, missing-reference, cancelled, refunded, paid, non-BACS and duplicate safety\n";
