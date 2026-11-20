<?php
use WPEssential\BankTransferMatcher\Evidence\AllocationRepository;
use WPEssential\BankTransferMatcher\Evidence\EvidenceRepository;
use WPEssential\BankTransferMatcher\Domain\Money;
use WPEssential\BankTransferMatcher\Domain\EntityId;
use WPEssential\BankTransferMatcher\Domain\PaymentReference;
use WPEssential\BankTransferMatcher\Orders\ReferenceRepository;
use WPEssential\BankTransferMatcherPro\Import\BankAccountRepository;
use WPEssential\BankTransferMatcherPro\Import\ImportBatchRepository;
use WPEssential\BankTransferMatcherPro\Import\MappingProfile;
use WPEssential\BankTransferMatcherPro\Reconciliation\OneToOneApproval;
use WPEssential\BankTransferMatcherPro\Reconciliation\OneToOneProposal;
use WPEssential\BankTransferMatcherPro\Reconciliation\OrderReceivingAccountRepository;
use WPEssential\BankTransferMatcherPro\Reconciliation\ProPaymentFinalizer;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'wbm_ci' !== DB_NAME ) {
	throw new RuntimeException( 'Run payment completion tests in the disposable wbm_ci database only.' );
}
global $wpdb;
$admin = get_user_by( 'login', 'wbm_ci' );
wp_set_current_user( $admin->ID );
$orders = [];
$references = [];
foreach ( [ '20.00', '20.00', '30.00' ] as $amount ) {
	$order = wc_create_order();
	$order->set_payment_method( 'bacs' );
	$order->set_currency( 'USD' );
	$order->set_total( $amount );
	$order->set_status( 'on-hold' );
	$order->save();
	$orders[] = $order;
	$reference = PaymentReference::issue();
	( new ReferenceRepository( $wpdb ) )->reserve( new EntityId( $order->get_id() ), $reference );
	$references[] = $reference->value;
}
$account = ( new BankAccountRepository( $wpdb ) )->create(
	'Pro completion fixture', 'Synthetic bank', 'USD', '3344', $admin->ID
);
$receiving = new OrderReceivingAccountRepository( $wpdb );
foreach ( $orders as $order ) {
	$receiving->confirm( $order->get_id(), $account, $admin->ID,
		'The merchant confirmed the USD receiving account before finance finalization.' );
}
$profile = MappingProfile::from_array( [
	'id' => 'finalization-fixture', 'name' => 'Finance finalization fixture', 'delimiter' => ',',
	'date_header' => 'Date', 'date_format' => 'Y-m-d', 'amount_header' => 'Amount',
	'decimal_separator' => '.', 'thousands_separator' => '', 'exponent' => 2,
	'currency_header' => '', 'fixed_currency' => 'USD', 'reference_header' => 'Reference',
	'description_header' => '', 'transaction_id_header' => 'Bank ID',
] );
$batches = new ImportBatchRepository( $wpdb );
$batch = $batches->create( $account, $profile,
	hash( 'sha256', 'pro-completion/' . $orders[0]->get_id() ), 100, $admin->ID );
$batches->transition( $batch, 'pending', 'processing' );
$batches->transition( $batch, 'processing', 'completed', 3 );
$sources = [];
$allocations = [];
$rows = $wpdb->prefix . 'wbm_pro_import_rows';
$proposals = new OneToOneProposal( $wpdb );
$review = new OneToOneApproval( $wpdb );
$finalizer = new ProPaymentFinalizer( $wpdb );
$intent_table = $wpdb->prefix . 'wbm_pro_payment_finalizations';
$allocation_table = $wpdb->prefix . 'wbm_pro_allocations';
$event_table = $wpdb->prefix . 'wbm_pro_allocation_events';
$reason = 'This distinct imported bank credit was reviewed against immutable BACS order evidence.';
$review_reason = 'I independently confirmed the bank credit is cleared and matches the current order.';
$final_reason = 'I explicitly confirmed the cleared bank source, independent review and full order funding.';
$checker = wp_create_user( 'wbm-payment-checker-' . wp_generate_password( 8, false ),
	wp_generate_password( 16 ), 'payment-checker-' . wp_generate_password( 8, false ) . '@example.test' );
if ( is_wp_error( $checker ) ) throw new RuntimeException( $checker->get_error_message() );
( new WP_User( $checker ) )->set_role( 'shop_manager' );
$reject = static function ( callable $fn, string $reason ): void {
	try {
		$fn();
		throw new RuntimeException( 'Unsafe Pro payment operation accepted: ' . $reason );
	} catch ( InvalidArgumentException $expected ) {}
};
$hook_count = 0;
$fail_hook = false;
$pending_at_hook = false;
$payment_hook = static function ( int $order_id ) use (
	&$hook_count, &$fail_hook, &$pending_at_hook, $orders, $wpdb, $intent_table
): void {
	if ( $order_id !== $orders[0]->get_id() && $order_id !== $orders[1]->get_id() ) return;
	++$hook_count;
	$state = $wpdb->get_var( $wpdb->prepare(
		"SELECT state FROM $intent_table WHERE order_id = %d", $order_id
	) );
	$pending_at_hook = 'pending' === $state;
	if ( ! $pending_at_hook ) throw new RuntimeException( 'WooCommerce hooks ran before durable payment intent.' );
	if ( $fail_hook ) throw new RuntimeException( 'Simulated WooCommerce post-payment hook failure.' );
};
$other_connection = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
$held_lock = null;
add_action( 'woocommerce_payment_complete', $payment_hook, 500, 1 );
try {
	// One $20 imported credit per order. Third order remains $10 short.
	foreach ( $orders as $index => $order ) {
		$payload = [
			'source_row' => $index + 2, 'posted_date' => gmdate( 'Y-m-d' ), 'amount_minor' => 2000,
			'currency' => 'USD', 'exponent' => 2, 'reference' => $references[$index],
			'description' => 'Synthetic cleared bank credit for completion tests',
			'transaction_id' => 'PRO-PAID-' . $order->get_id(),
		];
		if ( 1 !== $wpdb->insert( $rows, [
			'batch_id' => $batch, 'source_row' => $index + 2,
			'canonical_json' => wp_json_encode( $payload ),
			'error_code' => '', 'warning_code' => '',
			'identity_fingerprint' => hash( 'sha256', 'verified-payment-' . $batch . '-' . $index ),
		] ) ) throw new RuntimeException( 'Could not stage fixture bank credit.' );
		$sources[] = (int) $wpdb->insert_id;
		$allocation = $proposals->propose( $order->get_id(), $sources[$index], $admin->ID,
			hash( 'sha256', 'pro-payment-proposal/' . $sources[$index] ), $reason );
		$allocations[] = (int) $allocation['allocation_id'];
	}
	wp_set_current_user( $checker );
	foreach ( $allocations as $id ) $review->approve( $id, $checker, true, $review_reason );
	$key1 = hash( 'sha256', 'pro-payment-finalization/' . $orders[0]->get_id() );
	$key2 = hash( 'sha256', 'pro-payment-finalization/' . $orders[1]->get_id() );
	$partial_key = hash( 'sha256', 'pro-payment-finalization/' . $orders[2]->get_id() );
	$reject( static fn (): array => $finalizer->complete(
		$orders[2]->get_id(), $checker, $partial_key, true, $final_reason
	), 'partial order funded only 20 of 30 dollars' );
	if ( wc_get_order( $orders[2]->get_id() )->is_paid() ) {
		throw new RuntimeException( 'Partial bank payment silently marked the order paid.' );
	}
	// Even when a separate Free manual claim fills the $10 remainder, Pro
	// cannot treat its origin as independently cleared and skip maker/checker.
	wp_set_current_user( $admin->ID );
	$manual_evidence = ( new EvidenceRepository( $wpdb ) )->record(
		$orders[2]->get_id(), $admin->ID, gmdate( 'Y-m-d' ),
		new Money( 1000, 'USD', 2 ), $references[2],
		'Merchant-entered remaining ten dollars has no Pro bank clearance attestation.'
	);
	( new AllocationRepository( $wpdb ) )->allocate(
		$orders[2]->get_id(), $manual_evidence['id'], $admin->ID,
		new Money( 3000, 'USD', 2 ), new Money( 1000, 'USD', 2 ),
		'Manual source requires its own review and is not cleared Pro evidence.'
	);
	wp_set_current_user( $checker );
	$reject( static fn (): array => $finalizer->complete(
		$orders[2]->get_id(), $checker, $partial_key, true, $final_reason
	), 'mixed Free manual and Pro credits without independent source attestation' );
	if ( wc_get_order( $orders[2]->get_id() )->is_paid() ) {
		throw new RuntimeException( 'A mixed Free/Pro order was marked paid without verified manual evidence.' );
	}
	$reject( static fn (): array => $finalizer->complete(
		$orders[0]->get_id(), $checker, $key1, false, $final_reason
	), 'unconfirmed bank clearance' );
	wp_set_current_user( $admin->ID );
	$reject( static fn (): array => $finalizer->complete(
		$orders[0]->get_id(), $admin->ID, $key1, true, $final_reason
	), 'original credit maker finalizing their own order' );
	wp_set_current_user( 0 );
	try {
		$finalizer->complete( $orders[0]->get_id(), $checker, $key1, true, $final_reason );
		throw new RuntimeException( 'Anonymous Pro payment finalization succeeded.' );
	} catch ( RuntimeException $expected ) {
		if ( 'Anonymous Pro payment finalization succeeded.' === $expected->getMessage() ) throw $expected;
	}
	wp_set_current_user( $checker );
	$wpdb->update( $rows, [ 'warning_code' => 'possible_duplicate' ], [ 'id' => $sources[0] ] );
	$reject( static fn (): array => $finalizer->complete(
		$orders[0]->get_id(), $checker, $key1, true, $final_reason
	), 'source warning after approval' );
	$wpdb->update( $rows, [ 'warning_code' => '' ], [ 'id' => $sources[0] ] );

	// A different SQL session can block completion without changing the order.
	$held_lock = hash( 'sha256', DB_NAME . '/' . $wpdb->prefix . '/wbm-pro-source/' . $sources[0] );
	if ( '1' !== (string) $other_connection->get_var(
		$other_connection->prepare( 'SELECT GET_LOCK(%s, 0)', $held_lock )
	) ) throw new RuntimeException( 'Could not challenge source lock on finalizer.' );
	try {
		$finalizer->complete( $orders[0]->get_id(), $checker, $key1, true, $final_reason );
		throw new RuntimeException( 'Locked imported bank source was finalized.' );
	} catch ( RuntimeException $expected ) {
		if ( ! str_contains( $expected->getMessage(), 'concurrent reconciliation' ) ) throw $expected;
	}
	$other_connection->get_var( $other_connection->prepare( 'SELECT RELEASE_LOCK(%s)', $held_lock ) );
	$held_lock = null;

	$done = $finalizer->complete( $orders[0]->get_id(), $checker, $key1, true, $final_reason );
	if ( 'completed' !== $done['status'] || ! $done['order_payment_changed'] ||
		! wc_get_order( $orders[0]->get_id() )->is_paid() || ! $pending_at_hook ||
		1 !== $hook_count ) throw new RuntimeException( 'Explicit finance completion or Woo intent order failed.' );
	$replay = $finalizer->complete( $orders[0]->get_id(), $checker, $key1, true, $final_reason );
	if ( 'already_completed' !== $replay['status'] || $replay['order_payment_changed'] ||
		1 !== $hook_count ) throw new RuntimeException( 'Finalizer replay ran WooCommerce payment hooks twice.' );
	$reject( static fn (): array => $finalizer->complete(
		$orders[0]->get_id(), $checker, hash( 'sha256', 'different-completion-intent' ),
		true, $final_reason
	), 'payment request key changed after payment' );

	// Simulate interruption after WC saves paid status but before the finalizer
	// records its audit. The pending intent MUST recover without paying twice.
	$fail_hook = true;
	try {
		$finalizer->complete( $orders[1]->get_id(), $checker, $key2, true, $final_reason );
		throw new RuntimeException( 'Failure-injected WooCommerce hook was ignored.' );
	} catch ( RuntimeException $expected ) {
		if ( ! str_contains( $expected->getMessage(), 'pending intent retained for recovery' ) ) throw $expected;
	}
	$intent_state = $wpdb->get_var( $wpdb->prepare(
		"SELECT state FROM $intent_table WHERE order_id = %d", $orders[1]->get_id()
	) );
	if ( 'pending' !== $intent_state || ! wc_get_order( $orders[1]->get_id() )->is_paid() ) {
		throw new RuntimeException( 'Simulated payment hook interruption did not leave recoverable paid+pending state.' );
	}
	$hooks_before = $hook_count;
	$fail_hook = false;
	$recovered = $finalizer->complete( $orders[1]->get_id(), $checker, $key2, true, $final_reason );
	if ( 'audit_recovered' !== $recovered['status'] || $recovered['order_payment_changed'] ||
		$hook_count !== $hooks_before ) {
		throw new RuntimeException( 'Crash recovery called payment_complete a second time.' );
	}
	$twice = $finalizer->complete( $orders[1]->get_id(), $checker, $key2, true, $final_reason );
	if ( 'already_completed' !== $twice['status'] || $twice['order_payment_changed'] ) {
		throw new RuntimeException( 'Finalization recovery did not become idempotent.' );
	}
	foreach ( [ 0, 1 ] as $index ) {
		$state = $wpdb->get_var( $wpdb->prepare(
			"SELECT state FROM $allocation_table WHERE id = %d", $allocations[$index]
		) );
		$events = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM $event_table WHERE allocation_id = %d", $allocations[$index]
		) );
		$intent = $wpdb->get_var( $wpdb->prepare(
			"SELECT state FROM $intent_table WHERE order_id = %d", $orders[$index]->get_id()
		) );
		if ( 'committed' !== $state || $events !== 3 || 'completed' !== $intent ) {
			throw new RuntimeException( 'Paid order and finance allocation audit do not agree.' );
		}
	}
	if ( wc_get_order( $orders[2]->get_id() )->is_paid() ) {
		throw new RuntimeException( 'Unfunded order was marked paid by another credit.' );
	}
	echo "PASS explicit Pro WooCommerce completion, source/order locks, no partial payments, idempotency and paid-intent audit recovery\n";
} finally {
	remove_action( 'woocommerce_payment_complete', $payment_hook, 500 );
	if ( null !== $held_lock ) {
		$other_connection->get_var( $other_connection->prepare( 'SELECT RELEASE_LOCK(%s)', $held_lock ) );
	}
	wp_set_current_user( $admin->ID );
	foreach ( $orders as $order ) {
		$wpdb->delete( $intent_table, [ 'order_id' => $order->get_id() ], [ '%d' ] );
		$wpdb->delete( $wpdb->prefix . 'wbm_manual_allocations', [ 'order_id' => $order->get_id() ], [ '%d' ] );
		$wpdb->delete( $wpdb->prefix . 'wbm_bank_evidence', [ 'order_id' => $order->get_id() ], [ '%d' ] );
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM $allocation_table WHERE order_id = %d", $order->get_id()
		) );
		foreach ( $ids as $id ) {
			$wpdb->delete( $event_table, [ 'allocation_id' => (int) $id ], [ '%d' ] );
		}
		$wpdb->delete( $allocation_table, [ 'order_id' => $order->get_id() ], [ '%d' ] );
		$wpdb->delete( $wpdb->prefix . 'wbm_pro_order_bank_events',
			[ 'order_id' => $order->get_id() ], [ '%d' ] );
		$wpdb->delete( $wpdb->prefix . 'wbm_pro_order_receiving_accounts',
			[ 'order_id' => $order->get_id() ], [ '%d' ] );
		$order->delete( true );
	}
	$wpdb->delete( $rows, [ 'batch_id' => $batch ], [ '%d' ] );
	$wpdb->delete( $wpdb->prefix . 'wbm_pro_import_batches', [ 'id' => $batch ], [ '%d' ] );
	$wpdb->delete( $wpdb->prefix . 'wbm_pro_bank_accounts', [ 'id' => $account ], [ '%d' ] );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $checker );
}
