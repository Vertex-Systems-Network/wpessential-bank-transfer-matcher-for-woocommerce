<?php

namespace WPEssential\BankTransferMatcher\Orders;

use RuntimeException;
use WC_Email;
use WC_Order;
use WPEssential\BankTransferMatcher\Claims\CustomerClaims;
use WPEssential\BankTransferMatcher\Domain\EntityId;

final class ReferencePresentation {
	public static function register(): void {
		add_action( 'woocommerce_thankyou_bacs', array( self::class, 'thank_you' ), 20 );
		add_action( 'woocommerce_view_order', array( self::class, 'my_account' ), 15 );
		add_action( 'woocommerce_email_before_order_table', array( self::class, 'email' ), 20, 4 );
	}

	public static function thank_you( int $order_id ): void {
		$order = wc_get_order( $order_id );
		$key = $_GET['key'] ?? null; // Order-received key is checked by WooCommerce and verified again here.
		if ( ! $order instanceof WC_Order || ! is_string( $key ) || '' === $key ||
			! hash_equals( $order->get_order_key(), wc_clean( wp_unslash( $key ) ) ) ||
			( $order->get_customer_id() && $order->get_customer_id() !== get_current_user_id() ) ) {
			return;
		}
		self::render( $order );
		CustomerClaims::render( $order );
	}

	public static function my_account( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || ! is_user_logged_in() ||
			$order->get_customer_id() !== get_current_user_id() ) {
			return;
		}
		self::render( $order, false, true );
		CustomerClaims::render( $order );
	}

	public static function email( mixed $order, bool $sent_to_admin, bool $plain_text, ?WC_Email $email = null ): void {
		if ( ! $order instanceof WC_Order || $sent_to_admin || 'customer_on_hold_order' !== $email?->id || ! $order->has_status( 'on-hold' ) ) {
			return;
		}
		self::render( $order, $plain_text );
	}

	private static function render( WC_Order $order, bool $plain_text = false, bool $show_instructions = false ): void {
		if ( PaymentMethods::BACS !== $order->get_payment_method() || ! $order->get_id() ) {
			return;
		}

		global $wpdb;
		try {
			$reference = ( new ReferenceRepository( $wpdb ) )->find( new EntityId( $order->get_id() ) );
		} catch ( RuntimeException ) {
			return;
		}
		if ( null === $reference ) {
			return;
		}

		$label = __( 'Payment reference', 'wpessential-bank-transfer-matcher' );
		$hint = __( 'Use this exact reference when transferring.', 'wpessential-bank-transfer-matcher' );
		$awaiting_transfer = $order->has_status( array( 'pending', 'on-hold' ) );
		if ( $plain_text ) {
			printf( "%s: %s\n", esc_html( $label ), esc_html( $reference->value ) );
			if ( $awaiting_transfer ) {
				echo esc_html( $hint ) . "\n";
			}
			return;
		}

		printf( '<p class="wbm-payment-reference"><strong>%s</strong> <code>%s</code></p>', esc_html( $label ), esc_html( $reference->value ) );
		if ( $awaiting_transfer ) {
			printf( '<p>%s</p>', esc_html( $hint ) );
			if ( $show_instructions ) {
				echo wp_kses_post( wpautop( PaymentMethods::bacs_instructions() ) );
			}
		}
	}
}
