<?php

use WPEssential\BankTransferMatcher\Domain\EntityId;
use WPEssential\BankTransferMatcher\Domain\PaymentReference;
use WPEssential\BankTransferMatcher\Orders\ReferenceIssuer;
use WPEssential\BankTransferMatcher\Orders\ReferenceRepository;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'wbm_ci' !== DB_NAME ) {
	throw new RuntimeException( 'Run against the disposable wbm_ci database.' );
}

global $wpdb;
$mode  = Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'hpos' : 'classic';
$repo  = new ReferenceRepository( $wpdb );
$issue = new ReferenceIssuer( $repo );
$orders = [];
$create = static function ( string $method ) use ( &$orders ): WC_Order {
	$order = wc_create_order();
	if ( ! $order instanceof WC_Order ) {
		throw new RuntimeException( 'Could not create fixture order.' );
	}
	$orders[] = $order;
	$order->set_payment_method( $method );
	$order->save();
	return $order;
};
$id = static fn ( WC_Order $order ) => new EntityId( $order->get_id() );

try {
	$first = $create( 'bacs' );
	$second = $create( 'bacs' );
	$legacy = $create( 'bacs' );
	$other = $create( 'cod' );

	if ( null !== $repo->find( $id( $legacy ) ) ) {
		throw new RuntimeException( 'Legacy order was assigned a reference.' );
	}

	$collision = PaymentReference::issue();
	$issue->issue( $id( $first ), static fn () => $collision );
	$next = PaymentReference::issue();
	$candidates = [ $collision, $next ];
	$issued = $issue->issue( $id( $second ), static function () use ( &$candidates ): PaymentReference {
		return array_shift( $candidates );
	} );
	if ( $next->value !== $issued->value || [] !== $candidates ) {
		throw new RuntimeException( 'A duplicate candidate did not trigger retry.' );
	}

	add_filter( 'woocommerce_order_number', static fn () => 'CUSTOM-001' );
	if ( 'CUSTOM-001' !== $second->get_order_number() ) {
		throw new RuntimeException( 'Custom order number fixture did not apply.' );
	}

	do_action( 'woocommerce_checkout_order_created', $second );
	$checkout = $create( 'bacs' );
	do_action( 'woocommerce_checkout_order_created', $checkout );
	$blocks = $create( 'bacs' );
	do_action( 'woocommerce_store_api_checkout_order_processed', $blocks );
	do_action( 'woocommerce_checkout_order_created', $other );

	$fresh = new ReferenceRepository( $wpdb );
	$checkout_ref = $fresh->find( $id( $checkout ) );
	$blocks_ref = $fresh->find( $id( $blocks ) );
	if ( $collision->value !== $fresh->find( $id( $first ) )?->value ||
		$next->value !== $fresh->find( $id( $second ) )?->value ||
		! $checkout_ref || ! $blocks_ref || $checkout_ref->value === $blocks_ref->value ||
		null !== $fresh->find( $id( $other ) ) || null !== $fresh->find( $id( $legacy ) ) ) {
		throw new RuntimeException( 'Reference persistence, eligibility, or immutability failed.' );
	}

	printf( "PASS %s reference collision retry, order-number independence, eligibility and checkout hook dispatch\n", $mode );
} finally {
	foreach ( $orders as $order ) {
		$order->delete( true );
	}
}
