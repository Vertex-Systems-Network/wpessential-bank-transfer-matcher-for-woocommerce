<?php
use WPEssential\BankTransferMatcher\Domain\EntityId;
use WPEssential\BankTransferMatcher\Domain\PaymentReference;
use WPEssential\BankTransferMatcher\Orders\ReferenceRepository;
use WPEssential\BankTransferMatcherPro\Import\BankAccountRepository;
use WPEssential\BankTransferMatcherPro\Reconciliation\OrderReceivingAccountRepository;
use WPEssential\BankTransferMatcherPro\Import\ImportBatchRepository;
use WPEssential\BankTransferMatcherPro\Import\MappingProfile;
use WPEssential\BankTransferMatcherPro\Reconciliation\AllocationLedgerRepository;
use WPEssential\BankTransferMatcherPro\Reconciliation\OneToOneApproval;
use WPEssential\BankTransferMatcherPro\Reconciliation\OneToOneProposal;
use WPEssential\BankTransferMatcherPro\Reconciliation\SplitCreditAllocation;
use WPEssential\BankTransferMatcherPro\Reconciliation\CorrectionCaseRepository;
use WPEssential\BankTransferMatcherPro\Admin\AllocationReviewPage;
use WPEssential\BankTransferMatcherPro\Reconciliation\ProPaymentFinalizer;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'wbm_ci' !== DB_NAME ) {
	throw new RuntimeException( 'Run this fixture only in the disposable wbm_ci database.' );
}
global $wpdb;
$admin = get_user_by( 'login', 'wbm_ci' );
wp_set_current_user( $admin->ID );
$orders = [];
$references = [];
foreach ( [ '60.00', '40.00', '30.00' ] as $total ) {
	$order = wc_create_order();
	$order->set_payment_method( 'bacs' );
	$order->set_currency( 'USD' );
	$order->set_total( $total );
	$order->set_status( 'on-hold' );
	$order->save();
	$orders[] = $order;
	$ref = PaymentReference::issue();
	( new ReferenceRepository( $wpdb ) )->reserve( new EntityId( $order->get_id() ), $ref );
	$references[] = $ref->value;
}
$profile = MappingProfile::from_array( [
	'id' => 'split-credit-fixture', 'name' => 'Split credit fixture', 'delimiter' => ',',
	'date_header' => 'Date', 'date_format' => 'Y-m-d', 'amount_header' => 'Amount',
	'decimal_separator' => '.', 'thousands_separator' => '', 'exponent' => 2,
	'currency_header' => '', 'fixed_currency' => 'USD',
	'reference_header' => 'Reference', 'description_header' => '', 'transaction_id_header' => 'Bank ID',
] );
$account = ( new BankAccountRepository( $wpdb ) )->create(
	'Split credit test', 'Synthetic bank', 'USD', '9876', $admin->ID
);
$bank_bindings = new OrderReceivingAccountRepository( $wpdb );
foreach ( $orders as $eligible_order ) {
	$bank_bindings->confirm( $eligible_order->get_id(), $account, $admin->ID,
		'Merchant confirmed the USD receiving account for this split recipient.' );
}
$batch_repo = new ImportBatchRepository( $wpdb );
$batch = $batch_repo->create( $account, $profile, hash( 'sha256', 'split-credit-batch/' . $orders[0]->get_id() ), 100, $admin->ID );
$batch_repo->transition( $batch, 'pending', 'processing' );
$batch_repo->transition( $batch, 'processing', 'completed', 1 );
$imports = $wpdb->prefix . 'wbm_pro_import_rows';
$canonical = [
	'source_row' => 2, 'posted_date' => gmdate( 'Y-m-d' ), 'amount_minor' => 10000,
	'currency' => 'USD', 'exponent' => 2, 'reference' => $references[0],
	'description' => 'Synthetic transferred amount for two distinct orders',
	'transaction_id' => 'SPLIT-CREDIT-SYNTH-' . $orders[0]->get_id(),
];
if ( 1 !== $wpdb->insert( $imports, [
	'batch_id' => $batch, 'source_row' => 2, 'canonical_json' => wp_json_encode( $canonical ),
	'error_code' => '', 'warning_code' => '',
	'identity_fingerprint' => hash( 'sha256', 'split-source/' . $batch ),
] ) ) throw new RuntimeException( 'Could not create a synthetic unique imported credit.' );
$source_id = (int) $wpdb->insert_id;
$reviewer_id = wp_create_user( 'wbm-split-checker-' . wp_generate_password( 8, false ),
	wp_generate_password( 16 ), 'split-checker-' . wp_generate_password( 8, false ) . '@example.test' );
if ( is_wp_error( $reviewer_id ) ) throw new RuntimeException( $reviewer_id->get_error_message() );
( new WP_User( $reviewer_id ) )->set_role( 'shop_manager' );
$writer = new SplitCreditAllocation( $wpdb );
$ledger = new AllocationLedgerRepository( $wpdb );
$key = hash( 'sha256', 'split-group/' . $source_id );
$reason = 'The merchant assigned a single cleared bank transfer to two separately reviewed BACS orders.';
$approval_reason = 'I independently reviewed the cleared deposit and both allocation recipients.';
$lines = [
	[ 'order_id' => $orders[0]->get_id(), 'amount_minor' => 6000 ],
	[ 'order_id' => $orders[1]->get_id(), 'amount_minor' => 4000 ],
];
$reject = static function ( callable $action, string $description ): void {
	try {
		$action();
		throw new RuntimeException( 'Unsafe split accepted: ' . $description );
	} catch ( InvalidArgumentException $expected ) {}
};
$other_connection = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
$held_lock = null;
$expect_lock_failure = static function ( callable $operation ): void {
	try {
		$operation();
		throw new RuntimeException( 'Active split lock was ignored.' );
	} catch ( RuntimeException $error ) {
		if ( ! str_contains( $error->getMessage(), 'under concurrent reconciliation' ) ) throw $error;
	}
};
try {
	wp_set_current_user( 0 );
	try {
		$writer->propose( $source_id, $lines, $admin->ID, $key, $reason );
		throw new RuntimeException( 'Unauthenticated split proposal was allowed.' );
	} catch ( RuntimeException $expected ) {
		if ( 'Unauthenticated split proposal was allowed.' === $expected->getMessage() ) throw $expected;
	}
	wp_set_current_user( $admin->ID );
	$reject( static fn (): array => $writer->propose( $source_id, [
		[ 'order_id' => $orders[0]->get_id(), 'amount_minor' => 9000 ],
		[ 'order_id' => $orders[1]->get_id(), 'amount_minor' => 4000 ],
	], $admin->ID, hash( 'sha256', 'split-overdraw' ), $reason ), 'credit total overrun' );
	$reject( static fn (): array => $writer->propose( $source_id, [
		$lines[0], $lines[0],
	], $admin->ID, hash( 'sha256', 'split-repeat-order' ), $reason ), 'same order counted twice' );
	$reject( static fn (): array => $writer->propose( $source_id, [
		[ 'order_id' => $orders[0]->get_id(), 'amount_minor' => 6000 ],
		[ 'order_id' => $orders[2]->get_id(), 'amount_minor' => 4000 ],
	], $admin->ID, hash( 'sha256', 'split-over-order' ), $reason ), 'order balance overrun' );
	// A separate SQL connection locking the SECOND recipient blocks the entire group.
	$held_lock = hash( 'sha256', DB_NAME . '/' . $wpdb->prefix . '/wbm-allocation/' . $orders[1]->get_id() );
	if ( '1' !== (string) $other_connection->get_var(
		$other_connection->prepare( 'SELECT GET_LOCK(%s, 0)', $held_lock ) ) ) {
		throw new RuntimeException( 'Could not acquire independent split recipient lock.' );
	}
	$expect_lock_failure( static fn () => $writer->propose( $source_id, $lines, $admin->ID, $key, $reason ) );
	$other_connection->get_var( $other_connection->prepare( 'SELECT RELEASE_LOCK(%s)', $held_lock ) );
	$held_lock = null;
	if ( 0 !== (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->prefix}wbm_pro_allocations WHERE transaction_row_id = %d", $source_id
	) ) ) throw new RuntimeException( 'Rejected split lock created partial ledger rows.' );
	$proposed = $writer->propose( $source_id, $lines, $admin->ID, $key, $reason );
	$again = $writer->propose( $source_id, array_reverse( $lines ), $admin->ID, $key, $reason );
	if ( 'proposed' !== $proposed['status'] || 'already_proposed' !== $again['status'] ||
		$proposed['allocation_ids'] !== $again['allocation_ids'] ||
		count( $proposed['allocation_ids'] ) !== 2 || $proposed['order_payment_changed'] ) {
		throw new RuntimeException( 'Idempotent two-order split proposal did not persist.' );
	}
	$reject( static fn (): array => $writer->propose( $source_id, [
		[ 'order_id' => $orders[0]->get_id(), 'amount_minor' => 5000 ],
		[ 'order_id' => $orders[1]->get_id(), 'amount_minor' => 5000 ],
	], $admin->ID, $key, $reason ), 'request key changed distribution' );
	$reject( static fn (): array => $writer->propose( $source_id, $lines, $admin->ID,
		hash( 'sha256', 'competing-split-request' ), $reason ), 'source allocated twice' );
	$reject( static fn (): array => ( new OneToOneProposal( $wpdb ) )->propose(
		$orders[0]->get_id(), $source_id, $admin->ID, hash( 'sha256', 'wrong-source-ownership' ), $reason
	), 'one-to-one proposal bypassed split group' );
	$reject( static fn (): array => ( new OneToOneApproval( $wpdb ) )->approve(
		$proposed['allocation_ids'][0], $admin->ID, true, $approval_reason
	), 'single-item approval bypassed the group' );
	$events = $wpdb->prefix . 'wbm_pro_allocation_events';
	$event_count = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM $events WHERE allocation_id IN (%d, %d)",
		$proposed['allocation_ids'][0], $proposed['allocation_ids'][1]
	) );
	if ( 2 !== $event_count ) throw new RuntimeException( 'Split proposal audit event missing or duplicated.' );
	// The proposer cannot approve their own group.
	$reject( static fn (): array => $writer->approve( $key, $admin->ID, true, $approval_reason ),
		'same-person approval' );
	wp_set_current_user( $reviewer_id );
	$reject( static fn (): array => $writer->approve( $key, $reviewer_id, false, $approval_reason ),
		'approval without confirmed cleared credit' );
	// Source identity changing after proposal invalidates the entire group.
	$wpdb->update( $imports, [ 'warning_code' => 'possible_duplicate' ], [ 'id' => $source_id ] );
	$reject( static fn (): array => $writer->approve( $key, $reviewer_id, true, $approval_reason ),
		'credit flagged as duplicate' );
	$wpdb->update( $imports, [ 'warning_code' => '' ], [ 'id' => $source_id ] );
	$orders[1]->set_total( '41.00' );
	$orders[1]->save();
	$reject( static fn (): array => $writer->approve( $key, $reviewer_id, true, $approval_reason ),
		'changed WooCommerce order total' );
	$orders[1]->set_total( '40.00' );
	$orders[1]->save();
	// Bank-source lock held by a second session prevents ALL recipient approvals.
	$held_lock = hash( 'sha256', DB_NAME . '/' . $wpdb->prefix . '/wbm-pro-source/' . $source_id );
	if ( '1' !== (string) $other_connection->get_var(
		$other_connection->prepare( 'SELECT GET_LOCK(%s, 0)', $held_lock ) ) ) {
		throw new RuntimeException( 'Could not acquire independent bank source lock.' );
	}
	$expect_lock_failure( static fn () => $writer->approve( $key, $reviewer_id, true, $approval_reason ) );
	$other_connection->get_var( $other_connection->prepare( 'SELECT RELEASE_LOCK(%s)', $held_lock ) );
	$held_lock = null;
	$proposed_count = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->prefix}wbm_pro_allocations
		 WHERE split_group_key = %s AND state = 'proposed'", $key
	) );
	if ( 2 !== $proposed_count ) throw new RuntimeException( 'Split lock rejection partially approved a group.' );
	$approved = $writer->approve( $key, $reviewer_id, true, $approval_reason );
	$replay = $writer->approve( $key, $reviewer_id, true, $approval_reason );
	if ( 'approved' !== $approved['status'] || 'already_approved' !== $replay['status'] ||
		$approved['order_payment_changed'] || $approved['allocation_ids'] !== $proposed['allocation_ids'] ) {
		throw new RuntimeException( 'Two-person split approval and idempotent replay failed.' );
	}
	foreach ( [ 6000, 4000 ] as $idx => $amount ) {
		$balance = $ledger->snapshot( $orders[$idx]->get_id(), $source_id, $amount, 10000, 'USD', 2, $account );
		if ( 0 !== $balance['order_outstanding_minor'] ||
			10000 !== $balance['credit_allocated_minor'] ||
			0 !== $balance['credit_unallocated_minor'] ||
			wc_get_order( $orders[$idx]->get_id() )->is_paid() ) {
			throw new RuntimeException( 'Split balance incorrect or order was incorrectly marked paid.' );
		}
	}
	$events_count = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM $events WHERE allocation_id IN (%d, %d)",
		$proposed['allocation_ids'][0], $proposed['allocation_ids'][1]
	) );
	if ( 4 !== $events_count ) throw new RuntimeException( 'Group approval audit events were not atomic.' );
	echo "PASS atomic two-order split, maker-checker, idempotency, duplicate/drift guards and unchanged payment status\n";

	// Finalization must lock every recipient order, not just the one being paid.
	$pay = new ProPaymentFinalizer( $wpdb );
	$pay_reason = 'I confirmed the full, independently approved split bank source and each order balance.';
	$pay_key_one = hash( 'sha256', 'split-pay/' . $orders[0]->get_id() );
	$pay_key_two = hash( 'sha256', 'split-pay/' . $orders[1]->get_id() );
	$held_lock = hash( 'sha256', DB_NAME . '/' . $wpdb->prefix . '/wbm-allocation/' . $orders[1]->get_id() );
	if ( '1' !== (string) $other_connection->get_var(
		$other_connection->prepare( 'SELECT GET_LOCK(%s, 0)', $held_lock ) ) ) {
		throw new RuntimeException( 'Could not lock other split recipient during finalization.' );
	}
	try {
		$pay->complete( $orders[0]->get_id(), $reviewer_id, $pay_key_one, true, $pay_reason );
		throw new RuntimeException( 'Split payment completed while another recipient was locked.' );
	} catch ( RuntimeException $expected ) {
		if ( ! str_contains( $expected->getMessage(), 'concurrent reconciliation' ) ) throw $expected;
	}
	$other_connection->get_var( $other_connection->prepare( 'SELECT RELEASE_LOCK(%s)', $held_lock ) );
	$held_lock = null;
	if ( wc_get_order( $orders[0]->get_id() )->is_paid() ) {
		throw new RuntimeException( 'Contended split recipient was silently marked paid.' );
	}
	$paid_one = $pay->complete( $orders[0]->get_id(), $reviewer_id, $pay_key_one, true, $pay_reason );
	if ( 'completed' !== $paid_one['status'] || ! $paid_one['order_payment_changed'] ||
		! wc_get_order( $orders[0]->get_id() )->is_paid() ||
		wc_get_order( $orders[1]->get_id() )->is_paid() ) {
		throw new RuntimeException( 'First split order did not finalize independently.' );
	}
	$paid_two = $pay->complete( $orders[1]->get_id(), $reviewer_id, $pay_key_two, true, $pay_reason );
	$replay_one = $pay->complete( $orders[0]->get_id(), $reviewer_id, $pay_key_one, true, $pay_reason );
	if ( 'completed' !== $paid_two['status'] || ! $paid_two['order_payment_changed'] ||
		'already_completed' !== $replay_one['status'] || $replay_one['order_payment_changed'] ||
		! wc_get_order( $orders[1]->get_id() )->is_paid() ) {
		throw new RuntimeException( 'Shared bank credit split was paid twice or failed finalization.' );
	}
	$committed_count = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->prefix}wbm_pro_allocations
		 WHERE split_group_key = %s AND state = 'committed'", $key
	) );
	$final_events = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM $events WHERE allocation_id IN (%d, %d)",
		$proposed['allocation_ids'][0], $proposed['allocation_ids'][1]
	) );
	if ( 2 !== $committed_count || 6 !== $final_events ) {
		throw new RuntimeException( 'Split source ledger and payment audit did not commit consistently.' );
	}
	echo "PASS split source paid exactly once per recipient, cross-recipient locks and idempotent WooCommerce finalization\n";

	// A post-payment correction on either split recipient must HOLD the
	// common source for BOTH recipients, without performing a refund.
	$correction = new CorrectionCaseRepository( $wpdb );
	// Creation itself must acquire the other recipient's order lock.
	$held_lock = hash( 'sha256', DB_NAME . '/' . $wpdb->prefix . '/wbm-allocation/' . $orders[1]->get_id() );
	if ( '1' !== (string) $other_connection->get_var(
		$other_connection->prepare( 'SELECT GET_LOCK(%s, 0)', $held_lock ) ) ) {
		throw new RuntimeException( 'Could not establish competing split correction lock.' );
	}
	try {
		$correction->raise( $proposed['allocation_ids'][0], $reviewer_id,
			hash( 'sha256', 'split-disputed-source/' . $source_id ), 'duplicate_credit',
			'The shared bank transfer needs investigation for duplicate deposit provenance.'
		);
		throw new RuntimeException( 'Split correction bypassed locked second recipient.' );
	} catch ( RuntimeException $expected ) {
		if ( ! str_contains( $expected->getMessage(), 'concurrent financial reconciliation' ) ) throw $expected;
	}
	$other_connection->get_var( $other_connection->prepare( 'SELECT RELEASE_LOCK(%s)', $held_lock ) );
	$held_lock = null;
	$case = $correction->raise( $proposed['allocation_ids'][0], $reviewer_id,
		hash( 'sha256', 'split-disputed-source/' . $source_id ), 'duplicate_credit',
		'The shared bank transfer needs investigation for duplicate deposit provenance.'
	);
	try {
		\WPEssential\BankTransferMatcher\Evidence\CorrectionHoldGuard::assert_no_hold(
			$wpdb, $orders[1]->get_id()
		);
		throw new RuntimeException( 'Free writer did not see the split-bank credit correction hold.' );
	} catch ( InvalidArgumentException $expected ) {}
	wp_set_current_user( $admin->ID );
	$reviewed = $correction->review( $case['case_id'], $admin->ID,
		'continue_hold', 'I independently reviewed the duplicate source; no refund or payment action is authorized.' );
	if ( 'reviewed' !== $reviewed['status'] || $reviewed['ledger_changed'] ||
		$reviewed['order_payment_changed'] ) {
		throw new RuntimeException( 'Post-payment correction changed split ledger or payment.' );
	}
	try {
		\WPEssential\BankTransferMatcher\Evidence\CorrectionHoldGuard::assert_no_hold(
			$wpdb, $orders[1]->get_id(), $source_id
		);
		throw new RuntimeException( 'Reviewed split correction silently released the hold.' );
	} catch ( InvalidArgumentException $expected ) {}
	if ( ! wc_get_order( $orders[0]->get_id() )->is_paid() ||
		! wc_get_order( $orders[1]->get_id() )->is_paid() ) {
		throw new RuntimeException( 'Correction investigation incorrectly reversed a paid WooCommerce order.' );
	}
	echo "PASS immutable paid-source split correction blocks all recipient orders without refund or ledger change\n";
	$before_get = $_GET;
	try {
		$_GET = [ 'page' => 'wpessential-wbm-pro-allocations', 'state' => 'committed',
			'order_id' => (string) $orders[0]->get_id() ];
		ob_start();
		AllocationReviewPage::render();
		$queue_html = ob_get_clean();
		foreach ( [ 'Bank allocation ledger', 'Visible page totals by currency and state',
			'committed', 'View audit', '100.00', 'USD' ] as $needle ) {
			if ( ! str_contains( $queue_html, $needle ) ) {
				throw new RuntimeException( 'Native bank allocation queue lacks ' . $needle );
			}
		}
		$_GET = [ 'page' => 'wpessential-wbm-pro-allocations',
			'allocation_id' => (string) $proposed['allocation_ids'][0] ];
		ob_start();
		AllocationReviewPage::render();
		$workspace_html = ob_get_clean();
		foreach ( [ 'Allocation and provenance', 'Live WooCommerce order', 'Imported bank source',
			'Financial hold', 'Correction and reversal investigations',
			'Append-only correction audit history', 'Append-only allocation audit history',
			'No finance mutation actions are enabled' ] as $needle ) {
			if ( ! str_contains( $workspace_html, $needle ) ) {
				throw new RuntimeException( 'Native allocation audit workspace lacks ' . $needle );
			}
		}
		if ( str_contains( $workspace_html, '<form method="post"' ) ||
			str_contains( $workspace_html, 'admin-post.php' ) ) {
			throw new RuntimeException( 'Read-only finance workspace exposes unintended mutation controls.' );
		}
	} finally {
		$_GET = $before_get;
	}
	echo "PASS WordPress-native allocation queue, masked source, correction audit and no financial mutation forms\n";
} finally {
	if ( null !== $held_lock ) $other_connection->get_var( $other_connection->prepare( 'SELECT RELEASE_LOCK(%s)', $held_lock ) );
	wp_set_current_user( $admin->ID );
	$wpdb->delete( $wpdb->prefix . 'wbm_pro_payment_finalizations', [ 'order_id' => $orders[0]->get_id() ], [ '%d' ] );
	$wpdb->delete( $wpdb->prefix . 'wbm_pro_payment_finalizations', [ 'order_id' => $orders[1]->get_id() ], [ '%d' ] );
	$case_table = $wpdb->prefix . 'wbm_pro_correction_cases';
	$case_ids = $wpdb->get_col( $wpdb->prepare(
		"SELECT id FROM $case_table WHERE transaction_row_id = %d", $source_id
	) );
	foreach ( $case_ids as $case_id ) {
		$wpdb->delete( $wpdb->prefix . 'wbm_pro_correction_events',
			[ 'correction_case_id' => (int) $case_id ], [ '%d' ] );
	}
	$wpdb->delete( $case_table, [ 'transaction_row_id' => $source_id ], [ '%d' ] );
	$allocations = $wpdb->prefix . 'wbm_pro_allocations';
	$ids = $wpdb->get_col( $wpdb->prepare(
		"SELECT id FROM $allocations WHERE transaction_row_id = %d", $source_id
	) );
	foreach ( $ids as $id ) $wpdb->delete( $wpdb->prefix . 'wbm_pro_allocation_events',
		[ 'allocation_id' => (int) $id ], [ '%d' ] );
	$wpdb->delete( $allocations, [ 'transaction_row_id' => $source_id ], [ '%d' ] );
	$wpdb->delete( $imports, [ 'batch_id' => $batch ], [ '%d' ] );
	$wpdb->delete( $wpdb->prefix . 'wbm_pro_import_batches', [ 'id' => $batch ], [ '%d' ] );
	$wpdb->query( "DELETE FROM {$wpdb->prefix}wbm_pro_order_bank_events WHERE bank_account_id = " . (int) $account );
	$wpdb->query( "DELETE FROM {$wpdb->prefix}wbm_pro_order_receiving_accounts WHERE bank_account_id = " . (int) $account );
	$wpdb->delete( $wpdb->prefix . 'wbm_pro_bank_accounts', [ 'id' => $account ], [ '%d' ] );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $reviewer_id );
	foreach ( $orders as $order ) $order->delete( true );
}
