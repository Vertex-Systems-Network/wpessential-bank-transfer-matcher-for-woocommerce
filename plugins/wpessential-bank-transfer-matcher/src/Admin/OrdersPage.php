<?php

namespace WPEssential\BankTransferMatcher\Admin;

use RuntimeException;
use WC_Order;
use WPEssential\BankTransferMatcher\Access\Capabilities;

defined( 'ABSPATH' ) || exit;

final class OrdersPage {
	public static function render(): void {
		if ( ! current_user_can( Capabilities::VIEW ) ) {
			wp_die( esc_html__( 'You do not have permission to view bank transfer orders.', 'wpessential-bank-transfer-matcher' ), '', [ 'response' => 403 ] );
		}
		$status = isset( $_GET['wbm_status'] ) && is_string( $_GET['wbm_status'] ) ? sanitize_key( wp_unslash( $_GET['wbm_status'] ) ) : 'eligible';
		if ( ! in_array( $status, [ 'eligible', 'on-hold', 'pending' ], true ) ) {
			$status = 'eligible';
		}
		$page = isset( $_GET['paged'] ) ? max( 1, min( 10000, absint( $_GET['paged'] ) ) ) : 1;
		$statuses = 'eligible' === $status ? [ 'on-hold', 'pending' ] : [ $status ];
		$results = wc_get_orders( [
			'payment_method' => 'bacs', 'status' => $statuses, 'limit' => 20,
			'page' => $page, 'paginate' => true, 'orderby' => 'date', 'order' => 'DESC',
		] );
		if ( ! is_object( $results ) || ! isset( $results->orders, $results->max_num_pages ) ) {
			throw new RuntimeException( 'WooCommerce order query is unavailable.' );
		}
		echo '<div class="wrap wbm-admin">';
		Components::heading( __( 'Bank Transfer Matcher', 'wpessential-bank-transfer-matcher' ) );
		printf( '<p>%s</p>', esc_html__( 'Unpaid native bank transfer orders. A customer claim or manual record does not by itself confirm payment.', 'wpessential-bank-transfer-matcher' ) );
		global $wpdb;
		$eligible_count = (int) ( 'eligible' === $status ? $results->total : wc_get_orders( [
			'payment_method' => 'bacs', 'status' => [ 'on-hold', 'pending' ], 'limit' => 1, 'paginate' => true, 'return' => 'ids',
		] )->total );
		try {
			( new Overview( $wpdb ) )->render( $eligible_count, $results->orders );
		} catch ( RuntimeException $error ) {
			echo '<p role="alert">' . esc_html__( 'Overview data is unavailable; the order list remains available.', 'wpessential-bank-transfer-matcher' ) . '</p>';
		}
		echo '<form method="get" class="wbm-orders-filter"><input type="hidden" name="page" value="wpessential-bank-transfer-matcher">';
		echo '<label for="wbm-status">' . esc_html__( 'Order status', 'wpessential-bank-transfer-matcher' ) . '</label> ';
		echo '<select id="wbm-status" name="wbm_status">';
		foreach ( [ 'eligible' => __( 'Eligible: on hold and pending', 'wpessential-bank-transfer-matcher' ), 'on-hold' => __( 'On hold', 'wpessential-bank-transfer-matcher' ), 'pending' => __( 'Pending payment', 'wpessential-bank-transfer-matcher' ) ] as $value => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $value ), selected( $status, $value, false ), esc_html( $label ) );
		}
		echo '</select> <button type="submit" class="button">' . esc_html__( 'Filter', 'wpessential-bank-transfer-matcher' ) . '</button></form>';
		if ( ! $results->orders ) {
			printf( '<p role="status">%s</p>', esc_html__( 'No bank transfer orders match this status.', 'wpessential-bank-transfer-matcher' ) );
		} else {
			echo '<div class="wbm-table-scroll"><table class="wp-list-table widefat fixed striped"><thead><tr>';
			foreach ( [ __( 'Order', 'wpessential-bank-transfer-matcher' ), __( 'Date', 'wpessential-bank-transfer-matcher' ), __( 'Customer', 'wpessential-bank-transfer-matcher' ), __( 'Payment reference', 'wpessential-bank-transfer-matcher' ), __( 'Total', 'wpessential-bank-transfer-matcher' ), __( 'Allocated', 'wpessential-bank-transfer-matcher' ), __( 'Outstanding', 'wpessential-bank-transfer-matcher' ), __( 'Claim', 'wpessential-bank-transfer-matcher' ), __( 'Reconciliation', 'wpessential-bank-transfer-matcher' ) ] as $heading ) {
				printf( '<th scope="col">%s</th>', esc_html( $heading ) );
			}
			echo '</tr></thead><tbody>';
			$summary = new OrderSummary( $wpdb );
			foreach ( $results->orders as $order ) {
				self::row( $order, $summary );
			}
			echo '</tbody></table></div>';
		}
		$pages = (int) $results->max_num_pages;
		if ( $pages > 1 ) {
			$base = add_query_arg( [ 'page' => 'wpessential-bank-transfer-matcher', 'wbm_status' => $status, 'paged' => '%#%' ], admin_url( 'admin.php' ) );
			echo '<nav class="wbm-pagination" aria-label="' . esc_attr__( 'Bank transfer order pages', 'wpessential-bank-transfer-matcher' ) . '">';
			echo wp_kses_post( paginate_links( [ 'base' => $base, 'format' => '', 'current' => $page, 'total' => $pages, 'type' => 'plain' ] ) );
			echo '</nav>';
		}
		echo '</div>';
	}

	private static function row( WC_Order $order, OrderSummary $summary ): void {
		try {
			$data = $summary->get( $order );
		} catch ( \InvalidArgumentException|\OverflowException|RuntimeException $error ) {
			printf( '<tr><td><a href="%s">#%s</a></td><td colspan="8">%s</td></tr>', esc_url( $order->get_edit_order_url() ), esc_html( $order->get_order_number() ), esc_html__( 'Reconciliation summary unavailable; review this order.', 'wpessential-bank-transfer-matcher' ) );
			return;
		}
		$money = $data['total'];
		$customer = trim( $order->get_formatted_billing_full_name() );
		$customer = '' !== $customer ? $customer : __( 'Guest', 'wpessential-bank-transfer-matcher' );
		$state = $data['mismatch'] ? __( 'Balance exception', 'wpessential-bank-transfer-matcher' ) : ( 0 === $data['outstanding'] ? __( 'Fully allocated', 'wpessential-bank-transfer-matcher' ) : ( $data['allocated'] > 0 ? __( 'Partial', 'wpessential-bank-transfer-matcher' ) : __( 'Unallocated', 'wpessential-bank-transfer-matcher' ) ) );
		printf( '<tr><td><a href="%s">#%s</a></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
			esc_url( $order->get_edit_order_url() ), esc_html( $order->get_order_number() ),
			esc_html( $order->get_date_created()?->date_i18n( get_option( 'date_format' ) ) ?? '—' ), esc_html( $customer ),
			esc_html( $data['reference'] ?? '—' ), esc_html( self::money( $money->minor_units, $money->currency, $money->exponent ) ),
			esc_html( self::money( $data['allocated'], $money->currency, $money->exponent ) ),
			esc_html( self::money( $data['outstanding'], $money->currency, $money->exponent ) ),
			esc_html( $data['claim_status'] ?? '—' ), esc_html( $state )
		);
	}

	public static function money( int $minor, string $currency, int $exponent ): string {
		$negative = $minor < 0 ? '-' : '';
		$digits = ltrim( (string) $minor, '-' );
		if ( 0 === $exponent ) {
			return $negative . $digits . ' ' . $currency;
		}
		$digits = str_pad( $digits, $exponent + 1, '0', STR_PAD_LEFT );
		return $negative . substr( $digits, 0, -$exponent ) . '.' . substr( $digits, -$exponent ) . ' ' . $currency;
	}
}
