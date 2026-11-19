<?php
use WPEssential\BankTransferMatcher\Domain\EntityId;
use WPEssential\BankTransferMatcher\Domain\PaymentReference;
use WPEssential\BankTransferMatcher\Domain\Money;
use WPEssential\BankTransferMatcher\Evidence\AllocationRepository;
use WPEssential\BankTransferMatcher\Evidence\EvidenceRepository;
use WPEssential\BankTransferMatcher\Orders\ReferenceRepository;
use WPEssential\BankTransferMatcherPro\Import\BankAccountRepository;
use WPEssential\BankTransferMatcherPro\Reconciliation\OrderReceivingAccountRepository;
use WPEssential\BankTransferMatcherPro\Import\ImportBatchRepository;
use WPEssential\BankTransferMatcherPro\Import\MappingProfile;
use WPEssential\BankTransferMatcherPro\Reconciliation\AllocationLedgerRepository;
use WPEssential\BankTransferMatcherPro\Reconciliation\OneToOneApproval;
use WPEssential\BankTransferMatcherPro\Reconciliation\OneToOneProposal;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'wbm_ci' !== DB_NAME ) {
	throw new RuntimeException( 'Run only against disposable wbm_ci database.' );
}
global $wpdb;
$admin = get_user_by( 'login', 'wbm_ci' );
wp_set_current_user( $admin->ID );
$order = wc_create_order();
$order->set_payment_method( 'bacs' );
$order->set_currency( 'USD' );
$order->set_total( '100.00' );
$order->set_status( 'on-hold' );
$order->save();
$reference = PaymentReference::issue();
( new ReferenceRepository( $wpdb ) )->reserve( new EntityId( $order->get_id() ), $reference );
$account = ( new BankAccountRepository( $wpdb ) )->create( 'Partial fixture', 'Synthetic bank', 'USD', '6543', $admin->ID );
$binding = ( new OrderReceivingAccountRepository( $wpdb ) )->confirm(
	$order->get_id(), $account, $admin->ID,
	'Merchant explicitly selected the verified USD receiving bank account for this order.'
);
if ( $binding['bank_account_id'] !== $account ) throw new RuntimeException( 'Binding was not confirmed.' );
$profile = MappingProfile::from_array( [
	'id' => 'partial-fixture', 'name' => 'Partial fixture', 'delimiter' => ',',
	'date_header' => 'Date', 'date_format' => 'Y-m-d', 'amount_header' => 'Amount',
	'decimal_separator' => '.', 'thousands_separator' => '', 'exponent' => 2,
	'currency_header' => '', 'fixed_currency' => 'USD', 'reference_header' => 'Reference',
	'description_header' => '', 'transaction_id_header' => 'Bank ID',
] );
$batch_repo = new ImportBatchRepository( $wpdb );
$batch = $batch_repo->create( $account, $profile, hash( 'sha256', 'multi-credit-fixture-' . $order->get_id() ), 100, $admin->ID );
$batch_repo->transition( $batch, 'pending', 'processing' );
$batch_repo->transition( $batch, 'processing', 'completed', 4 );
$table = $wpdb->prefix . 'wbm_pro_import_rows';
$source_ids = [];
foreach ( [ 2500, 3500, 4000, 5000 ] as $index => $minor ) {
	$payload = [
		'source_row' => $index + 2, 'posted_date' => gmdate( 'Y-m-d' ),
		'amount_minor' => $minor, 'currency' => 'USD', 'exponent' => 2,
		'reference' => $reference->value, 'description' => 'Synthetic bank credit ' . $index,
		'transaction_id' => 'MULTI-' . $index . '-' . $order->get_id(),
	];
	$inserted = $wpdb->insert( $table, [
		'batch_id' => $batch, 'source_row' => $index + 2,
		'canonical_json' => wp_json_encode( $payload ), 'error_code' => '', 'warning_code' => '',
		'identity_fingerprint' => hash( 'sha256', 'multi-credit-source/' . $batch . '/' . $index ),
	] );
	if ( 1 !== $inserted ) throw new RuntimeException( 'Synthetic staged credit insertion failed.' );
	$source_ids[] = (int) $wpdb->insert_id;
}
$maker = new OneToOneProposal( $wpdb );
$checker = new OneToOneApproval( $wpdb );
$ledger = new AllocationLedgerRepository( $wpdb );
$reviewer_id = wp_create_user( 'wbm-partial-reviewer-' . wp_generate_password( 8, false ), wp_generate_password( 16 ), 'partial-' . wp_generate_password( 8, false ) . '@example.test' );
if ( is_wp_error( $reviewer_id ) ) throw new RuntimeException( $reviewer_id->get_error_message() );
( new WP_User( $reviewer_id ) )->set_role( 'shop_manager' );
$proposals = [];
$credit_reason = 'Synthetic bank credit is unique and matches the merchant order.';
$review_reason = 'Cleared synthetic bank credit checked by independent reviewer.';
$failed = static function ( callable $action, string $label ): void {
	try {
		$action();
		throw new RuntimeException( "$label was accepted." );
	} catch ( InvalidArgumentException $exception ) {
		if ( "$label was accepted." === $exception->getMessage() ) throw $exception;
	}
};
$assert_outstanding = static function ( int $expected, int $source_id, int $credit_minor ) use ( $ledger, $order, $account ): void {
	$balance = $ledger->snapshot( $order->get_id(), $source_id, 10000, $credit_minor, 'USD', 2, $account );
	if ( $expected !== $balance['order_outstanding_minor'] ) {
		throw new RuntimeException( 'Incorrect remaining order balance: ' . wp_json_encode( $balance ) );
	}
	if ( wc_get_order( $order->get_id() )->is_paid() || ! wc_get_order( $order->get_id() )->has_status( 'on-hold' ) ) {
		throw new RuntimeException( 'Partially funded order changed WooCommerce payment state.' );
	}
};
try {
	// A manually approved credit with the same date, amount and reference is NOT another deposit.
	$manual_repo = new AllocationRepository( $wpdb );
	$evidence_repo = new EvidenceRepository( $wpdb );
	$manual = $evidence_repo->record( $order->get_id(), $admin->ID, gmdate( 'Y-m-d' ),
		new Money( 2500, 'USD', 2 ), $reference->value, 'Same source as imported statement row' );
	$manual_repo->allocate( $order->get_id(), $manual['id'], $admin->ID,
		new Money( 10000, 'USD', 2 ), new Money( 2500, 'USD', 2 ),
		'Synthetic manual allocation predates the import review' );
	$failed( static fn (): array => $maker->propose( $order->get_id(), $source_ids[0], $admin->ID,
		hash( 'sha256', 'overlapping-manual-and-import' ), $credit_reason ), 'Imported credit duplicating approved Free evidence' );
	$wpdb->delete( $wpdb->prefix . 'wbm_manual_allocations', [ 'order_id' => $order->get_id() ], [ '%d' ] );
	$wpdb->delete( $wpdb->prefix . 'wbm_bank_evidence', [ 'id' => $manual['id'] ], [ '%d' ] );
	// Three distinct full credits can be proposed concurrently; proposals do not reserve order balance.
	foreach ( array_slice( $source_ids, 0, 3 ) as $i => $source_id ) {
		$proposals[] = $maker->propose( $order->get_id(), $source_id, $admin->ID,
			hash( 'sha256', 'multi-credit-request/' . $source_id ), $credit_reason );
		if ( 'proposed' !== $proposals[$i]['state'] ) throw new RuntimeException( 'Partial proposal status is incorrect.' );
	}
	$assert_outstanding( 10000, $source_ids[0], 2500 );
	$failed( static fn (): array => $maker->propose( $order->get_id(), $source_ids[0], $admin->ID,
		hash( 'sha256', 'multi-credit-second-request' ), $credit_reason ), 'Duplicate credit proposal' );
	wp_set_current_user( $reviewer_id );
	// Source fingerprint becoming ambiguous after proposal prevents approval without ledger changes.
	$original_fingerprint = $wpdb->get_var( $wpdb->prepare( "SELECT identity_fingerprint FROM $table WHERE id = %d", $source_ids[0] ) );
	$wpdb->insert( $table, [
		'batch_id' => $batch, 'source_row' => 10, 'canonical_json' => wp_json_encode( [ 'amount_minor' => 2500 ] ),
		'error_code' => '', 'warning_code' => 'possible_duplicate', 'identity_fingerprint' => $original_fingerprint,
	] );
	$duplicate_id = (int) $wpdb->insert_id;
	try {
		$failed( static fn (): array => $checker->approve( $proposals[0]['allocation_id'], $reviewer_id, true, $review_reason ),
			'Ambiguous bank credit approval' );
	} finally {
		if ( $duplicate_id > 0 ) $wpdb->delete( $table, [ 'id' => $duplicate_id ], [ '%d' ] );
	}
	foreach ( [ 7500, 4000 ] as $i => $outstanding ) {
		$approved = $checker->approve( $proposals[$i]['allocation_id'], $reviewer_id, true, $review_reason );
		if ( 'approved' !== $approved['status'] || $approved['order_payment_changed'] ) {
			throw new RuntimeException( 'Reviewed partial payment was not recorded correctly.' );
		}
		$assert_outstanding( $outstanding, $source_ids[$i], [ 2500, 3500 ][$i] );
		if ( 0 === $i ) {
			// Even with $75 still due, Free must not count the imported $25 as a second credit.
			wp_set_current_user( $admin->ID );
			$replay_evidence = $evidence_repo->record( $order->get_id(), $admin->ID, gmdate( 'Y-m-d' ),
				new Money( 2500, 'USD', 2 ), $reference->value, 'Same deposit as first Pro approval' );
			$failed( static fn (): array => $manual_repo->allocate( $order->get_id(), $replay_evidence['id'], $admin->ID,
				new Money( 10000, 'USD', 2 ), new Money( 2500, 'USD', 2 ),
				'Duplicate bank deposit was not a new payment' ), 'Free credit duplicating approved Pro evidence' );
			$wpdb->delete( $wpdb->prefix . 'wbm_bank_evidence', [ 'id' => $replay_evidence['id'] ], [ '%d' ] );
			wp_set_current_user( $reviewer_id );
		}
	}
	wp_set_current_user( $admin->ID );
	$failed( static fn (): array => $maker->propose( $order->get_id(), $source_ids[3], $admin->ID,
		hash( 'sha256', 'multi-credit-overflow-request' ), $credit_reason ), 'Over-allocating credit proposal' );
	// Changing the WooCommerce total between proposal and approval must block the final credit.
	$order->set_total( '110.00' );
	$order->save();
	wp_set_current_user( $reviewer_id );
	$failed( static fn (): array => $checker->approve( $proposals[2]['allocation_id'], $reviewer_id, true, $review_reason ),
		'Changed-order total approval' );
	$order->set_total( '100.00' );
	$order->save();
	$last = $checker->approve( $proposals[2]['allocation_id'], $reviewer_id, true, $review_reason );
	if ( 'approved' !== $last['status'] || $last['order_payment_changed'] ) {
		throw new RuntimeException( 'Final reviewed credit did not settle the internal balance.' );
	}
	$assert_outstanding( 0, $source_ids[2], 4000 );
	$events = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->prefix}wbm_pro_allocation_events WHERE allocation_id = %d",
		$proposals[2]['allocation_id']
	) );
	if ( 2 !== $events ) throw new RuntimeException( 'Failed approval attempt changed append-only audit.' );
	echo "PASS three credits fund one order; duplicate, drift and over-allocation blocked, maker-checker audit, no paid transition\n";
} finally {
	wp_set_current_user( $admin->ID );
	$allocations = $wpdb->prefix . 'wbm_pro_allocations';
	$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $allocations WHERE order_id = %d", $order->get_id() ) );
	foreach ( $ids as $id ) $wpdb->delete( $wpdb->prefix . 'wbm_pro_allocation_events', [ 'allocation_id' => (int) $id ], [ '%d' ] );
	$wpdb->delete( $allocations, [ 'order_id' => $order->get_id() ], [ '%d' ] );
	$wpdb->delete( $wpdb->prefix . 'wbm_manual_allocations', [ 'order_id' => $order->get_id() ], [ '%d' ] );
	$wpdb->delete( $wpdb->prefix . 'wbm_bank_evidence', [ 'order_id' => $order->get_id() ], [ '%d' ] );
	$wpdb->delete( $table, [ 'batch_id' => $batch ], [ '%d' ] );
	$wpdb->delete( $wpdb->prefix . 'wbm_pro_import_batches', [ 'id' => $batch ], [ '%d' ] );
	$wpdb->delete( $wpdb->prefix . 'wbm_pro_order_bank_events', [ 'order_id' => $order->get_id() ], [ '%d' ] );
	$wpdb->delete( $wpdb->prefix . 'wbm_pro_order_receiving_accounts', [ 'order_id' => $order->get_id() ], [ '%d' ] );
	$wpdb->delete( $wpdb->prefix . 'wbm_pro_bank_accounts', [ 'id' => $account ], [ '%d' ] );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $reviewer_id );
	$order->delete( true );
}
