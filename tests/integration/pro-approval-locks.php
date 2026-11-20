<?php
use WPEssential\BankTransferMatcher\Domain\EntityId;
use WPEssential\BankTransferMatcher\Domain\PaymentReference;
use WPEssential\BankTransferMatcher\Orders\ReferenceRepository;
use WPEssential\BankTransferMatcherPro\Import\BankAccountRepository;
use WPEssential\BankTransferMatcherPro\Import\ImportBatchRepository;
use WPEssential\BankTransferMatcherPro\Import\MappingProfile;
use WPEssential\BankTransferMatcherPro\Reconciliation\AllocationLedgerRepository;
use WPEssential\BankTransferMatcherPro\Reconciliation\OneToOneApproval;
use WPEssential\BankTransferMatcherPro\Reconciliation\OneToOneProposal;
use WPEssential\BankTransferMatcherPro\Reconciliation\OrderReceivingAccountRepository;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'wbm_ci' !== DB_NAME ) throw new RuntimeException( 'Disposable wbm_ci database required.' );
global $wpdb;
$admin = get_user_by( 'login', 'wbm_ci' );
wp_set_current_user( $admin->ID );
$order = wc_create_order();
$order->set_payment_method( 'bacs' );
$order->set_currency( 'USD' );
$order->set_total( '55.00' );
$order->set_status( 'on-hold' );
$order->save();
$reference = PaymentReference::issue();
( new ReferenceRepository( $wpdb ) )->reserve( new EntityId( $order->get_id() ), $reference );
$account = ( new BankAccountRepository( $wpdb ) )->create(
	'Approval contention fixture', 'Synthetic Bank', 'USD', '5511', $admin->ID
);
( new OrderReceivingAccountRepository( $wpdb ) )->confirm(
	$order->get_id(), $account, $admin->ID,
	'Merchant selected this USD receiving account for the lock and approval fixture.'
);
$profile = MappingProfile::from_array( [
	'id' => 'review-lock-fixture', 'name' => 'Review lock fixture',
	'delimiter' => ',', 'date_header' => 'Date', 'date_format' => 'Y-m-d',
	'amount_header' => 'Amount', 'decimal_separator' => '.', 'thousands_separator' => '',
	'exponent' => 2, 'currency_header' => '', 'fixed_currency' => 'USD',
	'reference_header' => 'Reference', 'description_header' => '', 'transaction_id_header' => 'Bank ID',
] );
$batches = new ImportBatchRepository( $wpdb );
$batch = $batches->create( $account, $profile, hash( 'sha256', 'lock-fixture-' . $order->get_id() ), 100, $admin->ID );
$batches->transition( $batch, 'pending', 'processing' );
$batches->transition( $batch, 'processing', 'completed', 1 );
$imports = $wpdb->prefix . 'wbm_pro_import_rows';
if ( 1 !== $wpdb->insert( $imports, [
	'batch_id' => $batch, 'source_row' => 2,
	'canonical_json' => wp_json_encode( [
		'source_row' => 2, 'posted_date' => gmdate( 'Y-m-d' ),
		'amount_minor' => 5500, 'currency' => 'USD', 'exponent' => 2,
		'reference' => $reference->value, 'description' => 'Synthetic lock evidence',
		'transaction_id' => 'LOCKED-' . $order->get_id(),
	] ),
	'error_code' => '', 'warning_code' => '',
	'identity_fingerprint' => hash( 'sha256', 'locking-source-' . $batch ),
] ) ) throw new RuntimeException( 'Could not stage source for lock fixture.' );
$source_id = (int) $wpdb->insert_id;
$reviewer_id = wp_create_user( 'wbm-lock-reviewer-' . wp_generate_password( 8, false ),
	wp_generate_password( 16 ), 'lock-' . wp_generate_password( 8, false ) . '@example.test' );
if ( is_wp_error( $reviewer_id ) ) throw new RuntimeException( $reviewer_id->get_error_message() );
( new WP_User( $reviewer_id ) )->set_role( 'shop_manager' );
$other = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
$proposal = new OneToOneProposal( $wpdb );
$approval = new OneToOneApproval( $wpdb );
$source_lock = hash( 'sha256', DB_NAME . '/' . $wpdb->prefix . '/wbm-pro-source/' . $source_id );
$order_lock = hash( 'sha256', DB_NAME . '/' . $wpdb->prefix . '/wbm-allocation/' . $order->get_id() );
$key = hash( 'sha256', 'locking-test-request-' . $source_id );
$reason = 'Finance matched a distinct confirmed bank source for concurrent review testing.';
$review_reason = 'I independently confirmed cleared funds and source/order integrity.';
$assert_blocked = static function ( callable $action, string $expected_message ): void {
	try {
		$action();
		throw new RuntimeException( 'Unsafe approval/proposal was accepted under an active SQL advisory lock.' );
	} catch ( RuntimeException $exception ) {
		if ( ! str_contains( $exception->getMessage(), $expected_message ) ) throw $exception;
	}
};
$held = null;
try {
	$held = $order_lock;
	if ( '1' !== (string) $other->get_var( $other->prepare( 'SELECT GET_LOCK(%s, 0)', $held ) ) ) {
		throw new RuntimeException( 'Could not establish independent order advisory lock.' );
	}
	$assert_blocked( static fn () => $proposal->propose(
		$order->get_id(), $source_id, $admin->ID, $key, $reason
	), 'locked for review' );
	if ( 0 !== (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->prefix}wbm_pro_allocations WHERE transaction_row_id = %d", $source_id
	) ) ) throw new RuntimeException( 'A blocked proposal created ledger entries.' );
	$other->get_var( $other->prepare( 'SELECT RELEASE_LOCK(%s)', $held ) );
	$held = null;

	$made = $proposal->propose( $order->get_id(), $source_id, $admin->ID, $key, $reason );
	if ( 'proposed' !== $made['state'] || $made['order_payment_changed'] ) throw new RuntimeException( 'Unlocked proposal did not persist.' );
	try {
		$approval->approve( $made['allocation_id'], $admin->ID, true, $review_reason );
		throw new RuntimeException( 'Maker approved their own order allocation.' );
	} catch ( InvalidArgumentException $exception ) {}
	wp_set_current_user( $reviewer_id );
	try {
		$approval->approve( $made['allocation_id'], $reviewer_id, false, $review_reason );
		throw new RuntimeException( 'Unconfirmed bank clearance was approved.' );
	} catch ( InvalidArgumentException $exception ) {}

	$held = $source_lock;
	if ( '1' !== (string) $other->get_var( $other->prepare( 'SELECT GET_LOCK(%s, 0)', $held ) ) ) {
		throw new RuntimeException( 'Could not establish independent source advisory lock.' );
	}
	$assert_blocked( static fn () => $approval->approve(
		$made['allocation_id'], $reviewer_id, true, $review_reason
	), 'currently being reconciled' );
	$stage = (string) $wpdb->get_var( $wpdb->prepare(
		"SELECT state FROM {$wpdb->prefix}wbm_pro_allocations WHERE id = %d", $made['allocation_id']
	) );
	if ( 'proposed' !== $stage ) throw new RuntimeException( 'Blocked checker changed allocation state.' );
	$other->get_var( $other->prepare( 'SELECT RELEASE_LOCK(%s)', $held ) );
	$held = null;

	$reviewed = $approval->approve( $made['allocation_id'], $reviewer_id, true, $review_reason );
	$replay = $approval->approve( $made['allocation_id'], $reviewer_id, true, $review_reason );
	$balance = ( new AllocationLedgerRepository( $wpdb ) )->snapshot(
		$order->get_id(), $source_id, 5500, 5500, 'USD', 2, $account
	);
	if ( 'approved' !== $reviewed['status'] || 'already_approved' !== $replay['status'] ||
		0 !== $balance['order_outstanding_minor'] || 0 !== $balance['credit_unallocated_minor'] ||
		$reviewed['order_payment_changed'] || wc_get_order( $order->get_id() )->is_paid() ) {
		throw new RuntimeException( 'Approval replay spent extra money or changed WooCommerce payment.' );
	}
	$events = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->prefix}wbm_pro_allocation_events WHERE allocation_id = %d", $made['allocation_id']
	) );
	if ( 2 !== $events ) throw new RuntimeException( 'Maker/checker lock challenge created extra audit entries.' );
	echo "PASS independent DB advisory order/source locks, maker-checker, explicit clearance and idempotent unpaid allocation\n";
} finally {
	if ( null !== $held ) $other->get_var( $other->prepare( 'SELECT RELEASE_LOCK(%s)', $held ) );
	wp_set_current_user( $admin->ID );
	$ids = $wpdb->get_col( $wpdb->prepare(
		"SELECT id FROM {$wpdb->prefix}wbm_pro_allocations WHERE transaction_row_id = %d", $source_id
	) );
	foreach ( $ids as $id ) $wpdb->delete( $wpdb->prefix . 'wbm_pro_allocation_events',
		[ 'allocation_id' => (int) $id ], [ '%d' ] );
	$wpdb->delete( $wpdb->prefix . 'wbm_pro_allocations', [ 'transaction_row_id' => $source_id ], [ '%d' ] );
	$wpdb->delete( $imports, [ 'batch_id' => $batch ], [ '%d' ] );
	$wpdb->delete( $wpdb->prefix . 'wbm_pro_import_batches', [ 'id' => $batch ], [ '%d' ] );
	$wpdb->delete( $wpdb->prefix . 'wbm_pro_order_bank_events', [ 'order_id' => $order->get_id() ], [ '%d' ] );
	$wpdb->delete( $wpdb->prefix . 'wbm_pro_order_receiving_accounts', [ 'order_id' => $order->get_id() ], [ '%d' ] );
	$wpdb->delete( $wpdb->prefix . 'wbm_pro_bank_accounts', [ 'id' => $account ], [ '%d' ] );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $reviewer_id );
	$order->delete( true );
}
