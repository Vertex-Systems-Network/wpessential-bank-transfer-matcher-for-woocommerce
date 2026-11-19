<?php
use WPEssential\BankTransferMatcherPro\Import\BankAccountRepository;
use WPEssential\BankTransferMatcherPro\Reconciliation\OrderReceivingAccountRepository;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'wbm_ci' !== DB_NAME ) throw new RuntimeException( 'Disposable wbm_ci required.' );
global $wpdb;
$admin = get_user_by( 'login', 'wbm_ci' );
wp_set_current_user( $admin->ID );
$order = wc_create_order();
$order->set_payment_method( 'bacs' );
$order->set_currency( 'USD' );
$order->set_total( '100.00' );
$order->set_status( 'on-hold' );
$order->save();
$banks = new BankAccountRepository( $wpdb );
$account_a = $banks->create( 'Order receiving USD A', 'Synthetic Bank A', 'USD', '4101', $admin->ID );
$account_b = $banks->create( 'Order receiving USD B', 'Synthetic Bank B', 'USD', '4102', $admin->ID );
$account_eur = $banks->create( 'Order receiving EUR', 'Synthetic Bank C', 'EUR', '4103', $admin->ID );
$bindings = new OrderReceivingAccountRepository( $wpdb );
$reviewer_id = wp_create_user( 'wbm-bank-bind-shop-' . wp_generate_password( 8, false ),
	wp_generate_password( 16 ), 'bind-shop-' . wp_generate_password( 8, false ) . '@example.test' );
if ( is_wp_error( $reviewer_id ) ) throw new RuntimeException( $reviewer_id->get_error_message() );
( new WP_User( $reviewer_id ) )->set_role( 'shop_manager' );
$reason = 'The merchant explicitly designated USD account A to receive this order payment.';
$reject = static function ( callable $fn, string $label ): void {
	try {
		$fn();
		throw new RuntimeException( "$label was wrongly permitted." );
	} catch ( InvalidArgumentException $expected ) {}
};
try {
	if ( 0 !== $bindings->expected_account_id( $order->get_id() ) ) {
		throw new RuntimeException( 'Missing receiving account was inferred from import.' );
	}
	$reject( static fn (): void => $bindings->assert_matches( $order->get_id(), $account_a ),
		'Unconfirmed bank source match' );
	wp_set_current_user( $reviewer_id );
	try {
		$bindings->confirm( $order->get_id(), $account_a, $reviewer_id, $reason );
		throw new RuntimeException( 'Shop manager without account-management capability confirmed a bank.' );
	} catch ( RuntimeException $error ) {
		if ( str_contains( $error->getMessage(), 'without account-management capability' ) ) throw $error;
	}
	wp_set_current_user( $admin->ID );
	$reject( static fn (): array => $bindings->confirm( $order->get_id(), $account_eur, $admin->ID, $reason ),
		'Mismatched receiving currency' );
	$confirmed = $bindings->confirm( $order->get_id(), $account_a, $admin->ID, $reason );
	$replay = $bindings->confirm( $order->get_id(), $account_a, $admin->ID, $reason );
	if ( 'confirmed' !== $confirmed['status'] || 'already_confirmed' !== $replay['status'] ||
		$account_a !== $bindings->expected_account_id( $order->get_id() ) ) {
		throw new RuntimeException( 'Expected receiving account binding is not stable.' );
	}
	$reject( static fn (): void => $bindings->assert_matches( $order->get_id(), $account_b ),
		'Wrong USD receiving account' );
	$reject( static fn (): array => $bindings->confirm( $order->get_id(), $account_b, $admin->ID, $reason ),
		'Unaudited bank reassignment' );
	$bindings->assert_matches( $order->get_id(), $account_a );
	$events = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->prefix}wbm_pro_order_bank_events WHERE order_id = %d", $order->get_id()
	) );
	if ( 1 !== $events || wc_get_order( $order->get_id() )->is_paid() ) {
		throw new RuntimeException( 'Bank binding replay added an event or changed WooCommerce payment.' );
	}
	echo "PASS immutable receiving account, missing/mismatch/multicurrency rejection, authorization and bank confirmation audit\n";
} finally {
	wp_set_current_user( $admin->ID );
	$wpdb->delete( $wpdb->prefix . 'wbm_pro_order_bank_events', [ 'order_id' => $order->get_id() ], [ '%d' ] );
	$wpdb->delete( $wpdb->prefix . 'wbm_pro_order_receiving_accounts', [ 'order_id' => $order->get_id() ], [ '%d' ] );
	foreach ( [ $account_a, $account_b, $account_eur ] as $account_id ) {
		$wpdb->delete( $wpdb->prefix . 'wbm_pro_bank_accounts', [ 'id' => $account_id ], [ '%d' ] );
	}
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $reviewer_id );
	$order->delete( true );
}
