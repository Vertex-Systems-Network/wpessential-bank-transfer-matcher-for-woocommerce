<?php

use WPEssential\BankTransferMatcher\Domain\EntityId;
use WPEssential\BankTransferMatcher\Orders\ReferenceRepository;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'wbm_ci' !== DB_NAME ) {
	throw new RuntimeException( 'Run against the disposable wbm_ci database.' );
}

function capture_reference_output( callable $callback ): string {
	ob_start();
	try {
		$callback();
		return (string) ob_get_contents();
	} finally {
		ob_end_clean();
	}
}

function expect_reference( string $name, string $output, string $reference, bool $visible = true ): void {
	if ( ( $visible ? 1 : 0 ) !== substr_count( $output, $reference ) ) {
		throw new RuntimeException( 'Unexpected reference output for ' . $name );
	}
}

add_filter( 'pre_wp_mail', '__return_true' );

$customer = wp_insert_user( array(
	'user_login' => 'wbm-customer-' . wp_generate_password( 10, false ),
	'user_pass' => wp_generate_password(),
	'role' => 'customer',
) );
if ( is_wp_error( $customer ) ) {
	throw new RuntimeException( 'Could not create fixture customer.' );
}
$order = wc_create_order( array( 'customer_id' => $customer, 'status' => 'on-hold' ) );
if ( ! $order instanceof WC_Order ) {
	throw new RuntimeException( 'Could not create fixture order.' );
}

try {
	$order->set_payment_method( 'bacs' );
	$order->save();
	do_action( 'woocommerce_checkout_order_created', $order );
	global $wpdb;
	$reference = ( new ReferenceRepository( $wpdb ) )->find( new EntityId( $order->get_id() ) );
	if ( null === $reference ) {
		throw new RuntimeException( 'Could not issue a reference for the presentation fixture.' );
	}
	$value = $reference->value;
	$_GET['key'] = $order->get_order_key();
	wp_set_current_user( $customer );
	set_query_var( 'order-received', $order->get_id() );

	$classic = static fn () => wc_get_template( 'checkout/thankyou.php', array( 'order' => $order ) );
	$account = static fn () => wc_get_template( 'myaccount/view-order.php', array(
		'order' => $order,
		'order_id' => $order->get_id(),
	) );
	$block = static function (): void {
		echo do_blocks( '<!-- wp:woocommerce/order-confirmation-additional-information /-->' );
	};
	expect_reference( 'classic Thank You', capture_reference_output( $classic ), $value );
	expect_reference( 'Blocks confirmation', capture_reference_output( $block ), $value );
	expect_reference( 'My Account owner', capture_reference_output( $account ), $value );

	$_GET['key'] = 'invalid-key';
	expect_reference( 'invalid Blocks key', capture_reference_output( $block ), $value, false );
	expect_reference( 'invalid confirmation key', capture_reference_output(
		static fn () => do_action( 'woocommerce_thankyou_bacs', $order->get_id() )
	), $value, false );
	$_GET['key'] = $order->get_order_key();
	wp_set_current_user( 0 );
	expect_reference( 'anonymous My Account', capture_reference_output( $account ), $value, false );
	expect_reference( 'anonymous registered-order Blocks confirmation', capture_reference_output( $block ), $value, false );

	$emails = WC()->mailer()->get_emails();
	$email = $emails['WC_Email_Customer_On_Hold_Order'];
	$email->object = $order;
	expect_reference( 'customer HTML email', $email->get_content_html(), $value );
	expect_reference( 'customer plain-text email', $email->get_content_plain(), $value );
	expect_reference( 'admin email hook', capture_reference_output(
		static fn () => do_action( 'woocommerce_email_before_order_table', $order, true, false, $email )
	), $value, false );

	$order->set_customer_id( 0 );
	$order->set_billing_email( 'fixture@example.test' );
	$order->save();
	expect_reference( 'guest Blocks confirmation', capture_reference_output( $block ), $value );

	echo "PASS classic template, Blocks confirmation render, My Account, HTML/plain email and access exclusions\n";
} finally {
	$order->delete( true );
}
