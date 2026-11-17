<?php

use Automattic\WooCommerce\Utilities\OrderUtil;
use WPEssential\BankTransferMatcher\Admin\OrderPanel;
use WPEssential\BankTransferMatcher\Bootstrap\Plugin;
use WPEssential\BankTransferMatcher\Domain\Money;
use WPEssential\BankTransferMatcher\Evidence\AllocationRepository;
use WPEssential\BankTransferMatcher\Evidence\EvidenceRepository;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'wbm_ci' !== DB_NAME ) {
	throw new RuntimeException( 'Run against the disposable wbm_ci database.' );
}
$mode = $args[0] ?? '';
if ( ! in_array( $mode, [ 'classic', 'hpos' ], true ) || OrderUtil::custom_orders_table_usage_is_enabled() !== ( 'hpos' === $mode ) ) {
	throw new RuntimeException( 'Order admin test requires the requested order storage.' );
}
global $wpdb;
wp_set_current_user( get_user_by( 'login', 'wbm_ci' )->ID );
$orders = [];
$make = static function ( string $status, string $gateway ) use ( &$orders ): WC_Order {
	$order = wc_create_order( [ 'status' => $status ] );
	$order->set_payment_method( $gateway );
	$order->set_total( '20.00' );
	$order->save();
	$orders[] = $order;
	return $order;
};
$render = static function ( array $query ): string {
	$_GET = array_replace( [ 'page' => 'wpessential-bank-transfer-matcher' ], $query );
	ob_start();
	try {
		Plugin::render_page();
		return ob_get_contents();
	} finally {
		ob_end_clean();
	}
};
$previous_get = $_GET;
try {
	$hold = $make( 'on-hold', 'bacs' );
	$pending = $make( 'pending', 'bacs' );
	$other = $make( 'on-hold', 'cod' );
	$paid = $make( 'processing', 'bacs' );
	$default = $render( [] );
	if ( ! str_contains( $default, '#' . $hold->get_order_number() . '</a>' ) ||
		! str_contains( $default, '#' . $pending->get_order_number() . '</a>' ) ||
		str_contains( $default, '#' . $other->get_order_number() . '</a>' ) ||
		str_contains( $default, '#' . $paid->get_order_number() . '</a>' ) ) {
		throw new RuntimeException( 'Eligible BACS order query returned incorrect orders in ' . $mode );
	}
	$filtered = $render( [ 'wbm_status' => 'on-hold' ] );
	if ( ! str_contains( $filtered, '#' . $hold->get_order_number() . '</a>' ) || str_contains( $filtered, '#' . $pending->get_order_number() . '</a>' ) ) {
		throw new RuntimeException( 'Order status filter did not affect results.' );
	}
	$evidence = ( new EvidenceRepository( $wpdb ) )->record( $hold->get_id(), get_current_user_id(), gmdate( 'Y-m-d' ), Money::from_decimal( '8.00', $hold->get_currency(), wc_get_price_decimals() ), 'ORDER-PANEL-' . $hold->get_id(), 'Synthetic bank credit' );
	( new AllocationRepository( $wpdb ) )->allocate( $hold->get_id(), $evidence['id'], get_current_user_id(), Money::from_decimal( '20.00', $hold->get_currency(), wc_get_price_decimals() ), Money::from_decimal( '8.00', $hold->get_currency(), wc_get_price_decimals() ), 'Synthetic approved allocation' );
	ob_start();
	OrderPanel::render( $hold );
	$panel = ob_get_clean();
	if ( ! str_contains( $panel, '8.00 ' . $hold->get_currency() ) || ! str_contains( $panel, '12.00 ' . $hold->get_currency() ) || ! str_contains( $panel, 'Manual evidence records:' ) ) {
		throw new RuntimeException( 'Order panel did not show the exact allocation summary.' );
	}
	ob_start();
	OrderPanel::render( $other );
	if ( '' !== ob_get_clean() ) {
		throw new RuntimeException( 'Non-BACS order displayed the matcher panel.' );
	}
	for ( $i = 0; $i < 20; ++$i ) {
		$make( 'on-hold', 'bacs' );
	}
	$page_one = $render( [] );
	$page_two = $render( [ 'paged' => '2' ] );
	if ( 20 !== preg_match_all( '/<tr><td><a href=/', $page_one ) ) {
		throw new RuntimeException( 'First page did not contain 20 order rows.' );
	}
	if ( 2 !== preg_match_all( '/<tr><td><a href=/', $page_two ) || ! str_contains( $page_one, 'wbm-pagination' ) || ! str_contains( $page_one, 'paged=2' ) ) {
		throw new RuntimeException( 'Second page or pagination links were incorrect.' );
	}
	echo "PASS $mode eligible BACS query, status filter, exact order panel, non-BACS isolation and pagination\n";
} finally {
	$_GET = $previous_get;
	foreach ( $orders as $order ) {
		$wpdb->delete( $wpdb->prefix . 'wbm_manual_allocations', [ 'order_id' => $order->get_id() ], [ '%d' ] );
		$wpdb->delete( $wpdb->prefix . 'wbm_bank_evidence', [ 'order_id' => $order->get_id() ], [ '%d' ] );
		$order->delete( true );
	}
}
