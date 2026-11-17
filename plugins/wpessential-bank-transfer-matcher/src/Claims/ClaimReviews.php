<?php

namespace WPEssential\BankTransferMatcher\Claims;

use InvalidArgumentException;
use RuntimeException;
use WPEssential\BankTransferMatcher\Access\Capabilities;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

final class ClaimReviews {
	public static function register(): void {
		add_action( 'rest_api_init', static function (): void {
			register_rest_route( 'wpessential-wbm/v1', '/orders/(?P<order_id>\d+)/claim/review', [
				'methods' => WP_REST_Server::CREATABLE,
				'permission_callback' => [ self::class, 'can_review' ],
				'callback' => [ self::class, 'review' ],
				'args' => [
					'order_id' => [ 'required' => true, 'type' => 'integer', 'minimum' => 1 ],
					'status' => [ 'required' => true, 'type' => 'string', 'enum' => [ 'under_review', 'verified', 'rejected' ] ],
					'comment' => [ 'required' => true, 'type' => 'string', 'minLength' => 3, 'maxLength' => 1000 ],
					'acknowledged' => [ 'required' => false, 'type' => 'boolean' ],
				],
			] );
			register_rest_route( 'wpessential-wbm/v1', '/orders/(?P<order_id>\d+)/claim/notifications/(?P<event_id>\d+)/retry', [
				'methods' => WP_REST_Server::CREATABLE,
				'permission_callback' => [ self::class, 'can_review' ],
				'callback' => [ self::class, 'retry_notification' ],
				'args' => [
					'order_id' => [ 'required' => true, 'type' => 'integer', 'minimum' => 1 ],
					'event_id' => [ 'required' => true, 'type' => 'integer', 'minimum' => 1 ],
				],
			] );
		} );
	}

	public static function can_review(): bool|WP_Error {
		return current_user_can( Capabilities::REVIEW_CLAIM ) ? true : new WP_Error( 'wbm_forbidden', __( 'Claim review permission is required.', 'wpessential-bank-transfer-matcher' ), [ 'status' => rest_authorization_required_code() ] );
	}

	public static function review( WP_REST_Request $request ): array|WP_Error {
		$status = (string) $request['status'];
		if ( 'verified' === $status && ! current_user_can( Capabilities::APPROVE ) ) {
			return new WP_Error( 'wbm_forbidden', __( 'Claim verification requires payment approval permission.', 'wpessential-bank-transfer-matcher' ), [ 'status' => rest_authorization_required_code() ] );
		}
		$order = wc_get_order( (int) $request['order_id'] );
		if ( ! $order || 'bacs' !== $order->get_payment_method() ) {
			return new WP_Error( 'wbm_ineligible_order', __( 'A bank transfer order is required.', 'wpessential-bank-transfer-matcher' ), [ 'status' => 422 ] );
		}
		global $wpdb;
		$repository = new ClaimReviewRepository( $wpdb );
		try {
			$event = $repository->transition( $order->get_id(), get_current_user_id(), $status,
				sanitize_textarea_field( (string) $request['comment'] ), true === ( $request['acknowledged'] ?? false ) );
		} catch ( InvalidArgumentException $error ) {
			return new WP_Error( 'wbm_claim_review_conflict', $error->getMessage(), [ 'status' => 409 ] );
		} catch ( RuntimeException $error ) {
			return new WP_Error( 'wbm_claim_review_unavailable', __( 'Claim review storage is unavailable.', 'wpessential-bank-transfer-matcher' ), [ 'status' => 503 ] );
		}
		// The decision was committed. Notification delivery must not turn its response
		// into an ambiguous failure that prompts the reviewer to repeat the decision.
		try {
			$event['notification'] = self::deliver( $repository, $order, $event['event_id'] );
		} catch ( RuntimeException $error ) {
			$event['notification'] = 'pending';
		}
		return $event;
	}

	public static function retry_notification( WP_REST_Request $request ): array|WP_Error {
		$order = wc_get_order( (int) $request['order_id'] );
		if ( ! $order ) {
			return new WP_Error( 'wbm_ineligible_order', __( 'Order not found.', 'wpessential-bank-transfer-matcher' ), [ 'status' => 404 ] );
		}
		global $wpdb;
		try {
			return [ 'notification' => self::deliver( new ClaimReviewRepository( $wpdb ), $order, (int) $request['event_id'] ) ];
		} catch ( InvalidArgumentException $error ) {
			return new WP_Error( 'wbm_notification_missing', __( 'Claim review event not found.', 'wpessential-bank-transfer-matcher' ), [ 'status' => 404 ] );
		} catch ( RuntimeException $error ) {
			return new WP_Error( 'wbm_notification_unavailable', __( 'Notification storage is unavailable.', 'wpessential-bank-transfer-matcher' ), [ 'status' => 503 ] );
		}
	}

	private static function deliver( ClaimReviewRepository $repository, \WC_Order $order, int $event_id ): string {
		global $wpdb;
		$lock = hash( 'sha256', DB_NAME . '/' . $wpdb->prefix . '/wbm-claim-email/' . $event_id );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) {
			return 'pending';
		}
		try {
			return self::send_locked( $repository, $order, $event_id );
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	private static function send_locked( ClaimReviewRepository $repository, \WC_Order $order, int $event_id ): string {
		$event = $repository->pending_event( $order->get_id(), $event_id );
		if ( ! $event ) {
			throw new InvalidArgumentException( 'Review event not found.' );
		}
		if ( 'sent' === $event['notification'] ) {
			return 'sent';
		}
		$email = $order->get_billing_email();
		if ( ! is_email( $email ) ) {
			return 'pending';
		}
		$messages = [
			'under_review' => __( 'Your bank transfer claim is being reviewed. This does not confirm payment.', 'wpessential-bank-transfer-matcher' ),
			'verified' => __( 'Your bank transfer claim has been verified against merchant bank evidence. Check your order for its current payment status.', 'wpessential-bank-transfer-matcher' ),
			'rejected' => __( 'Your bank transfer claim could not be verified. Please contact the store if you need help.', 'wpessential-bank-transfer-matcher' ),
		];
		$subject = sprintf( __( 'Update on order #%s', 'wpessential-bank-transfer-matcher' ), $order->get_order_number() );
		if ( wp_mail( $email, $subject, $messages[ $event['status'] ] ?? '' ) ) {
			$repository->mark_notified( $event_id );
			return 'sent';
		}
		return 'pending';
	}
}
