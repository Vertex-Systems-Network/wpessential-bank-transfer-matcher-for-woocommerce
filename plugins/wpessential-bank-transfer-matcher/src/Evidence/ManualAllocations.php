<?php

namespace WPEssential\BankTransferMatcher\Evidence;

use InvalidArgumentException;
use RuntimeException;
use WPEssential\BankTransferMatcher\Access\Capabilities;
use WPEssential\BankTransferMatcher\Domain\Money;
use WPEssential\BankTransferMatcher\Orders\PaymentMethods;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/** Explicit finance approval of a cleared credit, separate from a customer claim. */
final class ManualAllocations {
	public static function register(): void {
		add_action( 'rest_api_init', static function (): void {
			register_rest_route( 'wpessential-wbm/v1', '/orders/(?P<order_id>\d+)/allocations', [
				'methods' => WP_REST_Server::CREATABLE,
				'permission_callback' => static function (): bool|WP_Error {
					return current_user_can( Capabilities::APPROVE ) ? true : new WP_Error( 'wbm_forbidden', __( 'Allocation approval permission is required.', 'wpessential-bank-transfer-matcher' ), [ 'status' => rest_authorization_required_code() ] );
				},
				'callback' => [ self::class, 'apply' ],
				'args' => [
					'order_id' => [ 'required' => true, 'type' => 'integer', 'minimum' => 1 ],
					'evidence_id' => [ 'required' => true, 'type' => 'integer', 'minimum' => 1 ],
					'amount' => [ 'required' => true, 'type' => 'string' ],
					'acknowledged' => [ 'required' => true, 'type' => 'boolean' ],
					'reason' => [ 'required' => true, 'type' => 'string', 'minLength' => 3, 'maxLength' => 1000 ],
				],
			] );
			register_rest_route( 'wpessential-wbm/v1', '/orders/(?P<order_id>\d+)/finalize-payment', [
				'methods' => WP_REST_Server::CREATABLE,
				'permission_callback' => static function (): bool|WP_Error {
					return current_user_can( Capabilities::APPROVE ) ? true : new WP_Error( 'wbm_forbidden', __( 'Payment approval permission is required.', 'wpessential-bank-transfer-matcher' ), [ 'status' => rest_authorization_required_code() ] );
				},
				'callback' => [ self::class, 'finalize' ],
				'args' => [
					'order_id' => [ 'required' => true, 'type' => 'integer', 'minimum' => 1 ],
					'acknowledged' => [ 'required' => true, 'type' => 'boolean' ],
				],
			] );
		} );
	}

	public static function apply( WP_REST_Request $request ): array|WP_Error {
		$order = wc_get_order( (int) $request['order_id'] );
		if ( ! $order || PaymentMethods::BACS !== $order->get_payment_method() || $order->is_paid() || ! $order->has_status( [ 'on-hold', 'pending' ] ) ) {
			return new WP_Error( 'wbm_ineligible_order', __( 'An unpaid bank transfer order is required.', 'wpessential-bank-transfer-matcher' ), [ 'status' => 422 ] );
		}
		if ( true !== $request['acknowledged'] ) {
			return new WP_Error( 'wbm_acknowledgement_required', __( 'Confirm that a cleared incoming credit was checked in the merchant bank account.', 'wpessential-bank-transfer-matcher' ), [ 'status' => 422 ] );
		}
		try {
			$amount = Money::from_decimal( (string) $request['amount'], $order->get_currency(), wc_get_price_decimals() );
			$total = Money::from_decimal( (string) $order->get_total( 'edit' ), $order->get_currency(), wc_get_price_decimals() );
			if ( $amount->minor_units <= 0 || $total->minor_units <= 0 ) {
				throw new InvalidArgumentException( 'A positive amount is required.' );
			}
		} catch ( InvalidArgumentException|\OverflowException $error ) {
			return new WP_Error( 'wbm_invalid_amount', __( 'Enter an amount within the order currency precision.', 'wpessential-bank-transfer-matcher' ), [ 'status' => 422 ] );
		}
		global $wpdb;
		try {
			return ( new AllocationRepository( $wpdb ) )->allocate( (int) $order->get_id(), (int) $request['evidence_id'], get_current_user_id(), $total, $amount, sanitize_textarea_field( (string) $request['reason'] ) );
		} catch ( InvalidArgumentException $error ) {
			return new WP_Error( 'wbm_allocation_conflict', $error->getMessage(), [ 'status' => 409 ] );
		} catch ( RuntimeException $error ) {
			return new WP_Error( 'wbm_allocation_unavailable', __( 'Allocation could not be saved. Please retry.', 'wpessential-bank-transfer-matcher' ), [ 'status' => 503 ] );
		}
	}

	public static function finalize( WP_REST_Request $request ): array|WP_Error {
		if ( true !== $request['acknowledged'] ) {
			return new WP_Error( 'wbm_acknowledgement_required', __( 'Confirm the cleared credits and full order balance before completing payment.', 'wpessential-bank-transfer-matcher' ), [ 'status' => 422 ] );
		}
		global $wpdb;
		try {
			return ( new PaymentFinalizer( $wpdb ) )->complete( (int) $request['order_id'], get_current_user_id() );
		} catch ( InvalidArgumentException $error ) {
			return new WP_Error( 'wbm_finalization_conflict', $error->getMessage(), [ 'status' => 409 ] );
		} catch ( RuntimeException $error ) {
			return new WP_Error( 'wbm_finalization_unavailable', __( 'Payment completion needs review or retry; the allocation remains recorded.', 'wpessential-bank-transfer-matcher' ), [ 'status' => 503 ] );
		}
	}
}
