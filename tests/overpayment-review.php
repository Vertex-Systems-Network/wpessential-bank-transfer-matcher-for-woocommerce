<?php
use WPEssential\BankTransferMatcherPro\Reconciliation\OverpaymentReview;
require dirname( __DIR__ ) . '/plugins/wpessential-bank-transfer-matcher-pro/src/Reconciliation/OverpaymentReview.php';

$over = OverpaymentReview::assess( 10000, 0, 12000, 0, 10000, 'USD', 2 );
if ( $over['classification'] !== 'overpayment_review' ||
	$over['overpayment_minor'] !== 2000 || $over['credit_unallocated_after_minor'] !== 2000 ||
	$over['order_outstanding_after_minor'] !== 0 ||
	! in_array( 'overpayment', $over['exception_codes'], true ) ||
	! in_array( 'unallocated_credit_remainder', $over['exception_codes'], true ) ||
	! $over['review_only'] || $over['order_payment_changed'] ) {
	throw new RuntimeException( 'Overpayment was silently absorbed instead of held for review.' );
}
$exact = OverpaymentReview::assess( 10000, 0, 10000, 0, 10000, 'USD', 2 );
if ( 'balanced' !== $exact['classification'] || [] !== $exact['exception_codes'] ||
	! $exact['review_only'] ) throw new RuntimeException( 'Exact match was incorrectly classified.' );
$partial = OverpaymentReview::assess( 10000, 2000, 3500, 0, 3500, 'USD', 2 );
if ( $partial['classification'] !== 'partial_order_review' || 4500 !== $partial['order_outstanding_after_minor'] ||
	! in_array( 'partial_order_funding', $partial['exception_codes'], true ) ) {
	throw new RuntimeException( 'Partial-payment remainder was lost.' );
}
$unallocated = OverpaymentReview::assess( 10000, 0, 9000, 0, 3000, 'USD', 2 );
if ( 'unallocated_credit_review' !== $unallocated['classification'] ||
	6000 !== $unallocated['credit_unallocated_after_minor'] ||
	7000 !== $unallocated['order_outstanding_after_minor'] ) throw new RuntimeException( 'Unallocated remainder was lost.' );
$already = OverpaymentReview::assess( 10000, 10000, 2500, 0, 0, 'USD', 2 );
if ( ! in_array( 'no_order_balance', $already['exception_codes'], true ) ||
	2500 !== $already['overpayment_minor'] ) throw new RuntimeException( 'No-outstanding-balance exception was skipped.' );
$zero_precision = OverpaymentReview::assess( 1000, 250, 900, 0, 750, 'JPY', 0 );
if ( $zero_precision['order_outstanding_after_minor'] !== 0 ||
	150 !== $zero_precision['credit_unallocated_after_minor'] ) {
	throw new RuntimeException( 'Zero-decimal currency remainder was incorrect.' );
}
$boundary = OverpaymentReview::assess( PHP_INT_MAX, PHP_INT_MAX - 1, 1, 0, 1, 'USD', 2 );
if ( 0 !== $boundary['order_outstanding_after_minor'] || 0 !== $boundary['credit_unallocated_after_minor'] ) {
	throw new RuntimeException( 'Near-integer-boundary review did not remain exact.' );
}
$invalid = [
	'overallocated source' => [ 10000, 0, 3000, 0, 3001, 'USD', 2 ],
	'overallocated order' => [ 10000, 5000, 10000, 0, 5001, 'USD', 2 ],
	'overspent snapshot' => [ 10000, 10001, 10000, 0, 0, 'USD', 2 ],
	'source snapshot drift' => [ 10000, 0, 10000, 10001, 0, 'USD', 2 ],
	'negative proposal' => [ 10000, 0, 10000, 0, -1, 'USD', 2 ],
	'bad currency' => [ 10000, 0, 10000, 0, 100, 'US$', 2 ],
	'precision too high' => [ 10000, 0, 10000, 0, 100, 'USD', 6 ],
];
foreach ( $invalid as $label => $args ) {
	try {
		OverpaymentReview::assess( ...$args );
		throw new RuntimeException( "$label was accepted as safe." );
	} catch ( InvalidArgumentException $expected ) {}
}
echo "PASS overpayment, unallocated source remainders, partial orders and exact integer exception preview\n";
