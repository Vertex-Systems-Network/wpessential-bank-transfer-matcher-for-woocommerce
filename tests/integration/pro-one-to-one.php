<?php
use WPEssential\BankTransferMatcher\Domain\EntityId;
use WPEssential\BankTransferMatcher\Domain\PaymentReference;
use WPEssential\BankTransferMatcher\Orders\ReferenceRepository;
use WPEssential\BankTransferMatcherPro\Import\BankAccountRepository;
use WPEssential\BankTransferMatcherPro\Reconciliation\OrderReceivingAccountRepository;
use WPEssential\BankTransferMatcherPro\Import\ImportBatchRepository;
use WPEssential\BankTransferMatcherPro\Import\MappingProfile;
use WPEssential\BankTransferMatcherPro\Reconciliation\OneToOneProposal;
use WPEssential\BankTransferMatcherPro\Reconciliation\AllocationLedgerRepository;
use WPEssential\BankTransferMatcherPro\Reconciliation\CorrectionCaseRepository;
use WPEssential\BankTransferMatcherPro\Reconciliation\ProPaymentFinalizer;
use WPEssential\BankTransferMatcher\Evidence\PaymentFinalizer;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'wbm_ci' !== DB_NAME ) throw new RuntimeException( 'Disposable wbm_ci database required.' );
global $wpdb;
$admin = get_user_by( 'login', 'wbm_ci' );
wp_set_current_user( $admin->ID );
$order = wc_create_order();
$order->set_payment_method( 'bacs' );
$order->set_currency( 'USD' );
$order->set_total( '20.00' );
$order->set_status( 'on-hold' );
$order->save();
$reference = PaymentReference::issue();
( new ReferenceRepository( $wpdb ) )->reserve( new EntityId( $order->get_id() ), $reference );
$account = ( new BankAccountRepository( $wpdb ) )->create( 'One-to-one fixture', 'Synthetic bank', 'USD', '4321', $admin->ID );
$binding = ( new OrderReceivingAccountRepository( $wpdb ) )->confirm(
	$order->get_id(), $account, $admin->ID,
	'Merchant explicitly selected the verified USD receiving bank account for this order.'
);
if ( $binding['bank_account_id'] !== $account ) throw new RuntimeException( 'Binding was not confirmed.' );
$profile = MappingProfile::from_array( [
	'id' => 'one-to-one-fixture', 'name' => 'One-to-one fixture', 'delimiter' => ',',
	'date_header' => 'Date', 'date_format' => 'Y-m-d', 'amount_header' => 'Amount',
	'decimal_separator' => '.', 'thousands_separator' => '', 'exponent' => 2,
	'currency_header' => '', 'fixed_currency' => 'USD', 'reference_header' => 'Reference',
	'description_header' => '', 'transaction_id_header' => 'Bank ID',
] );
$batch = ( new ImportBatchRepository( $wpdb ) )->create( $account, $profile, hash( 'sha256', 'synthetic-one-to-one-' . $order->get_id() ), 100, $admin->ID );
$repository = new ImportBatchRepository( $wpdb );
$repository->transition( $batch, 'pending', 'processing' );
$repository->transition( $batch, 'processing', 'completed', 1 );
$rows = $wpdb->prefix . 'wbm_pro_import_rows';
$transaction = [
	'source_row' => 2, 'posted_date' => gmdate( 'Y-m-d' ), 'amount_minor' => 2000,
	'currency' => 'USD', 'exponent' => 2, 'reference' => $reference->value,
	'description' => 'Synthetic bank credit', 'transaction_id' => 'UNIQUE-ONE-TO-ONE',
];
if ( 1 !== $wpdb->insert( $rows, [
	'batch_id' => $batch, 'source_row' => 2, 'canonical_json' => wp_json_encode( $transaction ),
	'error_code' => '', 'identity_fingerprint' => hash( 'sha256', 'unique-source-' . $batch ),
	'warning_code' => '',
] ) ) throw new RuntimeException( 'Could not insert synthetic credit.' );
$source_id = (int) $wpdb->insert_id;
$allocator = new OneToOneProposal( $wpdb );
$key = hash( 'sha256', 'one-to-one-request/' . $source_id );
$reason = 'Matched a unique synthetic bank credit for finance review.';
try {
	wp_set_current_user( 0 );
	try {
		$allocator->propose( $order->get_id(), $source_id, $admin->ID, $key, $reason );
		throw new RuntimeException( 'Anonymous proposal accepted.' );
	} catch ( RuntimeException $error ) {
		if ( 'Anonymous proposal accepted.' === $error->getMessage() ) throw $error;
	}
	wp_set_current_user( $admin->ID );
	$first = $allocator->propose( $order->get_id(), $source_id, $admin->ID, $key, $reason );
	$replay = $allocator->propose( $order->get_id(), $source_id, $admin->ID, $key, $reason );
	if ( $first !== $replay || 'proposed' !== $first['state'] || $first['order_payment_changed'] ||
		wc_get_order( $order->get_id() )->is_paid() ) throw new RuntimeException( 'Proposal replay or payment guard failed.' );
	$balance = ( new AllocationLedgerRepository( $wpdb ) )->snapshot( $order->get_id(), $source_id, 2000, 2000, 'USD', 2, $account );
	if ( 2000 !== $balance['order_outstanding_minor'] || 2000 !== $balance['credit_unallocated_minor'] ) {
		throw new RuntimeException( 'Unapproved proposal consumed a balance.' );
	}
	$events = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->prefix}wbm_pro_allocation_events WHERE allocation_id = %d", $first['allocation_id']
	) );
	if ( 1 !== $events ) throw new RuntimeException( 'Proposal event missing or replay duplicated.' );
	try {
		$allocator->propose( $order->get_id(), $source_id, $admin->ID, hash( 'sha256', 'second-request' ), $reason );
		throw new RuntimeException( 'Duplicate proposal accepted.' );
	} catch ( InvalidArgumentException $error ) {
		if ( 'Duplicate proposal accepted.' === $error->getMessage() ) throw $error;
	}
	
	// A distinct finance reviewer must explicitly confirm the cleared credit.
	$reviewer_id = wp_create_user( 'wbm-checker-' . wp_generate_password( 8, false ), wp_generate_password( 16 ), 'checker-' . wp_generate_password( 8, false ) . '@example.test' );
	if ( is_wp_error( $reviewer_id ) ) throw new RuntimeException( $reviewer_id->get_error_message() );
	$reviewer_user = new WP_User( $reviewer_id );
	$reviewer_user->set_role( 'shop_manager' );
	$approval = new \WPEssential\BankTransferMatcherPro\Reconciliation\OneToOneApproval( $wpdb );
	try {
		try {
			$approval->approve( (int) $first['allocation_id'], $admin->ID, true, 'I checked the cleared synthetic credit.' );
			throw new RuntimeException( 'Same-person finance approval succeeded.' );
		} catch ( InvalidArgumentException $error ) {
			if ( 'Same-person finance approval succeeded.' === $error->getMessage() ) throw $error;
		}
		wp_set_current_user( $reviewer_id );
		try {
			$approval->approve( (int) $first['allocation_id'], $reviewer_id, false, 'I checked the cleared synthetic credit.' );
			throw new RuntimeException( 'Unconfirmed finance approval succeeded.' );
		} catch ( InvalidArgumentException $error ) {
			if ( 'Unconfirmed finance approval succeeded.' === $error->getMessage() ) throw $error;
		}
		$approved = $approval->approve( (int) $first['allocation_id'], $reviewer_id, true, 'Cleared credit checked in synthetic bank records.' );
		$again = $approval->approve( (int) $first['allocation_id'], $reviewer_id, true, 'Cleared credit checked in synthetic bank records.' );
		if ( 'approved' !== $approved['status'] || 'already_approved' !== $again['status'] ||
			$approved['order_payment_changed'] || $again['order_payment_changed'] ||
			wc_get_order( $order->get_id() )->is_paid() ) {
			throw new RuntimeException( 'Separate approval or unchanged WooCommerce payment failed.' );
		}
		$approved_balance = ( new AllocationLedgerRepository( $wpdb ) )->snapshot( $order->get_id(), $source_id, 2000, 2000, 'USD', 2, $account );
		if ( 0 !== $approved_balance['order_outstanding_minor'] || 0 !== $approved_balance['credit_unallocated_minor'] ) {
			throw new RuntimeException( 'Reviewed ledger failed to allocate exact bank credit.' );
		}
		$history = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}wbm_pro_allocation_events WHERE allocation_id = %d", $first['allocation_id']
		) );
		if ( 2 !== $history ) throw new RuntimeException( 'Approval audit history was duplicated or omitted.' );
		echo "PASS second-reviewer one-to-one allocation, idempotent approval and unpaid WooCommerce order\n";

		// A suspected bank reversal is documented, NOT applied to the Pro
		// allocation, and blocks both Pro and Free finalization.
		$corrections = new CorrectionCaseRepository( $wpdb );
		$case_key = hash( 'sha256', 'test-pro-correction/' . $first['allocation_id'] );
		$case_reason = 'The finance reviewer received a bank reversal notice and must investigate the credit.';
		$review_note = 'I independently reviewed the reversal notice and require a new bank statement.';
		$case = $corrections->raise(
			$first['allocation_id'], $reviewer_id, $case_key, 'bank_reversal_notice', $case_reason
		);
		$case_replay = $corrections->raise(
			$first['allocation_id'], $reviewer_id, $case_key, 'bank_reversal_notice', $case_reason
		);
		if ( 'open' !== $case['status'] || 'already_open' !== $case_replay['status'] ||
			$case['case_id'] !== $case_replay['case_id'] ||
			$case['order_payment_changed'] || $case['ledger_changed'] ) {
			throw new RuntimeException( 'Correction case creation and idempotency failed.' );
		}
		$reject_case = static function ( callable $fn, string $label ): void {
			try {
				$fn();
				throw new RuntimeException( 'Correction financial hold was bypassed: ' . $label );
			} catch ( InvalidArgumentException $expected ) {}
		};
		$reject_case( static fn () => $corrections->raise(
			$first['allocation_id'], $reviewer_id, hash( 'sha256', 'second-correction-key' ),
			'bank_reversal_notice', $case_reason
		), 'second case for same allocation' );
		$reject_case( static fn () => $approval->approve(
			$first['allocation_id'], $reviewer_id, true, $review_note
		), 'approved Pro allocation replay with a correction hold' );
		$reject_case( static fn () => ( new ProPaymentFinalizer( $wpdb ) )->complete(
			$order->get_id(), $reviewer_id, hash( 'sha256', 'case-held-finalization' ),
			true, 'Source remains disputed and is not eligible for payment finalization.'
		), 'Pro finalization while a correction exists' );
		$reject_case( static fn () => ( new PaymentFinalizer( $wpdb ) )->complete(
			$order->get_id(), $reviewer_id
		), 'Free finalization while a Pro correction exists' );
		$source_rows = $wpdb->prefix . 'wbm_pro_import_rows';
		$wpdb->update( $source_rows, [ 'warning_code' => 'possible_duplicate' ], [ 'id' => $source_id ] );
		wp_set_current_user( $admin->ID );
		$reject_case( static fn () => $corrections->review(
			$case['case_id'], $admin->ID, 'request_bank_restatement', $review_note
		), 'changed source warning was not detected' );
		$wpdb->update( $source_rows, [ 'warning_code' => '' ], [ 'id' => $source_id ] );
		$order->set_total( '21.00' );
		$order->save();
		$reject_case( static fn () => $corrections->review(
			$case['case_id'], $admin->ID, 'request_bank_restatement', $review_note
		), 'changed WooCommerce amount was not detected' );
		$order->set_total( '20.00' );
		$order->save();
		$reviewed_case = $corrections->review(
			$case['case_id'], $admin->ID, 'request_bank_restatement', $review_note
		);
		$replayed_review = $corrections->review(
			$case['case_id'], $admin->ID, 'request_bank_restatement', $review_note
		);
		if ( 'reviewed' !== $reviewed_case['status'] ||
			'already_reviewed' !== $replayed_review['status'] ) {
			throw new RuntimeException( 'Independent correction review was not idempotent.' );
		}
		$reject_case( static fn () => \WPEssential\BankTransferMatcher\Evidence\CorrectionHoldGuard::assert_no_hold(
			$wpdb, $order->get_id(), $source_id
		), 'independent review falsely released the source hold' );
		$case_events = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}wbm_pro_correction_events WHERE correction_case_id = %d",
			$case['case_id']
		) );
		if ( 2 !== $case_events || wc_get_order( $order->get_id() )->is_paid() ||
			'approved' !== (string) $wpdb->get_var( $wpdb->prepare(
				"SELECT state FROM {$wpdb->prefix}wbm_pro_allocations WHERE id = %d",
				$first['allocation_id']
			) ) ) {
			throw new RuntimeException( 'Correction review mutated the WooCommerce order or Pro ledger.' );
		}
		echo "PASS source-linked Pro reversal case, independent review, drift, idempotent audit and Free/Pro payment holds\n";
	} finally {
		wp_set_current_user( $admin->ID );
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $reviewer_id );
	}

} finally {
	wp_set_current_user( $admin->ID );
	$case_table = $wpdb->prefix . 'wbm_pro_correction_cases';
	$case_ids = $wpdb->get_col( $wpdb->prepare(
		"SELECT id FROM $case_table WHERE order_id = %d", $order->get_id()
	) );
	foreach ( $case_ids as $case_id ) {
		$wpdb->delete( $wpdb->prefix . 'wbm_pro_correction_events',
			[ 'correction_case_id' => (int) $case_id ], [ '%d' ] );
	}
	$wpdb->delete( $case_table, [ 'order_id' => $order->get_id() ], [ '%d' ] );
	$table = $wpdb->prefix . 'wbm_pro_allocations';
	$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $table WHERE order_id = %d", $order->get_id() ) );
	foreach ( $ids as $id ) $wpdb->delete( $wpdb->prefix . 'wbm_pro_allocation_events', [ 'allocation_id' => (int) $id ], [ '%d' ] );
	$wpdb->delete( $table, [ 'order_id' => $order->get_id() ], [ '%d' ] );
	$wpdb->delete( $rows, [ 'batch_id' => $batch ], [ '%d' ] );
	$wpdb->delete( $wpdb->prefix . 'wbm_pro_import_batches', [ 'id' => $batch ], [ '%d' ] );
	$wpdb->delete( $wpdb->prefix . 'wbm_pro_order_bank_events', [ 'order_id' => $order->get_id() ], [ '%d' ] );
	$wpdb->delete( $wpdb->prefix . 'wbm_pro_order_receiving_accounts', [ 'order_id' => $order->get_id() ], [ '%d' ] );
	$wpdb->delete( $wpdb->prefix . 'wbm_pro_bank_accounts', [ 'id' => $account ], [ '%d' ] );
	$order->delete( true );
}
