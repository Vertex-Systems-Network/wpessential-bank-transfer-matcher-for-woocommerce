<?php

use WPEssential\BankTransferMatcher\Claims\CustomerClaims;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'wbm_ci' !== DB_NAME ) {
	throw new RuntimeException( 'Run against the disposable wbm_ci database.' );
}

add_filter( 'pre_wp_mail', '__return_true' );
$owner = wp_insert_user( [
	'user_login' => 'wbm-claim-' . wp_generate_password( 10, false ),
	'user_pass' => wp_generate_password(),
	'role' => 'customer',
] );
if ( is_wp_error( $owner ) ) {
	throw new RuntimeException( 'Could not create customer fixture.' );
}
$orders = [];
$create = static function ( int $customer ) use ( &$orders ): WC_Order {
	$order = wc_create_order( [ 'customer_id' => $customer, 'status' => 'on-hold' ] );
	if ( ! $order instanceof WC_Order ) {
		throw new RuntimeException( 'Could not create order fixture.' );
	}
	$orders[] = $order;
	$order->set_payment_method( 'bacs' );
	$order->set_billing_email( 'fixture@example.test' );
	$order->save();
	do_action( 'woocommerce_checkout_order_created', $order );
	return $order;
};
$request = static function ( WC_Order $order ): array {
	return [
		'order_id' => (string) $order->get_id(),
		'_wbm_claim_nonce' => wp_create_nonce( 'wpessential_wbm_submit_claim:' . $order->get_id() ),
		'order_key' => $order->get_order_key(),
		'billing_email' => 'fixture@example.test',
		'transfer_date' => wp_date( 'Y-m-d' ),
		'transfer_amount' => '10',
		'transfer_reference' => 'fixture-transfer',
		'acknowledged' => 'yes',
	];
};
$expect = static function ( array $data, string $expected ): void {
	$result = CustomerClaims::process( $data );
	$actual = is_wp_error( $result ) ? $result->get_error_code() : $result[1];
	if ( $expected !== $actual ) {
		throw new RuntimeException( 'Expected ' . $expected . ', got ' . $actual );
	}
};

try {
	$registered = $create( $owner );
	wp_set_current_user( 0 );
	$expect( $request( $registered ), 'wbm_claim_forbidden' );
	wp_set_current_user( $owner );
	$data = $request( $registered );
	$expect( array_replace( $data, [ '_wbm_claim_nonce' => 'invalid' ] ), 'wbm_claim_forbidden' );
	$expect( array_replace( $data, [ 'transfer_amount' => '-1' ] ), 'invalid' );
	$expect( array_replace( $data, [ 'transfer_date' => '2026-02-30' ] ), 'invalid' );
	$expect( $data, 'submitted' );
	$expect( array_replace( $data, [ 'transfer_reference' => 'replacement' ] ), 'submitted' );

	global $wpdb;
	$table = $wpdb->prefix . 'wbm_payment_claims';
	$stored = $wpdb->get_row( $wpdb->prepare(
		"SELECT status, transfer_reference, amount_minor FROM `$table` WHERE order_id = %d",
		$registered->get_id()
	), ARRAY_A );
	if ( 'submitted' !== $stored['status'] || 'fixture-transfer' !== $stored['transfer_reference'] ||
		10 * 10 ** wc_get_price_decimals() !== (int) $stored['amount_minor'] ||
		! wc_get_order( $registered->get_id() )->has_status( 'on-hold' ) ) {
		throw new RuntimeException( 'Duplicate claim changed the saved claim or order status.' );
	}

	wp_set_current_user( 0 );
	$guest = $create( 0 );
	$data = $request( $guest );
	$expect( array_replace( $data, [ 'order_key' => 'invalid' ] ), 'wbm_claim_forbidden' );
	$expect( array_replace( $data, [ 'billing_email' => 'wrong@example.test' ] ), 'wbm_claim_forbidden' );
	$expect( $data, 'submitted' );

	$limited = $create( 0 );
	$data = array_replace( $request( $limited ), [ 'billing_email' => 'wrong@example.test' ] );
	for ( $attempt = 0; $attempt < 8; ++$attempt ) {
		$expect( $data, 'wbm_claim_forbidden' );
	}
	$expect( $request( $limited ), 'wbm_claim_limited' );

	echo "PASS owner and guest claims, nonce and identity checks, bounded inputs, atomic throttling, duplicate safety and unchanged payment status\n";
} finally {
	foreach ( $orders as $order ) {
		$order->delete( true );
	}
}
