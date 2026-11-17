<?php

use Automattic\WooCommerce\Utilities\OrderUtil;
use WPEssential\BankTransferMatcher\Admin\OrderPanel;
use WPEssential\BankTransferMatcher\Bootstrap\Plugin;
use WPEssential\BankTransferMatcher\Claims\ClaimRepository;
use WPEssential\BankTransferMatcher\Claims\ClaimReviewRepository;
use WPEssential\BankTransferMatcher\Domain\Money;
use WPEssential\BankTransferMatcher\Evidence\AllocationRepository;
use WPEssential\BankTransferMatcher\Evidence\EvidenceRepository;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'wbm_ci' !== DB_NAME ) {
	throw new RuntimeException( 'Run against the disposable wbm_ci database.' );
}
$mode = $args[0] ?? '';
if ( ! in_array( $mode, [ 'classic', 'hpos' ], true ) || OrderUtil::custom_orders_table_usage_is_enabled() !== ( 'hpos' === $mode ) ) {
	throw new RuntimeException( 'Overview test requires the requested storage mode.' );
}
global $wpdb;
wp_set_current_user( get_user_by( 'login', 'wbm_ci' )->ID );
$orders = [];
$make = static function ( string $currency, string $total, string $claim_amount ) use ( &$orders, $wpdb ): WC_Order {
	$order = wc_create_order( [ 'status' => 'on-hold' ] );
	$order->set_payment_method( 'bacs' );
	$order->set_currency( $currency );
	$order->set_total( $total );
	$order->save();
	( new ClaimRepository( $wpdb ) )->save( $order->get_id(), [
		'date' => gmdate( 'Y-m-d' ), 'amount' => Money::from_decimal( $claim_amount, $currency, wc_get_price_decimals() ),
		'reference' => 'OVERVIEW-' . $order->get_id(), 'sender' => 'Fixture sender', 'note' => 'Private customer note',
	] );
	$orders[] = $order;
	return $order;
};
$previous_get = $_GET;
try {
	$usd = $make( 'USD', '20.00', '5.00' );
	$eur = $make( 'EUR', '30.00', '30.00' );
	$evidence = new EvidenceRepository( $wpdb );
	$allocation = new AllocationRepository( $wpdb );
	foreach ( [ [ $usd, '5.00' ], [ $eur, '30.00' ] ] as [ $order, $amount ] ) {
		$bank = $evidence->record( $order->get_id(), get_current_user_id(), gmdate( 'Y-m-d' ), Money::from_decimal( $amount, $order->get_currency(), wc_get_price_decimals() ), 'OVERVIEW-BANK-' . $order->get_id(), 'Private bank note' );
		$allocation->allocate( $order->get_id(), $bank['id'], get_current_user_id(), Money::from_decimal( (string) $order->get_total(), $order->get_currency(), wc_get_price_decimals() ), Money::from_decimal( $amount, $order->get_currency(), wc_get_price_decimals() ), 'Private allocation reason' );
	}
	$reviews = new ClaimReviewRepository( $wpdb );
	$reviews->transition( $usd->get_id(), get_current_user_id(), 'under_review', 'Private reviewer comment', false );
	$reviews->transition( $eur->get_id(), get_current_user_id(), 'verified', 'Private verified comment', true );
	$_GET = [ 'page' => 'wpessential-bank-transfer-matcher' ];
	ob_start();
	Plugin::render_page();
	$html = ob_get_clean();
	if ( ! str_contains( $html, 'Claims awaiting review</h3><p>1</p>' ) ||
		! str_contains( $html, 'Claims verified in 30 days</h3><p>1</p>' ) ||
		! str_contains( $html, '15.00 USD' ) || ! str_contains( $html, '0.00 EUR' ) ||
		! str_contains( $html, 'Outstanding on this page' ) ) {
		throw new RuntimeException( 'Overview counts or currency-specific balance are incorrect.' );
	}
	ob_start();
	OrderPanel::render( $usd );
	$panel = ob_get_clean();
	if ( ! str_contains( $panel, 'Recent reconciliation activity' ) ||
		! str_contains( $panel, 'Manual allocation approved' ) ||
		! str_contains( $panel, 'Claim moved to review' ) ||
		str_contains( $panel, 'Private reviewer comment' ) || str_contains( $panel, 'Private bank note' ) ) {
		throw new RuntimeException( 'Audit timeline is incomplete or leaks private notes.' );
	}
	echo "PASS $mode exact per-currency visible balance, claim KPIs and private-safe audit timeline\n";
} finally {
	$_GET = $previous_get;
	foreach ( $orders as $order ) {
		foreach ( [ 'wbm_claim_review_events', 'wbm_manual_allocations', 'wbm_bank_evidence', 'wbm_payment_claims' ] as $suffix ) {
			$wpdb->delete( $wpdb->prefix . $suffix, [ 'order_id' => $order->get_id() ], [ '%d' ] );
		}
		$order->delete( true );
	}
}
