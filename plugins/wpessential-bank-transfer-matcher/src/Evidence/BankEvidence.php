<?php

namespace WPEssential\BankTransferMatcher\Evidence;

use RuntimeException;
use WPEssential\BankTransferMatcher\Access\Capabilities;
use WPEssential\BankTransferMatcher\Domain\Money;
use WPEssential\BankTransferMatcher\Orders\PaymentMethods;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;
use wpdb;

defined( 'ABSPATH' ) || exit;

/** Merchant-entered bank evidence is a review record, never proof of settlement by itself. */
final class BankEvidence {
	public static function register(): void {
		add_action( 'rest_api_init', static function (): void {
			register_rest_route( 'wpessential-wbm/v1', '/orders/(?P<order_id>\d+)/bank-evidence', [
				'methods' => WP_REST_Server::CREATABLE,
				'permission_callback' => static function (): bool|WP_Error {
					return current_user_can( Capabilities::REVIEW_CLAIM ) ? true : new WP_Error( 'wbm_forbidden', __( 'Bank evidence review permission is required.', 'wpessential-bank-transfer-matcher' ), [ 'status' => rest_authorization_required_code() ] );
				},
				'callback' => [ self::class, 'create' ],
				'args' => [
					'order_id' => [ 'required' => true, 'type' => 'integer', 'minimum' => 1 ],
					'date' => [ 'required' => true, 'type' => 'string', 'pattern' => '^\d{4}-\d{2}-\d{2}$' ],
					'amount' => [ 'required' => true, 'type' => 'string' ],
					'reference' => [ 'required' => true, 'type' => 'string', 'maxLength' => 100 ],
					'note' => [ 'required' => false, 'type' => 'string', 'maxLength' => 1000 ],
				],
			] );
		} );
	}

	public static function create( WP_REST_Request $request ): array|WP_Error {
		$order = wc_get_order( (int) $request['order_id'] );
		if ( ! $order || PaymentMethods::BACS !== $order->get_payment_method() || $order->is_paid() || in_array( $order->get_status(), [ 'cancelled', 'refunded', 'failed' ], true ) ) {
			return new WP_Error( 'wbm_ineligible_order', __( 'An unpaid bank transfer order is required.', 'wpessential-bank-transfer-matcher' ), [ 'status' => 422 ] );
		}
		$date = (string) $request['date'];
		$parts = explode( '-', $date );
		if ( 3 !== count( $parts ) || ! checkdate( (int) $parts[1], (int) $parts[2], (int) $parts[0] ) || $date > gmdate( 'Y-m-d' ) ) {
			return new WP_Error( 'wbm_invalid_date', __( 'Enter a valid transfer date that is not in the future.', 'wpessential-bank-transfer-matcher' ), [ 'status' => 422 ] );
		}
		$currency = $order->get_currency();
		$decimals = wc_get_price_decimals();
		try {
			$money = Money::from_decimal( (string) $request['amount'], $currency, $decimals );
		} catch ( \InvalidArgumentException|\OverflowException $error ) {
			return new WP_Error( 'wbm_invalid_amount', __( 'Enter a valid amount at store currency precision.', 'wpessential-bank-transfer-matcher' ), [ 'status' => 422 ] );
		}
		$reference = trim( (string) $request['reference'] );
		if ( $money->minor_units <= 0 || '' === $reference || strlen( $reference ) > 100 ) {
			return new WP_Error( 'wbm_invalid_evidence', __( 'A positive amount and bank reference are required.', 'wpessential-bank-transfer-matcher' ), [ 'status' => 422 ] );
		}
		global $wpdb;
		try {
			return ( new EvidenceRepository( $wpdb ) )->record( (int) $order->get_id(), get_current_user_id(), $date, $money, $reference, sanitize_textarea_field( (string) ( $request['note'] ?? '' ) ) );
		} catch ( RuntimeException $error ) {
			return new WP_Error( 'wbm_evidence_unavailable', __( 'Bank evidence could not be saved. Please retry.', 'wpessential-bank-transfer-matcher' ), [ 'status' => 503 ] );
		}
	}
}
