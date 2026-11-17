<?php

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'wbm_ci' !== DB_NAME ) {
	throw new RuntimeException( 'Run against the disposable wbm_ci database.' );
}

global $wpdb;
$order = wc_create_order( [ 'status' => 'on-hold' ] );
$order->set_payment_method( 'bacs' );
$order->set_total( '20.00' );
$order->save();
$admin = get_user_by( 'login', 'wbm_ci' );
$path = '/wpessential-wbm/v1/orders/' . $order->get_id();
$post = static function ( string $route, array $data ) use ( $path ): WP_REST_Response {
	$request = new WP_REST_Request( 'POST', $path . $route );
	$request->set_body_params( $data );
	return rest_do_request( $request );
};
$evidence = [ 'date' => gmdate( 'Y-m-d' ), 'amount' => '20.00', 'reference' => 'ALLOC-FIXTURE', 'note' => 'Synthetic cleared bank credit' ];

try {
	do_action( 'rest_api_init' );
	wp_set_current_user( $admin->ID );
	$record = $post( '/bank-evidence', $evidence );
	if ( 200 !== $record->get_status() ) {
		throw new RuntimeException( 'Could not create bank evidence fixture: ' . wp_json_encode( $record->get_data() ) );
	}
	$data = [ 'evidence_id' => $record->get_data()['id'], 'amount' => '7.50', 'acknowledged' => true, 'reason' => 'Confirmed against synthetic bank statement' ];
	wp_set_current_user( 0 );
	if ( 401 !== $post( '/allocations', $data )->get_status() ) {
		throw new RuntimeException( 'Anonymous approval was accepted.' );
	}
	wp_set_current_user( $admin->ID );
	if ( 422 !== $post( '/allocations', array_replace( $data, [ 'acknowledged' => false ] ) )->get_status() ) {
		throw new RuntimeException( 'Approval without bank acknowledgement was accepted.' );
	}
	$partial = $post( '/allocations', $data );
	if ( 200 !== $partial->get_status() || 750 !== $partial->get_data()['allocated_minor'] || 1250 !== $partial->get_data()['outstanding_minor'] || $partial->get_data()['fully_allocated'] ) {
		throw new RuntimeException( 'Incorrect exact partial balance: ' . wp_json_encode( $partial->get_data() ) );
	}
	if ( 409 !== $post( '/finalize-payment', [ 'acknowledged' => true ] )->get_status() ) {
		throw new RuntimeException( 'Partial balance was finalized.' );
	}
	if ( 409 !== $post( '/allocations', array_replace( $data, [ 'amount' => '13.00' ] ) )->get_status() ) {
		throw new RuntimeException( 'Over-allocation was accepted.' );
	}
	$full = $post( '/allocations', array_replace( $data, [ 'amount' => '12.50' ] ) );
	if ( 200 !== $full->get_status() || 0 !== $full->get_data()['outstanding_minor'] || ! $full->get_data()['fully_allocated'] || ! wc_get_order( $order->get_id() )->has_status( 'on-hold' ) ) {
		throw new RuntimeException( 'Full internal balance or guarded WooCommerce lifecycle failed: ' . wp_json_encode( $full->get_data() ) );
	}
	if ( 409 !== $post( '/allocations', $data )->get_status() ) {
		throw new RuntimeException( 'Replay allocation was accepted.' );
	}
	if ( 422 !== $post( '/finalize-payment', [ 'acknowledged' => false ] )->get_status() ) {
		throw new RuntimeException( 'Finalization without acknowledgement was accepted.' );
	}
	$completed = $post( '/finalize-payment', [ 'acknowledged' => true ] );
	$retry = $post( '/finalize-payment', [ 'acknowledged' => true ] );
	if ( 200 !== $completed->get_status() || 'completed' !== $completed->get_data()['status'] ||
		200 !== $retry->get_status() || 'already_completed' !== $retry->get_data()['status'] ||
		! wc_get_order( $order->get_id() )->is_paid() ) {
		throw new RuntimeException( 'Payment completion or idempotent retry failed: ' . wp_json_encode( [ $completed->get_data(), $retry->get_data() ] ) );
	}
	echo "PASS permissions, exact balances, over-allocation, guarded full payment completion and idempotent retry\n";
} finally {
	$wpdb->delete( $wpdb->prefix . 'wbm_payment_finalizations', [ 'order_id' => $order->get_id() ], [ '%d' ] );
	$wpdb->delete( $wpdb->prefix . 'wbm_manual_allocations', [ 'order_id' => $order->get_id() ], [ '%d' ] );
	$wpdb->delete( $wpdb->prefix . 'wbm_bank_evidence', [ 'order_id' => $order->get_id() ], [ '%d' ] );
	$order->delete( true );
}
