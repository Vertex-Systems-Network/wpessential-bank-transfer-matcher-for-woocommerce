<?php
use WPEssential\BankTransferMatcher\Domain\EntityId;
use WPEssential\BankTransferMatcher\Domain\PaymentReference;
use WPEssential\BankTransferMatcher\Orders\ReferenceRepository;
use WPEssential\BankTransferMatcherPro\Import\BankAccountRepository;
use WPEssential\BankTransferMatcherPro\Import\ImportBatchRepository;
use WPEssential\BankTransferMatcherPro\Import\MappingProfile;
use WPEssential\BankTransferMatcherPro\Reconciliation\MismatchCaseRepository;
use WPEssential\BankTransferMatcherPro\Reconciliation\OrderReceivingAccountRepository;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'wbm_ci' !== DB_NAME ) {
	throw new RuntimeException( 'Disposable WordPress wbm_ci database is required.' );
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
$payment_reference = PaymentReference::issue();
( new ReferenceRepository( $wpdb ) )->reserve( new EntityId( $order->get_id() ), $payment_reference );
$banks = new BankAccountRepository( $wpdb );
$receiving_account = $banks->create( 'Merchant expected bank', 'Synthetic Bank A', 'USD', '7311', $admin->ID );
$wrong_account = $banks->create( 'Imported wrong USD bank', 'Synthetic Bank B', 'USD', '7312', $admin->ID );
$foreign_account = $banks->create( 'Imported EUR bank', 'Synthetic Bank C', 'EUR', '7313', $admin->ID );
( new OrderReceivingAccountRepository( $wpdb ) )->confirm(
	$order->get_id(), $receiving_account, $admin->ID,
	'The merchant confirmed the USD receiving account for this unpaid BACS order.'
);
$profile = static function ( string $currency ): MappingProfile {
	return MappingProfile::from_array( [
		'id' => 'mismatch-' . strtolower( $currency ), 'name' => 'Synthetic mismatch ' . $currency,
		'delimiter' => ',', 'date_header' => 'Date', 'date_format' => 'Y-m-d',
		'amount_header' => 'Amount', 'decimal_separator' => '.',
		'thousands_separator' => '', 'exponent' => 2, 'currency_header' => '',
		'fixed_currency' => $currency, 'reference_header' => 'Reference',
		'description_header' => '', 'transaction_id_header' => 'Bank ID',
	] );
};
$batch_repo = new ImportBatchRepository( $wpdb );
$imports = $wpdb->prefix . 'wbm_pro_import_rows';
$batches = [];
$sources = [];
foreach ( [
	[ $wrong_account, 'USD', 12000, $payment_reference->value, 'wrong_account_and_amount' ],
	[ $foreign_account, 'EUR', 10000, $payment_reference->value, 'wrong_currency' ],
	[ $receiving_account, 'USD', 9000, 'UNRELATED-REFERENCE', 'wrong_reference_and_partial' ],
] as $row ) {
	[ $account_id, $currency, $amount_minor, $reference, $label ] = $row;
	$batch = $batch_repo->create( $account_id, $profile( $currency ),
		hash( 'sha256', 'mismatch-test/' . $order->get_id() . '/' . $label ), 100, $admin->ID );
	$batch_repo->transition( $batch, 'pending', 'processing' );
	$batch_repo->transition( $batch, 'processing', 'completed', 1 );
	$batches[] = $batch;
	$canonical = [
		'source_row' => 2, 'posted_date' => gmdate( 'Y-m-d' ),
		'amount_minor' => $amount_minor, 'currency' => $currency, 'exponent' => 2,
		'reference' => $reference, 'description' => 'Synthetic mismatch ' . $label,
		'transaction_id' => 'SYN-' . $label . '-' . $order->get_id(),
	];
	if ( 1 !== $wpdb->insert( $imports, [
		'batch_id' => $batch, 'source_row' => 2, 'canonical_json' => wp_json_encode( $canonical ),
		'error_code' => '', 'warning_code' => '',
		'identity_fingerprint' => hash( 'sha256', 'mismatch-source/' . $batch ),
	] ) ) throw new RuntimeException( 'Could not create synthetic mismatched bank row.' );
	$sources[$label] = (int) $wpdb->insert_id;
}
$reviewer_id = wp_create_user( 'wbm-mismatch-checker-' . wp_generate_password( 8, false ),
	wp_generate_password( 16 ), 'mismatch-checker-' . wp_generate_password( 8, false ) . '@example.test' );
if ( is_wp_error( $reviewer_id ) ) throw new RuntimeException( $reviewer_id->get_error_message() );
( new WP_User( $reviewer_id ) )->set_role( 'shop_manager' );
$repository = new MismatchCaseRepository( $wpdb );
$reason = 'Merchant finance flagged a source versus order mismatch for manual investigation.';
$review_reason = 'I independently checked the mismatch; bank confirmation is still required.';
$cases = [];
$reject = static function ( callable $fn, string $label ): void {
	try {
		$fn();
		throw new RuntimeException( 'Unsafe mismatch operation accepted: ' . $label );
	} catch ( InvalidArgumentException $expected ) {}
};
try {
	wp_set_current_user( 0 );
	try {
		$repository->raise( $sources['wrong_account_and_amount'], $order->get_id(),
			$admin->ID, hash( 'sha256', 'anonymous-mismatch' ), true, $reason );
		throw new RuntimeException( 'Anonymous mismatch recording succeeded.' );
	} catch ( RuntimeException $expected ) {
		if ( 'Anonymous mismatch recording succeeded.' === $expected->getMessage() ) throw $expected;
	}
	wp_set_current_user( $admin->ID );
	$checks = [
		'wrong_account_and_amount' => [ 'receiving_bank_account_mismatch', 'credit_exceeds_outstanding' ],
		'wrong_currency' => [ 'currency_mismatch', 'receiving_bank_account_mismatch' ],
		'wrong_reference_and_partial' => [ 'payment_reference_mismatch', 'partial_bank_credit' ],
	];
	foreach ( $checks as $label => $required ) {
		$key = hash( 'sha256', 'mismatch-request/' . $sources[$label] );
		$case = $repository->raise( $sources[$label], $order->get_id(), $admin->ID, $key, true, $reason );
		$replay = $repository->raise( $sources[$label], $order->get_id(), $admin->ID, $key, true, $reason );
		if ( 'open' !== $case['status'] || 'already_open' !== $replay['status'] ||
			$case['case_id'] !== $replay['case_id'] || $case['order_payment_changed'] ) {
			throw new RuntimeException( 'Mismatch case request was not idempotent.' );
		}
		foreach ( $required as $code ) {
			if ( ! in_array( $code, $case['codes'], true ) ) {
				throw new RuntimeException( "$label mismatch code $code was missing." );
			}
		}
		$cases[$label] = $case['case_id'];
	}
	$reject( static fn () => $repository->raise( $sources['wrong_currency'], $order->get_id(),
		$admin->ID, hash( 'sha256', 'mismatch-request/' . $sources['wrong_account_and_amount'] ),
		true, $reason ), 'request-key reuse' );
	$reject( static fn () => $repository->raise( $sources['wrong_currency'], $order->get_id(),
		$admin->ID, hash( 'sha256', 'second-mismatch-for-same-pair' ), true, $reason ),
		'duplicate source/order mismatch case' );
	$case_id = $cases['wrong_account_and_amount'];
	$reject( static fn () => $repository->review( $case_id, $admin->ID,
		'request_account_confirmation', $review_reason ), 'self-review' );
	wp_set_current_user( $reviewer_id );
	$reject( static fn () => $repository->review( $case_id, $reviewer_id,
		'authorize_payment', $review_reason ), 'payment-authorization decision' );
	$wpdb->update( $imports, [ 'warning_code' => 'possible_duplicate' ],
		[ 'id' => $sources['wrong_account_and_amount'] ] );
	$reject( static fn () => $repository->review( $case_id, $reviewer_id,
		'request_account_confirmation', $review_reason ), 'changed source duplicate warning' );
	$wpdb->update( $imports, [ 'warning_code' => '' ],
		[ 'id' => $sources['wrong_account_and_amount'] ] );
	$order->set_total( '101.00' );
	$order->save();
	$reject( static fn () => $repository->review( $case_id, $reviewer_id,
		'request_account_confirmation', $review_reason ), 'changed WooCommerce total' );
	$order->set_total( '100.00' );
	$order->save();
	$approved = $repository->review( $case_id, $reviewer_id,
		'request_account_confirmation', $review_reason );
	$again = $repository->review( $case_id, $reviewer_id,
		'request_account_confirmation', $review_reason );
	if ( 'reviewed' !== $approved['status'] || 'already_reviewed' !== $again['status'] ||
		$approved['order_payment_changed'] || wc_get_order( $order->get_id() )->is_paid() ) {
		throw new RuntimeException( 'Audit-only mismatch decision moved money or changed an order.' );
	}
	$events_table = $wpdb->prefix . 'wbm_pro_mismatch_events';
	$events = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM $events_table WHERE mismatch_case_id = %d", $case_id
	) );
	if ( 2 !== $events ) throw new RuntimeException( 'Mismatch review did not record exactly two immutable audit events.' );
	$manual = $wpdb->prefix . 'wbm_manual_allocations';
	$pro = $wpdb->prefix . 'wbm_pro_allocations';
	$manual_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $manual WHERE order_id = %d", $order->get_id() ) );
	$pro_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $pro WHERE order_id = %d", $order->get_id() ) );
	if ( $manual_count !== 0 || $pro_count !== 0 ||
		! wc_get_order( $order->get_id() )->has_status( 'on-hold' ) ) {
		throw new RuntimeException( 'Review-only mismatches were counted as payments or allocations.' );
	}
	echo "PASS imported mismatch cases for receiving bank, currency, amount, reference, source drift, reviewer audit and unpaid order\n";
} finally {
	wp_set_current_user( $admin->ID );
	$cases_table = $wpdb->prefix . 'wbm_pro_mismatch_cases';
	$ids = $wpdb->get_col( $wpdb->prepare(
		"SELECT id FROM $cases_table WHERE order_id = %d", $order->get_id()
	) );
	foreach ( $ids as $id ) $wpdb->delete( $wpdb->prefix . 'wbm_pro_mismatch_events',
		[ 'mismatch_case_id' => (int) $id ], [ '%d' ] );
	$wpdb->delete( $cases_table, [ 'order_id' => $order->get_id() ], [ '%d' ] );
	foreach ( $batches as $batch ) {
		$wpdb->delete( $imports, [ 'batch_id' => $batch ], [ '%d' ] );
		$wpdb->delete( $wpdb->prefix . 'wbm_pro_import_batches', [ 'id' => $batch ], [ '%d' ] );
	}
	$wpdb->delete( $wpdb->prefix . 'wbm_pro_order_bank_events', [ 'order_id' => $order->get_id() ], [ '%d' ] );
	$wpdb->delete( $wpdb->prefix . 'wbm_pro_order_receiving_accounts', [ 'order_id' => $order->get_id() ], [ '%d' ] );
	foreach ( [ $receiving_account, $wrong_account, $foreign_account ] as $id ) {
		$wpdb->delete( $wpdb->prefix . 'wbm_pro_bank_accounts', [ 'id' => $id ], [ '%d' ] );
	}
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $reviewer_id );
	$order->delete( true );
}
