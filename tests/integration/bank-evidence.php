<?php

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'wbm_ci' !== DB_NAME ) {
	throw new RuntimeException( 'Run against the disposable wbm_ci database.' );
}

$order = wc_create_order( [ 'status' => 'on-hold' ] );
$order->set_payment_method( 'bacs' );
$order->save();
$admin = get_user_by( 'login', 'wbm_ci' );
$payload = [ 'date' => gmdate( 'Y-m-d' ), 'amount' => '12.50', 'reference' => ' BANK-42 ', 'note' => 'Synthetic statement entry' ];
$send = static function ( array $data ) use ( $order ): WP_REST_Response {
	$request = new WP_REST_Request( 'POST', '/wpessential-wbm/v1/orders/' . $order->get_id() . '/bank-evidence' );
	$request->set_body_params( $data );
	return rest_do_request( $request );
};

try {
	do_action( 'rest_api_init' );
	wp_set_current_user( 0 );
	if ( 401 !== $send( $payload )->get_status() ) {
		throw new RuntimeException( 'Anonymous evidence write accepted.' );
	}
	wp_set_current_user( $admin->ID );
	if ( 422 !== $send( array_replace( $payload, [ 'amount' => '-1' ] ) )->get_status() ) {
		throw new RuntimeException( 'Negative evidence amount accepted.' );
	}
	$first = $send( $payload );
	$second = $send( array_replace( $payload, [ 'reference' => 'BANK-42' ] ) );
	if ( 200 !== $first->get_status() || 200 !== $second->get_status() ||
		$first->get_data()['duplicate_warning'] || ! $second->get_data()['duplicate_warning'] ||
		! wc_get_order( $order->get_id() )->has_status( 'on-hold' ) ) {
		throw new RuntimeException( 'Evidence duplicate warning or payment isolation failed.' );
	}
	echo "PASS permission, amount validation, duplicate warning and unchanged order status\n";
} finally {
	$order->delete( true );
	global $wpdb;
	$wpdb->delete( $wpdb->prefix . 'wbm_bank_evidence', [ 'order_id' => $order->get_id() ], [ '%d' ] );
}
