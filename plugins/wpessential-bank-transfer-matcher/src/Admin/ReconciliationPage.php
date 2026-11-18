<?php

namespace WPEssential\BankTransferMatcher\Admin;

use WC_Order;
use WPEssential\BankTransferMatcher\Access\Capabilities;

defined( 'ABSPATH' ) || exit;

final class ReconciliationPage {
	public static function render(): void {
		if ( ! current_user_can( Capabilities::VIEW ) ) {
			wp_die( esc_html__( 'You do not have permission to view this reconciliation.', 'wpessential-bank-transfer-matcher' ), '', array( 'response' => 403 ) );
		}
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || 'bacs' !== $order->get_payment_method() ) {
			wp_die( esc_html__( 'A bank transfer order is required.', 'wpessential-bank-transfer-matcher' ), '', array( 'response' => 404 ) );
		}
		global $wpdb;
		try {
			$summary = ( new OrderSummary( $wpdb ) )->get( $order );
		} catch ( \InvalidArgumentException|\OverflowException|\RuntimeException $error ) {
			echo '<div class="wrap wbm-admin"><h1>' . esc_html__( 'Reconciliation unavailable', 'wpessential-bank-transfer-matcher' ) . '</h1><div class="notice notice-error"><p>' . esc_html__( 'The order reconciliation state could not be loaded. Check storage health, then retry.', 'wpessential-bank-transfer-matcher' ) . '</p></div><p><a class="button" href="' . esc_url( add_query_arg( array( 'page' => 'wpessential-bank-transfer-matcher', 'wbm_view' => 'order', 'order_id' => $order_id ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Retry', 'wpessential-bank-transfer-matcher' ) . '</a></p></div>';
			return;
		}
		$money = $summary['total'];
		$claim = self::claim( $wpdb, $order_id );
		$evidence = self::evidence( $wpdb, $order_id );
		echo '<div class="wrap wbm-admin"><h1>' . esc_html( sprintf( __( 'Reconcile order #%s', 'wpessential-bank-transfer-matcher' ), $order->get_order_number() ) ) . '</h1>';
		printf( '<p><a href="%s">%s</a> · <a href="%s">%s</a></p>', esc_url( add_query_arg( 'page', 'wpessential-bank-transfer-matcher', admin_url( 'admin.php' ) ) ), esc_html__( 'Bank transfer orders', 'wpessential-bank-transfer-matcher' ), esc_url( $order->get_edit_order_url() ), esc_html__( 'Open WooCommerce order', 'wpessential-bank-transfer-matcher' ) );
		if ( isset( $_GET['wbm_notice'] ) && 'saved' === sanitize_key( wp_unslash( $_GET['wbm_notice'] ) ) ) echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Action completed. The reconciliation state below is current.', 'wpessential-bank-transfer-matcher' ) . '</p></div>';
		echo '<div class="wbm-kpis">';
		self::stat( __( 'Order total', 'wpessential-bank-transfer-matcher' ), OrdersPage::money( $money->minor_units, $money->currency, $money->exponent ) );
		self::stat( __( 'Verified allocation', 'wpessential-bank-transfer-matcher' ), OrdersPage::money( $summary['allocated'], $money->currency, $money->exponent ) );
		self::stat( __( 'Outstanding', 'wpessential-bank-transfer-matcher' ), OrdersPage::money( $summary['outstanding'], $money->currency, $money->exponent ) );
		self::stat( __( 'Payment reference', 'wpessential-bank-transfer-matcher' ), $summary['reference'] ?? '—' );
		echo '</div>';
		self::claim_panel( $order, $claim );
		self::evidence_panel( $order, $evidence );
		self::allocation_panel( $order, $evidence, $summary['outstanding'], $money->currency, $money->exponent );
		self::finalize_panel( $order, $summary['outstanding'] );
		try { ( new AuditTimeline( $wpdb ) )->render( $order_id ); } catch ( \RuntimeException $error ) { echo '<p role="alert">' . esc_html__( 'Recent activity is unavailable. Retry this page.', 'wpessential-bank-transfer-matcher' ) . '</p>'; }
		echo '</div>';
	}

	private static function claim( \wpdb $db, int $order_id ): ?array {
		if ( ! in_array( (string) get_option( 'wpessential_wbm_claim_schema' ), array( '1', '2' ), true ) ) return null;
		$row = $db->get_row( $db->prepare( "SELECT status, transfer_date, amount_minor, currency, exponent, transfer_reference, sender_name, customer_note, created_at, attachment_mime FROM `{$db->prefix}wbm_payment_claims` WHERE order_id = %d", $order_id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	private static function evidence( \wpdb $db, int $order_id ): array {
		if ( '1' !== (string) get_option( 'wpessential_wbm_evidence_schema' ) ) return array();
		$rows = $db->get_results( $db->prepare( "SELECT id, transfer_date, amount_minor, currency, exponent, reference, created_at FROM `{$db->prefix}wbm_bank_evidence` WHERE order_id = %d ORDER BY id DESC", $order_id ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	private static function claim_panel( WC_Order $order, ?array $claim ): void {
		echo '<section class="wbm-panel"><h2>' . esc_html__( 'Customer claim', 'wpessential-bank-transfer-matcher' ) . '</h2>';
		if ( ! $claim ) { echo '<p class="wbm-empty">' . esc_html__( 'No customer payment claim has been submitted for this order.', 'wpessential-bank-transfer-matcher' ) . '</p></section>'; return; }
		printf( '<dl class="wbm-details"><dt>%s</dt><dd>%s</dd><dt>%s</dt><dd>%s</dd><dt>%s</dt><dd>%s</dd><dt>%s</dt><dd>%s</dd></dl>', esc_html__( 'Status', 'wpessential-bank-transfer-matcher' ), esc_html( $claim['status'] ), esc_html__( 'Declared date', 'wpessential-bank-transfer-matcher' ), esc_html( $claim['transfer_date'] ), esc_html__( 'Claimed amount', 'wpessential-bank-transfer-matcher' ), esc_html( OrdersPage::money( (int) $claim['amount_minor'], $claim['currency'], (int) $claim['exponent'] ) ), esc_html__( 'Customer reference', 'wpessential-bank-transfer-matcher' ), esc_html( $claim['transfer_reference'] ?: '—' ) );
		if ( current_user_can( Capabilities::REVIEW_CLAIM ) ) {
			self::form_start( sprintf( '/wpessential-wbm/v1/orders/%d/claim/review', $order->get_id() ), __( 'Review claim', 'wpessential-bank-transfer-matcher' ) );
			echo '<label>' . esc_html__( 'Decision', 'wpessential-bank-transfer-matcher' ) . '<select name="status"><option value="under_review">' . esc_html__( 'Under review', 'wpessential-bank-transfer-matcher' ) . '</option><option value="rejected">' . esc_html__( 'Rejected', 'wpessential-bank-transfer-matcher' ) . '</option>';
			if ( current_user_can( Capabilities::APPROVE ) ) echo '<option value="verified">' . esc_html__( 'Claim verified only (does not mark order paid)', 'wpessential-bank-transfer-matcher' ) . '</option>';
			echo '</select></label><label>' . esc_html__( 'Reviewer comment', 'wpessential-bank-transfer-matcher' ) . '<textarea name="comment" minlength="3" maxlength="1000" required></textarea></label><label><input type="checkbox" name="acknowledged" value="true"> ' . esc_html__( 'I understand claim verification does not complete WooCommerce payment.', 'wpessential-bank-transfer-matcher' ) . '</label>';
			self::form_end( __( 'Save claim review', 'wpessential-bank-transfer-matcher' ) );
		}
		echo '</section>';
	}

	private static function evidence_panel( WC_Order $order, array $evidence ): void {
		echo '<section class="wbm-panel"><h2>' . esc_html__( 'Merchant bank evidence', 'wpessential-bank-transfer-matcher' ) . '</h2>';
		if ( $evidence ) { echo '<ul class="wbm-records">'; foreach ( $evidence as $row ) printf( '<li><strong>#%d</strong> · %s · %s · %s</li>', (int) $row['id'], esc_html( $row['transfer_date'] ), esc_html( OrdersPage::money( (int) $row['amount_minor'], $row['currency'], (int) $row['exponent'] ) ), esc_html( $row['reference'] ) ); echo '</ul>'; } else echo '<p class="wbm-empty">' . esc_html__( 'No merchant bank evidence has been recorded.', 'wpessential-bank-transfer-matcher' ) . '</p>';
		if ( current_user_can( Capabilities::REVIEW_CLAIM ) && ! $order->is_paid() ) {
			self::form_start( sprintf( '/wpessential-wbm/v1/orders/%d/bank-evidence', $order->get_id() ), __( 'Add bank evidence', 'wpessential-bank-transfer-matcher' ) );
			echo '<label>' . esc_html__( 'Cleared date', 'wpessential-bank-transfer-matcher' ) . '<input type="date" name="date" max="' . esc_attr( wp_date( 'Y-m-d' ) ) . '" required></label><label>' . esc_html( sprintf( __( 'Cleared amount (%s)', 'wpessential-bank-transfer-matcher' ), $order->get_currency() ) ) . '<input type="text" inputmode="decimal" name="amount" required></label><label>' . esc_html__( 'Bank transaction reference', 'wpessential-bank-transfer-matcher' ) . '<input type="text" name="reference" maxlength="100" required></label><label>' . esc_html__( 'Evidence note', 'wpessential-bank-transfer-matcher' ) . '<textarea name="note" maxlength="1000"></textarea></label>';
			self::form_end( __( 'Record bank evidence', 'wpessential-bank-transfer-matcher' ) );
		}
		echo '</section>';
	}

	private static function allocation_panel( WC_Order $order, array $evidence, int $outstanding, string $currency, int $exponent ): void {
		if ( ! current_user_can( Capabilities::APPROVE ) || $order->is_paid() ) return;
		echo '<section class="wbm-panel"><h2>' . esc_html__( 'Apply verified allocation', 'wpessential-bank-transfer-matcher' ) . '</h2>';
		if ( ! $evidence ) { echo '<p class="wbm-empty">' . esc_html__( 'Record merchant bank evidence before applying an allocation.', 'wpessential-bank-transfer-matcher' ) . '</p></section>'; return; }
		self::form_start( sprintf( '/wpessential-wbm/v1/orders/%d/allocations', $order->get_id() ), __( 'Apply allocation', 'wpessential-bank-transfer-matcher' ) );
		echo '<label>' . esc_html__( 'Evidence record', 'wpessential-bank-transfer-matcher' ) . '<select name="evidence_id">'; foreach ( $evidence as $row ) printf( '<option value="%d">#%d · %s</option>', (int) $row['id'], (int) $row['id'], esc_html( $row['reference'] ) ); echo '</select></label><label>' . esc_html( sprintf( __( 'Allocation amount (%s)', 'wpessential-bank-transfer-matcher' ), $currency ) ) . '<input type="text" inputmode="decimal" name="amount" value="' . esc_attr( self::decimal( $outstanding, $exponent ) ) . '" required></label><label>' . esc_html__( 'Approval reason', 'wpessential-bank-transfer-matcher' ) . '<textarea name="reason" minlength="3" maxlength="1000" required></textarea></label><label><input type="checkbox" name="acknowledged" value="true" required> ' . esc_html__( 'I checked the merchant bank account and confirmed a cleared incoming credit.', 'wpessential-bank-transfer-matcher' ) . '</label>';
		self::form_end( __( 'Verify and apply allocation', 'wpessential-bank-transfer-matcher' ) ); echo '</section>';
	}

	private static function finalize_panel( WC_Order $order, int $outstanding ): void {
		if ( ! current_user_can( Capabilities::APPROVE ) || $order->is_paid() || 0 !== $outstanding ) return;
		echo '<section class="wbm-panel"><h2>' . esc_html__( 'Complete WooCommerce payment', 'wpessential-bank-transfer-matcher' ) . '</h2><p>' . esc_html__( 'The internal ledger is fully allocated. This separate action changes the WooCommerce payment state.', 'wpessential-bank-transfer-matcher' ) . '</p>';
		self::form_start( sprintf( '/wpessential-wbm/v1/orders/%d/finalize-payment', $order->get_id() ), __( 'Complete payment', 'wpessential-bank-transfer-matcher' ) ); echo '<label><input type="checkbox" name="acknowledged" value="true" required> ' . esc_html__( 'I confirm the cleared credits cover the full order balance.', 'wpessential-bank-transfer-matcher' ) . '</label>'; self::form_end( __( 'Complete payment', 'wpessential-bank-transfer-matcher' ) ); echo '</section>';
	}

	private static function form_start( string $endpoint, string $label ): void { printf( '<form class="wbm-api-form" data-endpoint="%s" aria-label="%s">', esc_attr( $endpoint ), esc_attr( $label ) ); }
	private static function form_end( string $button ): void { printf( '<p><button class="button button-primary" type="submit">%s</button> <span class="spinner" aria-hidden="true"></span></p><p class="wbm-form-message" role="status" aria-live="polite"></p></form>', esc_html( $button ) ); }
	private static function stat( string $label, string $value ): void { printf( '<section class="wbm-kpi"><h3>%s</h3><p>%s</p></section>', esc_html( $label ), esc_html( $value ) ); }
	private static function decimal( int $minor, int $exponent ): string { if ( 0 === $exponent ) return (string) $minor; $digits = str_pad( (string) $minor, $exponent + 1, '0', STR_PAD_LEFT ); return substr( $digits, 0, -$exponent ) . '.' . substr( $digits, -$exponent ); }
}
