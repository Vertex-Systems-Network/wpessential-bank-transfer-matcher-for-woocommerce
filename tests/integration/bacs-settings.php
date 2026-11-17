<?php

use WPEssential\BankTransferMatcher\Domain\EntityId;
use WPEssential\BankTransferMatcher\Orders\PaymentMethods;
use WPEssential\BankTransferMatcher\Orders\ReferenceRepository;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'wbm_ci' !== DB_NAME ) {
	throw new RuntimeException( 'Run against the disposable wbm_ci database.' );
}

$enabled = ( $args[0] ?? '' ) === 'enabled';
$expected = 'Fixture transfer instructions';
$bacs_settings = get_option( 'woocommerce_bacs_settings' );
$accounts = get_option( 'woocommerce_bacs_accounts' );

if ( $enabled !== in_array( 'bacs', PaymentMethods::enabled_ids(), true ) ||
	! in_array( 'cod', PaymentMethods::enabled_ids(), true ) ||
	$expected !== PaymentMethods::bacs_instructions() ) {
	throw new RuntimeException( 'Gateway detection or native instructions did not match the fixture.' );
}

$order = wc_create_order();
if ( ! $order instanceof WC_Order ) {
	throw new RuntimeException( 'Could not create fixture order.' );
}

try {
	$order->set_payment_method( 'bacs' );
	$order->save();
	do_action( 'woocommerce_checkout_order_created', $order );
	global $wpdb;
	$reference = ( new ReferenceRepository( $wpdb ) )->find( new EntityId( $order->get_id() ) );
	if ( $enabled !== ( null !== $reference ) ) {
		throw new RuntimeException( 'BACS reference eligibility did not follow the enabled setting.' );
	}
	if ( $bacs_settings !== get_option( 'woocommerce_bacs_settings' ) ||
		$accounts !== get_option( 'woocommerce_bacs_accounts' ) ) {
		throw new RuntimeException( 'Gateway detection altered native WooCommerce settings.' );
	}
	printf( "PASS native BACS %s, enabled COD detection, instructions and unchanged bank settings\n", $enabled ? 'enabled' : 'disabled' );
} finally {
	$order->delete( true );
}
