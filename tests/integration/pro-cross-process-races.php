<?php
/** Real concurrent WP-CLI requests to one disposable WooCommerce/MariaDB. */
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
use WPEssential\BankTransferMatcherPro\Reconciliation\ProPaymentFinalizer;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'wbm_ci' !== DB_NAME ) {
	throw new RuntimeException( 'Disposable wbm_ci database required for concurrency tests.' );
}
global $wpdb;
$stage = $args[0] ?? '';
if ( ! is_string( $stage ) ) throw new InvalidArgumentException( 'Invalid race test stage.' );
$option_key = 'wbm_pro_race_fixture';
$fixture = get_option( $option_key );
$admin = get_user_by( 'login', 'wbm_ci' );
if ( ! $admin ) throw new RuntimeException( 'CI finance administrator missing.' );
if ( 'setup' === $stage ) {
	if ( is_array( $fixture ) ) throw new RuntimeException( 'Existing race fixture.' );
	wp_set_current_user( $admin->ID );
	$order = wc_create_order();
	$order->set_payment_method( 'bacs' );
	$order->set_currency( 'USD' );
	$order->set_total( '100.00' );
	$order->set_status( 'on-hold' );
	$order->save();
	$reference = PaymentReference::issue();
	( new ReferenceRepository( $wpdb ) )->reserve( new EntityId( $order->get_id() ), $reference );
	$account = ( new BankAccountRepository( $wpdb ) )->create(
		'Cross-process race bank', 'Synthetic', 'USD', '9911', $admin->ID
	);
	( new OrderReceivingAccountRepository( $wpdb ) )->confirm(
		$order->get_id(), $account, $admin->ID,
		'Merchant verified the expected receiving USD account for cross-process finance tests.'
	);
	$profile = MappingProfile::from_array( [
		'id' => 'race-profile', 'name' => 'Cross-process fixture',
		'delimiter' => ',', 'date_header' => 'Date', 'date_format' => 'Y-m-d',
		'amount_header' => 'Amount', 'decimal_separator' => '.',
		'thousands_separator' => '', 'exponent' => 2,
		'currency_header' => '', 'fixed_currency' => 'USD',
		'reference_header' => 'Reference', 'description_header' => '',
		'transaction_id_header' => 'Bank ID',
	] );
	$batches = new ImportBatchRepository( $wpdb );
	$batch_id = $batches->create( $account, $profile,
		hash( 'sha256', 'concurrent-bank/' . $order->get_id() ), 100, $admin->ID );
	$batches->transition( $batch_id, 'pending', 'processing' );
	$batches->transition( $batch_id, 'processing', 'completed', 1 );
	$source = [
		'source_row' => 2, 'posted_date' => gmdate( 'Y-m-d' ),
		'amount_minor' => 10000, 'currency' => 'USD', 'exponent' => 2,
		'reference' => $reference->value, 'description' => 'Synthetic cleared bank credit',
		'transaction_id' => 'RACE-' . $order->get_id(),
	];
	if ( 1 !== $wpdb->insert( $wpdb->prefix . 'wbm_pro_import_rows', [
		'batch_id' => $batch_id, 'source_row' => 2, 'canonical_json' => wp_json_encode( $source ),
		'error_code' => '', 'warning_code' => '',
		'identity_fingerprint' => hash( 'sha256', 'race-source/' . $batch_id ),
	] ) ) throw new RuntimeException( 'Could not stage imported credit.' );
	$source_id = (int) $wpdb->insert_id;
	$checker = wp_create_user( 'wbm-race-checker-' . wp_generate_password( 8, false ),
		wp_generate_password( 20 ), 'wbm-race-' . wp_generate_password( 8, false ) . '@example.test' );
	if ( is_wp_error( $checker ) ) throw new RuntimeException( $checker->get_error_message() );
	( new WP_User( $checker ) )->set_role( 'shop_manager' );
	( new AllocationLedgerRepository( $wpdb ) )->install();
	// Initialize the finalizer schema BEFORE barrier hooks can see any transaction.
	try {
		( new ProPaymentFinalizer( $wpdb ) )->complete(
			$order->get_id(), $admin->ID, hash( 'sha256', 'schema-init/' . $source_id ),
			true, 'This is only a storage schema preflight; no approved bank credit exists.'
		);
		throw new RuntimeException( 'Unfunded schema initialization marked an order paid.' );
	} catch ( InvalidArgumentException $expected ) {}
	update_option( $option_key, [
		'order_id' => $order->get_id(), 'source_id' => $source_id,
		'batch_id' => $batch_id, 'account_id' => $account,
		'maker_id' => $admin->ID, 'checker_id' => $checker,
	], false );
	echo "PASS initialized synthetic bank-source concurrency fixture\n";
	return;
}
if ( ! is_array( $fixture ) || ! is_int( $fixture['order_id'] ?? null ) ||
	! is_int( $fixture['source_id'] ?? null ) ) {
	throw new RuntimeException( 'Race test fixture missing.' );
}
$order_id = $fixture['order_id'];
$source_id = $fixture['source_id'];
$maker_id = (int) $fixture['maker_id'];
$checker_id = (int) $fixture['checker_id'];
$proposal_key = hash( 'sha256', 'race/proposal/' . $source_id );
$payment_key = hash( 'sha256', 'race/payment/' . $order_id );
$reason = 'This unique imported bank credit is proposed for explicit independent review.';
$review_reason = 'I independently verified cleared funds and the intended bank credit source.';
$payment_reason = 'I explicitly verified exact approved funding, bank clearance and order evidence.';
$alloc_table = $wpdb->prefix . 'wbm_pro_allocations';
$events_table = $wpdb->prefix . 'wbm_pro_allocation_events';
$intent_table = $wpdb->prefix . 'wbm_pro_payment_finalizations';
if ( 'cleanup' === $stage ) {
	wp_set_current_user( $maker_id );
	$ids = $wpdb->get_col( $wpdb->prepare(
		"SELECT id FROM $alloc_table WHERE order_id = %d", $order_id
	) );
	foreach ( $ids as $id ) {
		$wpdb->delete( $events_table, [ 'allocation_id' => (int) $id ], [ '%d' ] );
	}
	$wpdb->delete( $intent_table, [ 'order_id' => $order_id ], [ '%d' ] );
	$wpdb->delete( $alloc_table, [ 'order_id' => $order_id ], [ '%d' ] );
	$wpdb->delete( $wpdb->prefix . 'wbm_pro_import_rows', [ 'batch_id' => $fixture['batch_id'] ], [ '%d' ] );
	$wpdb->delete( $wpdb->prefix . 'wbm_pro_import_batches', [ 'id' => $fixture['batch_id'] ], [ '%d' ] );
	$wpdb->delete( $wpdb->prefix . 'wbm_pro_order_bank_events', [ 'order_id' => $order_id ], [ '%d' ] );
	$wpdb->delete( $wpdb->prefix . 'wbm_pro_order_receiving_accounts', [ 'order_id' => $order_id ], [ '%d' ] );
	$wpdb->delete( $wpdb->prefix . 'wbm_pro_bank_accounts', [ 'id' => $fixture['account_id'] ], [ '%d' ] );
	$order = wc_get_order( $order_id );
	if ( $order ) $order->delete( true );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $checker_id );
	delete_option( 'wbm_pro_race_payment_hooks_' . $order_id );
	delete_option( $option_key );
	echo "PASS deleted disposable race fixture\n";
	return;
}
$row = $wpdb->get_row( $wpdb->prepare(
	"SELECT id, state, created_by, approved_by FROM $alloc_table
	 WHERE transaction_row_id = %d AND order_id = %d", $source_id, $order_id
), ARRAY_A );
$allocation_id = is_array( $row ) ? (int) $row['id'] : 0;
if ( str_ends_with( $stage, '_hold' ) ) {
	$signal = $args[1] ?? null;
	if ( ! is_string( $signal ) || ! str_starts_with( $signal, '/tmp/wbm-pro-race-' ) ) {
		throw new InvalidArgumentException( 'Race signal must be in disposable /tmp path.' );
	}
	$signaled = false;
	add_filter( 'query', static function ( string $sql ) use ( $signal, &$signaled ): string {
		if ( ! $signaled && 'START TRANSACTION' === strtoupper( trim( $sql ) ) ) {
			$signaled = true;
			if ( false === file_put_contents( $signal, 'lock acquired' ) ) {
				throw new RuntimeException( 'Failed to record held-lock barrier.' );
			}
			usleep( 3500000 );
		}
		return $sql;
	}, 999 );
}
if ( str_starts_with( $stage, 'propose_' ) ) {
	wp_set_current_user( $maker_id );
	$writer = new OneToOneProposal( $wpdb );
	if ( 'propose_contender' === $stage ) {
		try {
			$writer->propose( $order_id, $source_id, $maker_id,
				hash( 'sha256', 'race/competing-request/' . $source_id ), $reason );
			throw new RuntimeException( 'Concurrent proposal accepted twice.' );
		} catch ( RuntimeException $expected ) {
			if ( ! str_contains( $expected->getMessage(), 'locked for review' ) ) throw $expected;
		}
		echo "PASS competing source proposal rejected while another WordPress process holds locks\n";
		return;
	}
	$result = $writer->propose( $order_id, $source_id, $maker_id, $proposal_key, $reason );
	if ( 'proposed' !== $result['state'] ) throw new RuntimeException( 'Primary concurrent proposal missing.' );
	echo "PASS primary process persisted source-backed proposal\n";
	return;
}
if ( str_starts_with( $stage, 'approve_' ) ) {
	wp_set_current_user( $checker_id );
	$writer = new OneToOneApproval( $wpdb );
	if ( 'approve_contender' === $stage ) {
		try {
			$writer->approve( $allocation_id, $checker_id, true, $review_reason );
			throw new RuntimeException( 'Simultaneous reviewer bypassed named locks.' );
		} catch ( RuntimeException $expected ) {
			if ( ! str_contains( $expected->getMessage(), 'currently being reconciled' ) ) throw $expected;
		}
		echo "PASS second independent checker session blocked during active source review\n";
		return;
	}
	$result = $writer->approve( $allocation_id, $checker_id, true, $review_reason );
	if ( 'approved' !== $result['status'] ) throw new RuntimeException( 'Primary reviewer approval absent.' );
	echo "PASS primary checker recorded single approval\n";
	return;
}
if ( str_starts_with( $stage, 'complete_' ) ) {
	wp_set_current_user( $checker_id );
	$writer = new ProPaymentFinalizer( $wpdb );
	if ( 'complete_contender' === $stage ) {
		try {
			$writer->complete( $order_id, $checker_id, $payment_key, true, $payment_reason );
			throw new RuntimeException( 'Simultaneous WooCommerce completion bypassed source locks.' );
		} catch ( RuntimeException $expected ) {
			if ( ! str_contains( $expected->getMessage(), 'concurrent reconciliation' ) ) throw $expected;
		}
		echo "PASS second finance finalizer session blocked during active source review\n";
		return;
	}
	add_action( 'woocommerce_payment_complete', static function ( int $id ) use ( $order_id ): void {
		if ( $id !== $order_id ) return;
		$option = 'wbm_pro_race_payment_hooks_' . $id;
		update_option( $option, 1 + (int) get_option( $option, 0 ), false );
	}, 10 );
	$result = $writer->complete( $order_id, $checker_id, $payment_key, true, $payment_reason );
	if ( 'completed' !== $result['status'] || ! $result['order_payment_changed'] ) {
		throw new RuntimeException( 'Primary finance finalizer failed to complete explicitly.' );
	}
	echo "PASS primary process persisted WooCommerce paid intent and committed audit\n";
	return;
}
if ( in_array( $stage, [ 'verify_propose', 'verify_approve', 'verify_complete' ], true ) ) {
	if ( $allocation_id < 1 ) throw new RuntimeException( 'Missing unique allocation.' );
	$count = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM $alloc_table WHERE transaction_row_id = %d", $source_id
	) );
	$event_count = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM $events_table WHERE allocation_id = %d", $allocation_id
	) );
	$expected_events = [ 'verify_propose' => 1, 'verify_approve' => 2, 'verify_complete' => 3 ][$stage];
	$expected_state = [ 'verify_propose' => 'proposed', 'verify_approve' => 'approved', 'verify_complete' => 'committed' ][$stage];
	if ( $count !== 1 || $event_count !== $expected_events ||
		$row['state'] !== $expected_state || (int) $row['created_by'] !== $maker_id ||
		( 'verify_propose' !== $stage && (int) $row['approved_by'] !== $checker_id ) ) {
		throw new RuntimeException( 'Parallel requests broke single-credit allocation/audit conservation.' );
	}
	$order = wc_get_order( $order_id );
	if ( ! $order ) throw new RuntimeException( 'Race order unavailable.' );
	if ( 'verify_complete' === $stage ) {
		$intent = $wpdb->get_var( $wpdb->prepare(
			"SELECT state FROM $intent_table WHERE order_id = %d", $order_id
		) );
		if ( ! $order->is_paid() || $intent !== 'completed' ||
			1 !== (int) get_option( 'wbm_pro_race_payment_hooks_' . $order_id, 0 ) ) {
			throw new RuntimeException( 'Simultaneous finalization produced stale audit or duplicate WC hook.' );
		}
		wp_set_current_user( $checker_id );
		$replay = ( new ProPaymentFinalizer( $wpdb ) )->complete(
			$order_id, $checker_id, $payment_key, true, $payment_reason
		);
		if ( $replay['status'] !== 'already_completed' || $replay['order_payment_changed'] ||
			1 !== (int) get_option( 'wbm_pro_race_payment_hooks_' . $order_id, 0 ) ) {
			throw new RuntimeException( 'Separate idempotent replay repeated WooCommerce paid hooks.' );
		}
	} else {
		if ( $order->is_paid() || ! $order->has_status( 'on-hold' ) ) {
			throw new RuntimeException( 'Proposal or approval marked the order paid.' );
		}
		$balance = ( new AllocationLedgerRepository( $wpdb ) )->snapshot(
			$order_id, $source_id, 10000, 10000, 'USD', 2, (int) $fixture['account_id']
		);
		$due = 'verify_propose' === $stage ? 10000 : 0;
		if ( $balance['order_outstanding_minor'] !== $due ||
			$balance['credit_unallocated_minor'] !== $due ) {
			throw new RuntimeException( 'Overlapping finance requests over-allocated bank credit.' );
		}
	}
	echo "PASS $stage unique source, correct ledger/audit and idempotent WooCommerce status\n";
	return;
}
throw new InvalidArgumentException( 'Unknown cross-process finance stage.' );
