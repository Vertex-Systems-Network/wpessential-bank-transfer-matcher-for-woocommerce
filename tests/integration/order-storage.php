<?php

use Automattic\WooCommerce\Utilities\OrderUtil;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'wbm_ci' !== DB_NAME ) {
	throw new RuntimeException( 'Run against the disposable wbm_ci database.' );
}

$mode = $args[0] ?? '';
if ( 'pro-missing-free' === $mode ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	$pro = 'wpessential-bank-transfer-matcher-pro/wpessential-bank-transfer-matcher-pro.php';
	$result = activate_plugin( $pro );
	if ( ! is_wp_error( $result ) || 'plugin_missing_dependencies' !== $result->get_error_code() || is_plugin_active( $pro ) ) {
		throw new RuntimeException( 'Expected WordPress to block Pro activation without Free.' );
	}
	echo "PASS Pro activation rejected without Free\n";
	return;
}

if ( ! in_array( $mode, array( 'hpos', 'classic' ), true ) ||
	1 !== did_action( 'wpessential_bank_transfer_matcher_ready' ) ||
	did_action( 'wpessential_bank_transfer_matcher_pro_ready' ) ) {
	throw new RuntimeException( 'Expected the standalone Free bootstrap and a known storage mode.' );
}

if ( OrderUtil::custom_orders_table_usage_is_enabled() !== ( 'hpos' === $mode ) ) {
	throw new RuntimeException( 'WooCommerce did not use the requested order store.' );
}

$order = wc_create_order();
if ( ! $order instanceof WC_Order || ! $order->get_id() ) {
	throw new RuntimeException( 'Could not create a disposable WooCommerce order.' );
}

try {
	$order->set_payment_method( 'bacs' );
	$order->save();
	$stored = wc_get_order( $order->get_id() );
	if ( ! $stored instanceof WC_Order || 'bacs' !== $stored->get_payment_method() ) {
		throw new RuntimeException( 'The WooCommerce order failed its storage round trip.' );
	}
} finally {
	$order->delete( true );
}

printf( "PASS Free loaded with WooCommerce %s in %s mode. WooCommerce order CRUD passed.\n", WC_VERSION, $mode );
