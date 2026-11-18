<?php

use Automattic\WooCommerce\Utilities\OrderUtil;
use WPEssential\BankTransferMatcher\Claims\CustomerClaims;
use WPEssential\BankTransferMatcher\Orders\ReferenceIssuer;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'wbm_ci' !== DB_NAME ) {
	throw new RuntimeException( 'Run against the disposable wbm_ci database.' );
}
$mode = $args[0] ?? '';
if ( ! in_array( $mode, [ 'classic', 'hpos' ], true ) || OrderUtil::custom_orders_table_usage_is_enabled() !== ( 'hpos' === $mode ) ) {
	throw new RuntimeException( 'End-to-end test requires the requested order storage mode.' );
}
global $wpdb;
require_once ABSPATH . 'wp-admin/includes/user.php';
$admin = get_user_by( 'login', 'wbm_ci' );
$login = 'wbm_e2e_' . strtolower( $mode );
$user_id = wp_create_user( $login, wp_generate_password( 24 ), $login . '@example.test' );
if ( is_wp_error( $user_id ) ) throw new RuntimeException( 'Could not create end-to-end customer.' );
$order = wc_create_order( [ 'status' => 'on-hold', 'customer_id' => $user_id ] );
$order->set_payment_method( 'bacs' );
$order->set_billing_email( $login . '@example.test' );
$order->set_currency( 'USD' );
$order->set_total( '25.00' );
$order->save();
$post = static function ( string $route, array $data ) use ( $order ): WP_REST_Response {
	$request = new WP_REST_Request( 'POST', '/wpessential-wbm/v1/orders/' . $order->get_id() . $route );
	$request->set_body_params( $data );
	return rest_do_request( $request );
};
$mail = static fn () => true;
try {
	ReferenceIssuer::issue_at_checkout( $order );
	$reference = $wpdb->get_var( $wpdb->prepare( "SELECT reference FROM `{$wpdb->prefix}wbm_payment_references` WHERE order_id = %d", $order->get_id() ) );
	if ( ! is_string( $reference ) || '' === $reference ) throw new RuntimeException( 'Checkout did not issue a payment reference.' );

	wp_set_current_user( $user_id );
	$claim = CustomerClaims::process( [
		'order_id' => (string) $order->get_id(), '_wbm_claim_nonce' => wp_create_nonce( 'wpessential_wbm_submit_claim:' . $order->get_id() ),
		'transfer_date' => gmdate( 'Y-m-d' ), 'transfer_amount' => '25.00', 'transfer_reference' => $reference,
		'sender_name' => 'End-to-end customer', 'customer_note' => 'Paid using the displayed reference', 'acknowledged' => 'yes',
	] );
	if ( is_wp_error( $claim ) || 'submitted' !== $claim[1] || wc_get_order( $order->get_id() )->is_paid() ) throw new RuntimeException( 'Customer claim submission changed or failed the payment state.' );

	do_action( 'rest_api_init' );
	add_filter( 'pre_wp_mail', $mail );
	wp_set_current_user( $admin->ID );
	$review = $post( '/claim/review', [ 'status' => 'under_review', 'comment' => 'Awaiting cleared merchant bank credit' ] );
	$bank = $post( '/bank-evidence', [ 'date' => gmdate( 'Y-m-d' ), 'amount' => '25.00', 'reference' => 'E2E-BANK-' . $order->get_id(), 'note' => 'Synthetic cleared credit' ] );
	if ( 200 !== $review->get_status() || 200 !== $bank->get_status() || wc_get_order( $order->get_id() )->is_paid() ) throw new RuntimeException( 'Review or bank evidence step failed, or paid too early.' );
	$allocation = $post( '/allocations', [ 'evidence_id' => $bank->get_data()['id'], 'amount' => '25.00', 'acknowledged' => true, 'reason' => 'Exact cleared credit matched to checkout reference' ] );
	if ( 200 !== $allocation->get_status() || ! $allocation->get_data()['fully_allocated'] || wc_get_order( $order->get_id() )->is_paid() ) throw new RuntimeException( 'Full allocation did not preserve the explicit payment boundary.' );
	$verified = $post( '/claim/review', [ 'status' => 'verified', 'comment' => 'Exact cleared credit and allocation checked', 'acknowledged' => true ] );
	$completed = $post( '/finalize-payment', [ 'acknowledged' => true ] );
	if ( 200 !== $verified->get_status() || 200 !== $completed->get_status() || ! wc_get_order( $order->get_id() )->is_paid() ) throw new RuntimeException( 'Verified claim and explicit payment completion failed.' );
	echo "PASS $mode checkout reference, customer claim, review, evidence, exact allocation and explicit payment completion\n";
} finally {
	remove_filter( 'pre_wp_mail', $mail );
	foreach ( [ 'wbm_payment_finalizations', 'wbm_claim_review_events', 'wbm_manual_allocations', 'wbm_bank_evidence', 'wbm_payment_claims', 'wbm_claim_attempts', 'wbm_payment_references' ] as $suffix ) {
		$wpdb->delete( $wpdb->prefix . $suffix, [ 'order_id' => $order->get_id() ], [ '%d' ] );
	}
	$order->delete( true );
	wp_delete_user( $user_id );
}
