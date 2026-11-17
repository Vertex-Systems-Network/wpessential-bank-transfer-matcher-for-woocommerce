<?php

namespace WPEssential\BankTransferMatcher\Admin;

use RuntimeException;
use WC_Order;
use WPEssential\BankTransferMatcher\Access\Capabilities;

defined( 'ABSPATH' ) || exit;

final class OrderPanel {
	public static function register(): void {
		add_action( 'woocommerce_admin_order_data_after_order_details', [ self::class, 'render' ] );
	}

	public static function render( WC_Order $order ): void {
		if ( ! current_user_can( Capabilities::VIEW ) || 'bacs' !== $order->get_payment_method() ) {
			return;
		}
		global $wpdb;
		try {
			$data = ( new OrderSummary( $wpdb ) )->get( $order );
		} catch ( \InvalidArgumentException|\OverflowException|RuntimeException $error ) {
			printf( '<div class="wbm-order-panel"><h3>%s</h3><p role="alert">%s</p></div>', esc_html__( 'Bank Transfer Matcher', 'wpessential-bank-transfer-matcher' ), esc_html__( 'Reconciliation summary unavailable. Review the order and storage health.', 'wpessential-bank-transfer-matcher' ) );
			return;
		}
		$money = $data['total'];
		echo '<div class="wbm-order-panel"><h3>' . esc_html__( 'Bank Transfer Matcher', 'wpessential-bank-transfer-matcher' ) . '</h3>';
		printf( '<p><strong>%s</strong> %s</p>', esc_html__( 'Payment reference:', 'wpessential-bank-transfer-matcher' ), esc_html( $data['reference'] ?? '—' ) );
		printf( '<p><strong>%s</strong> %s · <strong>%s</strong> %s · <strong>%s</strong> %s</p>',
			esc_html__( 'Order total:', 'wpessential-bank-transfer-matcher' ), esc_html( OrdersPage::money( $money->minor_units, $money->currency, $money->exponent ) ),
			esc_html__( 'Allocated:', 'wpessential-bank-transfer-matcher' ), esc_html( OrdersPage::money( $data['allocated'], $money->currency, $money->exponent ) ),
			esc_html__( 'Outstanding:', 'wpessential-bank-transfer-matcher' ), esc_html( OrdersPage::money( $data['outstanding'], $money->currency, $money->exponent ) )
		);
		printf( '<p><strong>%s</strong> %s · <strong>%s</strong> %d</p>',
			esc_html__( 'Claim status:', 'wpessential-bank-transfer-matcher' ), esc_html( $data['claim_status'] ?? '—' ),
			esc_html__( 'Manual evidence records:', 'wpessential-bank-transfer-matcher' ), $data['evidence_count']
		);
		if ( $data['mismatch'] ) {
			printf( '<p role="alert"><strong>%s</strong></p>', esc_html__( 'WooCommerce payment state and verified allocations disagree. Review before taking further action.', 'wpessential-bank-transfer-matcher' ) );
		}
		$url = add_query_arg( [ 'page' => 'wpessential-bank-transfer-matcher' ], admin_url( 'admin.php' ) );
		printf( '<p><a class="button" href="%s">%s</a></p>', esc_url( $url ), esc_html__( 'View bank transfer orders', 'wpessential-bank-transfer-matcher' ) );
		echo '</div>';
	}
}
