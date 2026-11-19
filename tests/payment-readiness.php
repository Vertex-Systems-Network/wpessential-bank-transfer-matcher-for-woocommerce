<?php
use WPEssential\BankTransferMatcherPro\Reconciliation\PaymentReadiness;
require dirname( __DIR__ ) . '/plugins/wpessential-bank-transfer-matcher-pro/src/Reconciliation/PaymentReadiness.php';

$fact = [
	'expected_minor' => 10000, 'free_allocated_minor' => 2000, 'pro_approved_minor' => 8000,
	'pro_approved_row_count' => 2, 'pending_allocation_reviews' => 0,
	'unresolved_financial_exceptions' => 0, 'currency' => 'USD', 'exponent' => 2,
	'gateway' => 'bacs', 'order_status' => 'on-hold', 'order_is_paid' => false,
	'bank_sources_verified' => true, 'manual_evidence_verified' => true,
	'maker_checker_verified' => true, 'receiving_accounts_verified' => true,
];
$ready = PaymentReadiness::inspect( $fact );
if ( ! $ready['ready_for_explicit_finalization'] || $ready['reasons'] ||
	! $ready['review_only'] || $ready['auto_pay_allowed'] || $ready['order_payment_changed'] ) {
	throw new RuntimeException( 'Verified credit policy triggered a payment or lost its explicit-review boundary.' );
}
$blocked = [
	'partial' => [ 'pro_approved_minor', 7999, 'outstanding_order_balance' ],
	'pending allocations' => [ 'pending_allocation_reviews', 1, 'pending_allocation_review' ],
	'unresolved overpayment' => [ 'unresolved_financial_exceptions', 1, 'unresolved_financial_exception' ],
	'not confirmed source' => [ 'bank_sources_verified', false, 'bank_source_provenance_unverified' ],
	'manual evidence unverified' => [ 'manual_evidence_verified', false, 'manual_evidence_unverified' ],
	'checker missing' => [ 'maker_checker_verified', false, 'maker_checker_verification_required' ],
	'bank binding missing' => [ 'receiving_accounts_verified', false, 'receiving_bank_confirmation_required' ],
	'paid order' => [ 'order_is_paid', true, 'order_already_paid' ],
	'wrong gateway' => [ 'gateway', 'cod', 'non_bacs_gateway' ],
	'refunded order' => [ 'order_status', 'refunded', 'ineligible_order_status' ],
	'zero Pro allocations' => [ 'pro_approved_minor', 0, 'outstanding_order_balance' ],
];
foreach ( $blocked as $name => [ $key, $value, $code ] ) {
	$result = PaymentReadiness::inspect( array_replace( $fact, [ $key => $value ] ) );
	if ( $result['ready_for_explicit_finalization'] || ! in_array( $code, $result['reasons'], true ) ||
		$result['auto_pay_allowed'] || $result['order_payment_changed'] ) {
		throw new RuntimeException( "$name did not fail closed." );
	}
}
$bad = [
	[ 'free_allocated_minor' => 2001.0 ], [ 'pro_approved_minor' => -1 ],
	[ 'free_allocated_minor' => 9999 ], [ 'exponent' => 5 ],
	[ 'currency' => 'usd' ], [ 'maker_checker_verified' => 1 ],
	[ 'expected_minor' => 0 ], [ 'pending_allocation_reviews' => -1 ],
];
foreach ( $bad as $change ) {
	try {
		PaymentReadiness::inspect( array_replace( $fact, $change ) );
		throw new RuntimeException( 'Malformed financial readiness input was accepted.' );
	} catch ( InvalidArgumentException $expected ) {}
}
$large = PaymentReadiness::inspect( array_replace( $fact, [
	'expected_minor' => PHP_INT_MAX, 'free_allocated_minor' => PHP_INT_MAX - 1,
	'pro_approved_minor' => 1,
] ) );
if ( ! $large['ready_for_explicit_finalization'] ) {
	throw new RuntimeException( 'Exact-money readiness lost integer precision near PHP_INT_MAX.' );
}
echo "PASS explicit payment readiness guards, partial balances, maker-checker, receiving accounts and zero automatic paid actions\n";
