<?php

use WPEssential\BankTransferMatcher\Claims\ClaimRepository;
use WPEssential\BankTransferMatcher\Domain\Money;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'wbm_ci' !== DB_NAME ) {
	throw new RuntimeException( 'Run against the disposable wbm_ci database.' );
}

global $wpdb;
$admin = get_user_by( 'login', 'wbm_ci' );
$orders = [];
$new_order = static function () use ( &$orders, $wpdb ): WC_Order {
	$order = wc_create_order( [ 'status' => 'on-hold' ] );
	$order->set_payment_method( 'bacs' );
	$order->set_billing_email( 'claim-review@example.test' );
	$order->set_total( '10.00' );
	$order->save();
	( new ClaimRepository( $wpdb ) )->save( $order->get_id(), [
		'date' => gmdate( 'Y-m-d' ), 'amount' => Money::from_decimal( '10.00', $order->get_currency(), wc_get_price_decimals() ),
		'reference' => 'CLAIM-' . $order->get_id(), 'sender' => 'Synthetic sender', 'note' => 'Synthetic claim',
	] );
	$orders[] = $order;
	return $order;
};
$post = static function ( WC_Order $order, string $route, array $data ): WP_REST_Response {
	$request = new WP_REST_Request( 'POST', '/wpessential-wbm/v1/orders/' . $order->get_id() . $route );
	$request->set_body_params( $data );
	return rest_do_request( $request );
};
$messages = [];
$mail_ok = false;
$mail_filter = static function ( $pre, array $atts ) use ( &$messages, &$mail_ok ) {
	$messages[] = $atts;
	return $mail_ok;
};

try {
	do_action( 'rest_api_init' );
	add_filter( 'pre_wp_mail', $mail_filter, 10, 2 );
	$rejected = $new_order();
	wp_set_current_user( 0 );
	$review_data = [ 'status' => 'under_review', 'comment' => 'Private reviewer comment' ];
	if ( 401 !== $post( $rejected, '/claim/review', $review_data )->get_status() ) {
		throw new RuntimeException( 'Anonymous claim review was accepted.' );
	}
	wp_set_current_user( $admin->ID );
	$under_review = $post( $rejected, '/claim/review', $review_data );
	if ( 200 !== $under_review->get_status() || 'pending' !== $under_review->get_data()['notification'] ) {
		throw new RuntimeException( 'Unsent notification did not remain pending.' );
	}
	$mail_ok = true;
	$retry_route = '/claim/notifications/' . $under_review->get_data()['event_id'] . '/retry';
	if ( 'sent' !== $post( $rejected, $retry_route, [] )->get_data()['notification'] ) {
		throw new RuntimeException( 'Claim email retry failed.' );
	}
	$sent_count = count( $messages );
	$post( $rejected, $retry_route, [] );
	if ( $sent_count !== count( $messages ) ) {
		throw new RuntimeException( 'Sent email was delivered twice.' );
	}
	if ( 409 !== $post( $rejected, '/claim/review', $review_data )->get_status() ) {
		throw new RuntimeException( 'Duplicate claim status transition was accepted.' );
	}
	$declined = $post( $rejected, '/claim/review', [ 'status' => 'rejected', 'comment' => 'No matching incoming credit' ] );
	if ( 200 !== $declined->get_status() || 'rejected' !== $declined->get_data()['status'] || wc_get_order( $rejected->get_id() )->is_paid() ) {
		throw new RuntimeException( 'Rejected claim changed payment state.' );
	}

	$verified = $new_order();
	$verification = [ 'status' => 'verified', 'comment' => 'Cleared credit checked', 'acknowledged' => true ];
	if ( 409 !== $post( $verified, '/claim/review', $verification )->get_status() ) {
		throw new RuntimeException( 'Claim verified without an allocation.' );
	}
	$bank = $post( $verified, '/bank-evidence', [ 'date' => gmdate( 'Y-m-d' ), 'amount' => '10.00', 'reference' => 'REVIEW-' . $verified->get_id() ] );
	$allocated = $post( $verified, '/allocations', [ 'evidence_id' => $bank->get_data()['id'], 'amount' => '10.00', 'acknowledged' => true, 'reason' => 'Matched cleared credit' ] );
	if ( 200 !== $allocated->get_status() ) {
		throw new RuntimeException( 'Could not create verified allocation fixture.' );
	}
	$approved = $post( $verified, '/claim/review', $verification );
	if ( 200 !== $approved->get_status() || 'verified' !== $approved->get_data()['status'] || wc_get_order( $verified->get_id() )->is_paid() ) {
		throw new RuntimeException( 'Claim verification improperly completed payment.' );
	}
	foreach ( $messages as $mail ) {
		if ( str_contains( $mail['message'], 'Private reviewer comment' ) || str_contains( $mail['message'], 'No matching incoming credit' ) ) {
			throw new RuntimeException( 'Private reviewer comment leaked in customer mail.' );
		}
	}
	echo "PASS claim transitions, append-only review, allocation-backed verification, pending email retry, no duplicate delivery and no payment mutation\n";
} finally {
	remove_filter( 'pre_wp_mail', $mail_filter, 10 );
	foreach ( $orders as $order ) {
		foreach ( [ 'wbm_claim_review_events', 'wbm_manual_allocations', 'wbm_bank_evidence', 'wbm_payment_claims' ] as $suffix ) {
			$wpdb->delete( $wpdb->prefix . $suffix, [ 'order_id' => $order->get_id() ], [ '%d' ] );
		}
		$order->delete( true );
	}
}
