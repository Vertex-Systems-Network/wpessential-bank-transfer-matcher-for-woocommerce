<?php
/** Real concurrent WP-CLI requests to one disposable WooCommerce/MariaDB. */
use WPEssential\BankTransferMatcher\Domain\EntityId;
use WPEssential\BankTransferMatcher\Domain\Money;
use WPEssential\BankTransferMatcher\Evidence\AllocationRepository;
use WPEssential\BankTransferMatcher\Evidence\EvidenceRepository;
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
use WPEssential\BankTransferMatcherPro\Reconciliation\SplitCreditAllocation;

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
	$manual_source = ( new EvidenceRepository( $wpdb ) )->record(
		$order->get_id(), $admin->ID, gmdate( 'Y-m-d' ),
		new Money( 10000, 'USD', 2 ), $reference->value,
		'Separate merchant-entered claim matches this imported bank credit; it must not duplicate payment.'
	);
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
		'manual_evidence_id' => $manual_source['id'],
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
	$wpdb->delete( $wpdb->prefix . 'wbm_manual_allocations', [ 'order_id' => $order_id ], [ '%d' ] );
	$wpdb->delete( $wpdb->prefix . 'wbm_bank_evidence',
		[ 'id' => $fixture['manual_evidence_id'] ], [ '%d' ] );
	if ( is_array( $fixture['split'] ?? null ) ) {
		$split = $fixture['split'];
		$split_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM $alloc_table WHERE transaction_row_id = %d", (int) $split['source_id']
		) );
		foreach ( $split_ids as $id ) {
			$wpdb->delete( $events_table, [ 'allocation_id' => (int) $id ], [ '%d' ] );
		}
		$wpdb->delete( $alloc_table, [ 'transaction_row_id' => (int) $split['source_id'] ], [ '%d' ] );
		foreach ( $split['order_ids'] as $split_order ) {
			$wpdb->delete( $intent_table, [ 'order_id' => $split_order ], [ '%d' ] );
			$wpdb->delete( $wpdb->prefix . 'wbm_manual_allocations', [ 'order_id' => $split_order ], [ '%d' ] );
			$wpdb->delete( $wpdb->prefix . 'wbm_bank_evidence', [ 'order_id' => $split_order ], [ '%d' ] );
			$wpdb->delete( $wpdb->prefix . 'wbm_pro_order_bank_events', [ 'order_id' => $split_order ], [ '%d' ] );
			$wpdb->delete( $wpdb->prefix . 'wbm_pro_order_receiving_accounts', [ 'order_id' => $split_order ], [ '%d' ] );
			$split_wc = wc_get_order( $split_order );
			if ( $split_wc ) $split_wc->delete( true );
		}
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
if ( 'split_setup' === $stage ) {
	wp_set_current_user( $maker_id );
	if ( isset( $fixture['split'] ) ) throw new RuntimeException( 'Split race already initialized.' );
	$recipients = [];
	$refs = [];
	foreach ( [ '60.00', '40.00' ] as $total ) {
		$recipient = wc_create_order();
		$recipient->set_payment_method( 'bacs' );
		$recipient->set_currency( 'USD' );
		$recipient->set_total( $total );
		$recipient->set_status( 'on-hold' );
		$recipient->save();
		$recipients[] = $recipient->get_id();
		$ref = PaymentReference::issue();
		( new ReferenceRepository( $wpdb ) )->reserve( new EntityId( $recipient->get_id() ), $ref );
		$refs[] = $ref->value;
		( new OrderReceivingAccountRepository( $wpdb ) )->confirm(
			$recipient->get_id(), (int) $fixture['account_id'], $maker_id,
			'Merchant confirmed the expected bank account for a split-recipient race.'
		);
	}
	if ( 1 !== $wpdb->insert( $wpdb->prefix . 'wbm_pro_import_rows', [
		'batch_id' => $fixture['batch_id'], 'source_row' => 3,
		'canonical_json' => wp_json_encode( [
			'source_row' => 3, 'posted_date' => gmdate( 'Y-m-d' ),
			'amount_minor' => 10000, 'currency' => 'USD', 'exponent' => 2,
			'reference' => $refs[0], 'description' => 'Distinct cleared split credit',
			'transaction_id' => 'RACE-SPLIT-' . $recipients[0],
		] ),
		'error_code' => '', 'warning_code' => '',
		'identity_fingerprint' => hash( 'sha256', 'cross-process-split/' . $recipients[0] ),
	] ) ) throw new RuntimeException( 'Could not import synthetic split credit.' );
	$split_source = (int) $wpdb->insert_id;
	$split_key = hash( 'sha256', 'cross-process/split/' . $split_source );
	$proposal = ( new SplitCreditAllocation( $wpdb ) )->propose(
		$split_source, [
			[ 'order_id' => $recipients[0], 'amount_minor' => 6000 ],
			[ 'order_id' => $recipients[1], 'amount_minor' => 4000 ],
		], $maker_id, $split_key,
		'The merchant assigned one distinct cleared bank source to both verified BACS recipients.'
	);
	if ( 'proposed' !== $proposal['status'] || count( $proposal['allocation_ids'] ) !== 2 ) {
		throw new RuntimeException( 'Split setup failed to create two atomic proposals.' );
	}
	$evidence = ( new EvidenceRepository( $wpdb ) )->record(
		$recipients[1], $maker_id, gmdate( 'Y-m-d' ),
		new Money( 4000, 'USD', 2 ), $refs[1],
		'Manual receipt competes with the approved split and cannot duplicate its amount.'
	);
	$fixture['split'] = [
		'source_id' => $split_source, 'key' => $split_key, 'order_ids' => $recipients,
		'manual_evidence_id' => (int) $evidence['id'],
	];
	update_option( $option_key, $fixture, false );
	echo "PASS staged one imported bank credit split between two unpaid BACS recipients\n";
	return;
}
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
if ( str_starts_with( $stage, 'split_' ) || str_starts_with( $stage, 'verify_split_' ) ) {
	$split = $fixture['split'] ?? null;
	if ( ! is_array( $split ) ) throw new RuntimeException( 'Split race fixture missing.' );
	$first = (int) $split['order_ids'][0];
	$second = (int) $split['order_ids'][1];
	$group = (string) $split['key'];
	$split_review = 'I independently confirmed the cleared shared credit and both split recipient balances.';
	$split_pay = 'I explicitly confirmed this split credit is cleared with verified recipients and audit.';
	if ( 'split_approve_hold' === $stage || 'split_approve_contender' === $stage ) {
		wp_set_current_user( $checker_id );
		try {
			$result = ( new SplitCreditAllocation( $wpdb ) )->approve(
				$group, $checker_id, true, $split_review
			);
			if ( 'split_approve_contender' === $stage ) {
				throw new RuntimeException( 'Overlapping split checker bypassed named locks.' );
			}
			if ( 'approved' !== $result['status'] ) {
				throw new RuntimeException( 'First split checker did not approve the group.' );
			}
		} catch ( RuntimeException $expected ) {
			if ( 'split_approve_contender' !== $stage ||
				! str_contains( $expected->getMessage(), 'under concurrent reconciliation' ) ) {
				throw $expected;
			}
		}
		echo "PASS $stage atomic multi-recipient review under independent WP-CLI locks\n";
		return;
	}
	if ( 'split_finalize_contender' === $stage ) {
		wp_set_current_user( $maker_id );
		try {
			( new AllocationRepository( $wpdb ) )->allocate(
				$second, (int) $split['manual_evidence_id'], $maker_id,
				new Money( 4000, 'USD', 2 ), new Money( 4000, 'USD', 2 ),
				'An unrelated Free manual claim cannot spend the already reviewed split recipient.'
			);
			throw new RuntimeException( 'Manual writer bypassed second split recipient lock.' );
		} catch ( RuntimeException $expected ) {
			if ( ! str_contains( $expected->getMessage(), 'Order is being reconciled' ) ) throw $expected;
		}
		echo "PASS Free writer blocked on other split recipient while Pro finalizes first\n";
		return;
	}
	if ( 'split_finalize_hold' === $stage ) {
		wp_set_current_user( $checker_id );
		$result = ( new ProPaymentFinalizer( $wpdb ) )->complete(
			$first, $checker_id, hash( 'sha256', 'cross-process/first-split/' . $first ),
			true, $split_pay
		);
		if ( 'completed' !== $result['status'] || ! $result['order_payment_changed'] ) {
			throw new RuntimeException( 'First split recipient did not complete under held locks.' );
		}
		echo "PASS first split recipient completed while locking all other source recipients\n";
		return;
	}
	if ( 'verify_split_approve' === $stage || 'verify_split_finalize' === $stage ||
		'split_finish' === $stage ) {
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, order_id, state, approved_by FROM $alloc_table
			 WHERE transaction_row_id = %d ORDER BY order_id", (int) $split['source_id']
		), ARRAY_A );
		if ( ! is_array( $rows ) || count( $rows ) !== 2 ) {
			throw new RuntimeException( 'Shared bank source did not produce two distinct recipients.' );
		}
		$approved = 0;
		$committed = 0;
		$event_count = 0;
		foreach ( $rows as $allocation ) {
			if ( (int) $allocation['approved_by'] !== $checker_id ) {
				throw new RuntimeException( 'Split approval has missing independent reviewer.' );
			}
			if ( 'approved' === $allocation['state'] ) ++$approved;
			if ( 'committed' === $allocation['state'] ) ++$committed;
			$event_count += (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM $events_table WHERE allocation_id = %d", $allocation['id']
			) );
		}
		$free_count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}wbm_manual_allocations WHERE order_id = %d", $second
		) );
		if ( $free_count !== 0 ) throw new RuntimeException( 'Manual evidence double-funded a split recipient.' );
		$first_order = wc_get_order( $first );
		$second_order = wc_get_order( $second );
		if ( ! $first_order || ! $second_order ) throw new RuntimeException( 'Split recipient order vanished.' );
		if ( 'verify_split_approve' === $stage &&
			( $approved !== 2 || $committed !== 0 || $event_count !== 4 ||
				$first_order->is_paid() || $second_order->is_paid() ) ) {
			throw new RuntimeException( 'Parallel split approval partially committed or paid a recipient.' );
		}
		if ( 'verify_split_finalize' === $stage &&
			( $approved !== 1 || $committed !== 1 || $event_count !== 5 ||
				! $first_order->is_paid() || $second_order->is_paid() ) ) {
			throw new RuntimeException( 'Split finalization did not preserve independent order states.' );
		}
		if ( 'split_finish' === $stage ) {
			wp_set_current_user( $checker_id );
			$result = ( new ProPaymentFinalizer( $wpdb ) )->complete(
				$second, $checker_id, hash( 'sha256', 'cross-process/second-split/' . $second ),
				true, $split_pay
			);
			$replay = ( new ProPaymentFinalizer( $wpdb ) )->complete(
				$first, $checker_id, hash( 'sha256', 'cross-process/first-split/' . $first ),
				true, $split_pay
			);
			if ( 'completed' !== $result['status'] || ! $result['order_payment_changed'] ||
				'already_completed' !== $replay['status'] || $replay['order_payment_changed'] ||
				! wc_get_order( $first )->is_paid() || ! wc_get_order( $second )->is_paid() ) {
				throw new RuntimeException( 'Split recipients failed independent paid-intent replay.' );
			}
			$count = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM $alloc_table
				 WHERE transaction_row_id = %d AND state = 'committed'", (int) $split['source_id']
			) );
			if ( $count !== 2 ) throw new RuntimeException( 'One split recipient was not committed.' );
			echo "PASS shared credit finalized once per recipient with exact source conservation\n";
		} else {
			echo "PASS $stage guarded two-recipient source and financial audit\n";
		}
		return;
	}
	throw new InvalidArgumentException( 'Unknown split cross-process stage.' );
}
if ( 'free_contender' === $stage ) {
	wp_set_current_user( $maker_id );
	try {
		( new AllocationRepository( $wpdb ) )->allocate(
			$order_id, (int) $fixture['manual_evidence_id'], $maker_id,
			new Money( 10000, 'USD', 2 ), new Money( 10000, 'USD', 2 ),
			'Merchant-entered copy of imported bank credit must never double-fund an order.'
		);
		throw new RuntimeException( 'Concurrent Free manual writer bypassed active Pro order lock.' );
	} catch ( RuntimeException $expected ) {
		if ( ! str_contains( $expected->getMessage(), 'Order is being reconciled' ) ) throw $expected;
	}
	echo "PASS Free manual writer blocked by concurrent Pro source/order reconciliation\\n";
	return;
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
	$manual_spend = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->prefix}wbm_manual_allocations WHERE order_id = %d", $order_id
	) );
	if ( $manual_spend !== 0 ) {
		throw new RuntimeException( 'Cross-edition simultaneous manual writer double-funded this order.' );
	}
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
