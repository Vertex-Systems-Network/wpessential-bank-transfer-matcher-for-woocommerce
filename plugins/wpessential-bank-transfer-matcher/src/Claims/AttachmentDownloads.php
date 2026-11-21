<?php

namespace WPEssential\BankTransferMatcher\Claims;

use RuntimeException;
use WC_Order;
use WPEssential\BankTransferMatcher\Access\Capabilities;

final class AttachmentDownloads {
	private const ACTION = 'wpessential_wbm_download_receipt';
	public const CRON = 'wpessential_wbm_purge_claim_receipts';

	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'download' ) );
		add_action( 'woocommerce_admin_order_data_after_order_details', array( self::class, 'order_link' ) );
		add_action( 'init', array( self::class, 'schedule' ) );
		add_action( self::CRON, array( self::class, 'purge' ) );
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::CRON );
		}
	}

	public static function purge(): void {
		global $wpdb;
		try {
			( new ClaimRepository( $wpdb ) )->purge_expired_attachments();
		} catch ( RuntimeException ) {
			wc_get_logger()->error( 'Payment receipt cleanup failed.', [ 'source' => 'wpessential-bank-transfer-matcher' ] );
		}
	}

	public static function order_link( WC_Order $order ): void {
		if ( ! current_user_can( Capabilities::REVIEW_CLAIM ) ) {
			return;
		}
		global $wpdb;
		$id = $order->get_id();
		try {
			if ( ! ( new ClaimRepository( $wpdb ) )->has_attachment( $id ) ) {
				return;
			}
		} catch ( RuntimeException ) {
			return;
		}
		$url = add_query_arg( [ 'action' => self::ACTION, 'order_id' => $id ], admin_url( 'admin-post.php' ) );
		printf( '<p><a href="%s">%s</a></p>',
			esc_url( wp_nonce_url( $url, self::ACTION . ':' . $id ) ),
			esc_html__( 'Download customer receipt', 'wpessential-bank-transfer-matcher' )
		);
	}

	public static function authorized( int $order_id, string $nonce ): bool {
		return $order_id > 0 && current_user_can( Capabilities::REVIEW_CLAIM ) &&
			(bool) wp_verify_nonce( $nonce, self::ACTION . ':' . $order_id );
	}

	public static function download(): void {
		$id = is_scalar( $_GET['order_id'] ?? null ) ? absint( $_GET['order_id'] ) : 0;
		$nonce = is_string( $_GET['_wpnonce'] ?? null ) ? wp_unslash( $_GET['_wpnonce'] ) : '';
		if ( ! self::authorized( $id, $nonce ) ) {
			wp_die( esc_html__( 'You cannot download this receipt.', 'wpessential-bank-transfer-matcher' ), '', [ 'response' => 403 ] );
		}

		global $wpdb;
		try {
			$attachment = ( new ClaimRepository( $wpdb ) )->attachment( $id );
		} catch ( RuntimeException ) {
			wp_die( esc_html__( 'Receipt storage is unavailable.', 'wpessential-bank-transfer-matcher' ), '', [ 'response' => 503 ] );
		}
		$extension = ClaimAttachment::extension( $attachment['attachment_mime'] ?? '' );
		if ( null === $extension ) {
			wp_die( esc_html__( 'Receipt not available.', 'wpessential-bank-transfer-matcher' ), '', [ 'response' => 404 ] );
		}

		$bytes = $attachment['attachment_bytes'];
		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: attachment; filename="payment-receipt.' . $extension . '"' );
		header( 'Content-Length: ' . strlen( $bytes ) );
		// Binary application/octet-stream receipt: HTML escaping corrupts the protected downloadable bytes.
		echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Capability-, nonce- and MIME-validated raw attachment response, not HTML.
		exit;
	}
}
