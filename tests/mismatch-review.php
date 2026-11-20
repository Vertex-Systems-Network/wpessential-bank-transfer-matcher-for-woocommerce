<?php
use WPEssential\BankTransferMatcherPro\Reconciliation\MismatchReview;
require dirname( __DIR__ ) . '/plugins/wpessential-bank-transfer-matcher-pro/src/Reconciliation/MismatchReview.php';

$order = [
	'order_id' => 51, 'method' => 'bacs', 'status' => 'on-hold',
	'currency' => 'USD', 'exponent' => 2, 'outstanding_minor' => 10000,
	'reference' => 'WB-ABC 009', 'expected_bank_account_id' => 7,
];
$source = [
	'source_id' => 12, 'bank_account_id' => 7, 'currency' => 'USD',
	'exponent' => 2, 'credit_minor' => 10000, 'reference' => 'wbabc009',
	'unique' => true, 'cleared_confirmed' => true,
];
$exact = MismatchReview::inspect( $order, $source );
if ( $exact['classification'] !== 'exact_candidate_review' || [] !== $exact['codes'] ||
	! $exact['review_only'] || $exact['auto_pay_allowed'] || $exact['order_payment_changed'] ) {
	throw new RuntimeException( 'Exact credit review was treated as a payment authorization.' );
}
$cases = [
	'unknown bank account' => [ array_replace( $order, [ 'expected_bank_account_id' => 0 ] ), $source, 'receiving_bank_account_unconfirmed' ],
	'wrong receiving account' => [ array_replace( $order, [ 'expected_bank_account_id' => 8 ] ), $source, 'receiving_bank_account_mismatch' ],
	'wrong currency' => [ $order, array_replace( $source, [ 'currency' => 'EUR' ] ), 'currency_mismatch' ],
	'wrong exponent' => [ $order, array_replace( $source, [ 'exponent' => 0 ] ), 'currency_precision_mismatch' ],
	'wrong reference' => [ $order, array_replace( $source, [ 'reference' => 'OTHER-100' ] ), 'payment_reference_mismatch' ],
	'wrong gateway' => [ array_replace( $order, [ 'method' => 'cod' ] ), $source, 'unsupported_gateway' ],
	'paid or cancelled order' => [ array_replace( $order, [ 'status' => 'completed' ] ), $source, 'ineligible_order_status' ],
	'duplicate source' => [ $order, array_replace( $source, [ 'unique' => false ] ), 'ambiguous_bank_source' ],
	'uncleared deposit' => [ $order, array_replace( $source, [ 'cleared_confirmed' => false ] ), 'unconfirmed_bank_clearance' ],
	'overpayment' => [ $order, array_replace( $source, [ 'credit_minor' => 12000 ] ), 'credit_exceeds_outstanding' ],
	'partial credit' => [ $order, array_replace( $source, [ 'credit_minor' => 4000 ] ), 'partial_bank_credit' ],
	'fully paid order' => [ array_replace( $order, [ 'outstanding_minor' => 0 ] ), $source, 'no_outstanding_order_balance' ],
];
foreach ( $cases as $label => [ $order_sample, $source_sample, $code ] ) {
	$result = MismatchReview::inspect( $order_sample, $source_sample );
	if ( ! in_array( $code, $result['codes'], true ) || 'mismatch_review' !== $result['classification'] ||
		$result['auto_pay_allowed'] || ! $result['review_only'] ) {
		throw new RuntimeException( "$label was silently accepted." );
	}
}
$invalid = [
	[ array_replace( $order, [ 'outstanding_minor' => 10000.0 ] ), $source ],
	[ $order, array_replace( $source, [ 'credit_minor' => 10000.0 ] ) ],
	[ $order, array_replace( $source, [ 'unique' => 1 ] ) ],
	[ $order, array_replace( $source, [ 'bank_account_id' => 0 ] ) ],
	[ array_replace( $order, [ 'reference' => '' ] ), $source ],
	[ array_replace( $order, [ 'currency' => 'usd' ] ), $source ],
];
foreach ( $invalid as [ $bad_order, $bad_source ] ) {
	try {
		MismatchReview::inspect( $bad_order, $bad_source );
		throw new RuntimeException( 'Invalid bank/order identity was accepted.' );
	} catch ( InvalidArgumentException $expected ) {}
}
$large = MismatchReview::inspect(
	array_replace( $order, [ 'outstanding_minor' => PHP_INT_MAX ] ),
	array_replace( $source, [ 'credit_minor' => PHP_INT_MAX ] )
);
if ( 'exact_candidate_review' !== $large['classification'] ) throw new RuntimeException( 'Large exact amount lost precision.' );
echo "PASS currency, precision, receiving account, reference, amount, eligibility and credit-trust mismatch classification\n";
