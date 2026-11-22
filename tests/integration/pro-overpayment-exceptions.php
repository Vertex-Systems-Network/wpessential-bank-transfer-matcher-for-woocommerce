<?php
use WPEssential\BankTransferMatcher\Domain\EntityId;
use WPEssential\BankTransferMatcher\Domain\PaymentReference;
use WPEssential\BankTransferMatcher\Orders\ReferenceRepository;
use WPEssential\BankTransferMatcherPro\Import\BankAccountRepository;
use WPEssential\BankTransferMatcherPro\Import\ImportBatchRepository;
use WPEssential\BankTransferMatcherPro\Import\MappingProfile;
use WPEssential\BankTransferMatcherPro\Reconciliation\AllocationLedgerRepository;
use WPEssential\BankTransferMatcherPro\Reconciliation\OverpaymentExceptionRepository;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'wbm_ci' !== DB_NAME ) throw new RuntimeException( 'Disposable wbm_ci required.' );
global $wpdb;
$admin = get_user_by( 'login', 'wbm_ci' );
wp_set_current_user( $admin->ID );
$order = wc_create_order();
$order->set_payment_method( 'bacs' );
$order->set_currency( 'USD' );
$order->set_total( '500.00' );
$order->set_status( 'on-hold' );
$order->save();
$reference = PaymentReference::issue();
( new ReferenceRepository( $wpdb ) )->reserve( new EntityId( $order->get_id() ), $reference );
$account = ( new BankAccountRepository( $wpdb ) )->create(
	'Overpayment fixture', 'Synthetic bank', 'USD', '1357', $admin->ID
);
$profile = MappingProfile::from_array( [
	'id' => 'overpay-fixture', 'name' => 'Overpayment fixture', 'delimiter' => ',',
	'date_header' => 'Date', 'date_format' => 'Y-m-d', 'amount_header' => 'Amount',
	'decimal_separator' => '.', 'thousands_separator' => '', 'exponent' => 2,
	'currency_header' => '', 'fixed_currency' => 'USD',
	'reference_header' => 'Reference', 'description_header' => '', 'transaction_id_header' => 'Bank ID',
] );
$batch_repo = new ImportBatchRepository( $wpdb );
$batch = $batch_repo->create( $account, $profile, hash( 'sha256', 'overpay-' . $order->get_id() ), 100, $admin->ID );
$batch_repo->transition( $batch, 'pending', 'processing' );
$batch_repo->transition( $batch, 'processing', 'completed', 1 );
$imports = $wpdb->prefix . 'wbm_pro_import_rows';
$payload = [
	'source_row' => 2, 'posted_date' => gmdate( 'Y-m-d' ), 'amount_minor' => 52500,
	'currency' => 'USD', 'exponent' => 2, 'reference' => $reference->value,
	'description' => 'Synthetic overpayment', 'transaction_id' => 'OVERPAY-' . $order->get_id(),
];
if ( 1 !== $wpdb->insert( $imports, [
	'batch_id' => $batch, 'source_row' => 2, 'canonical_json' => wp_json_encode( $payload ),
	'error_code' => '', 'warning_code' => '',
	'identity_fingerprint' => hash( 'sha256', 'overpayment-credit/' . $batch ),
] ) ) throw new RuntimeException( 'Cannot create synthetic overpayment source.' );
$source_id = (int) $wpdb->insert_id;
$reviewer_id = wp_create_user( 'wbm-overpay-' . wp_generate_password( 8, false ),
	wp_generate_password( 16 ), 'overpay-' . wp_generate_password( 8, false ) . '@example.test' );
if ( is_wp_error( $reviewer_id ) ) throw new RuntimeException( $reviewer_id->get_error_message() );
( new WP_User( $reviewer_id ) )->set_role( 'shop_manager' );
$cases = new OverpaymentExceptionRepository( $wpdb );
$reason = 'Bank credit exceeds the order total. Keep the excess unallocated for review.';
$review_note = 'I independently examined this exception; funds remain unallocated pending action.';
$key = hash( 'sha256', 'overpayment-review/' . $source_id );
$reject = static function ( callable $fn, string $description ): void {
	try {
		$fn();
		throw new RuntimeException( 'Unsafe exception review succeeded: ' . $description );
	} catch ( InvalidArgumentException $expected ) {}
};
try {
	wp_set_current_user( 0 );
	try {
		$cases->raise( $source_id, $order->get_id(), $admin->ID, $key, $reason );
		throw new RuntimeException( 'Anonymous exception creation succeeded.' );
	} catch ( RuntimeException $expected ) {
		if ( 'Anonymous exception creation succeeded.' === $expected->getMessage() ) throw $expected;
	}
	wp_set_current_user( $admin->ID );
	$case = $cases->raise( $source_id, $order->get_id(), $admin->ID, $key, $reason );
	$replay = $cases->raise( $source_id, $order->get_id(), $admin->ID, $key, $reason );
	if ( 'open' !== $case['status'] || 'already_open' !== $replay['status'] ||
		$case['exception_id'] !== $replay['exception_id'] ||
		52500 !== $case['credit_unallocated_minor'] || $case['order_payment_changed'] ) {
		throw new RuntimeException( 'Exception creation and idempotency were invalid.' );
	}
	$reject( static fn (): array => $cases->raise( $source_id, $order->get_id(),
		$admin->ID, hash( 'sha256', 'overpay-different-key' ), $reason ), 'duplicate source/order exception' );
	$table = $wpdb->prefix . 'wbm_pro_exceptions';
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $case['exception_id'] ), ARRAY_A );
	if ( ! is_array( $row ) || 'overpayment' !== $row['exception_code'] ||
		2500 !== (int) $row['overpayment_minor'] ||
		50000 !== (int) $row['order_outstanding_minor'] ) {
		throw new RuntimeException( 'Overpayment/source remainder snapshot invalid.' );
	}
	$reject( static fn (): array => $cases->review( $case['exception_id'], $admin->ID,
		'continue_hold', $review_note ), 'self-review' );
	wp_set_current_user( $reviewer_id );
	$reject( static fn (): array => $cases->review( $case['exception_id'], $reviewer_id,
		'automatic_refund', $review_note ), 'unapproved money movement decision' );
	$wpdb->update( $imports, [ 'warning_code' => 'possible_duplicate' ], [ 'id' => $source_id ] );
	$reject( static fn (): array => $cases->review( $case['exception_id'], $reviewer_id,
		'continue_hold', $review_note ), 'duplicated bank source' );
	$wpdb->update( $imports, [ 'warning_code' => '' ], [ 'id' => $source_id ] );
	$order->set_total( '499.00' );
	$order->save();
	$reject( static fn (): array => $cases->review( $case['exception_id'], $reviewer_id,
		'continue_hold', $review_note ), 'changed order amount' );
	$order->set_total( '500.00' );
	$order->save();
	$approved = $cases->review( $case['exception_id'], $reviewer_id, 'continue_hold', $review_note );
	$repeated = $cases->review( $case['exception_id'], $reviewer_id, 'continue_hold', $review_note );
	if ( 'reviewed' !== $approved['status'] || 'already_reviewed' !== $repeated['status'] ||
		$approved['order_payment_changed'] || wc_get_order( $order->get_id() )->is_paid() ) {
		throw new RuntimeException( 'Independent review changed the WooCommerce payment state.' );
	}
	$ledger = ( new AllocationLedgerRepository( $wpdb ) )->snapshot( $order->get_id(),
		$source_id, 50000, 52500, 'USD', 2, $account );
	if ( 50000 !== $ledger['order_outstanding_minor'] || 52500 !== $ledger['credit_unallocated_minor'] ) {
		throw new RuntimeException( 'Review moved bank money or altered a payment.' );
	}
	$events = $wpdb->prefix . 'wbm_pro_exception_events';
	$event_count = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM $events WHERE exception_id = %d", $case['exception_id']
	) );
	if ( 2 !== $event_count ) throw new RuntimeException( 'Immutable two-event review audit invalid.' );
	echo "PASS overpayment exception case, maker/checker review, drift detection and no payment/money mutations\n";
} finally {
	wp_set_current_user( $admin->ID );
	$table = $wpdb->prefix . 'wbm_pro_exceptions';
	$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $table WHERE transaction_row_id = %d", $source_id ) );
	foreach ( $ids as $id ) $wpdb->delete( $wpdb->prefix . 'wbm_pro_exception_events',
		[ 'exception_id' => (int) $id ], [ '%d' ] );
	$wpdb->delete( $table, [ 'transaction_row_id' => $source_id ], [ '%d' ] );
	$wpdb->delete( $imports, [ 'batch_id' => $batch ], [ '%d' ] );
	$wpdb->delete( $wpdb->prefix . 'wbm_pro_import_batches', [ 'id' => $batch ], [ '%d' ] );
	$wpdb->delete( $wpdb->prefix . 'wbm_pro_bank_accounts', [ 'id' => $account ], [ '%d' ] );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $reviewer_id );
	$order->delete( true );
}
