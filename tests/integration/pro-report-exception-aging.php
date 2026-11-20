<?php
use WPEssential\BankTransferMatcherPro\Import\BankAccountRepository;
use WPEssential\BankTransferMatcherPro\Reconciliation\OverpaymentExceptionRepository;
use WPEssential\BankTransferMatcherPro\Reconciliation\MismatchCaseRepository;
use WPEssential\BankTransferMatcherPro\Reconciliation\CorrectionCaseRepository;
use WPEssential\BankTransferMatcherPro\Reporting\ReportDefinition;
use WPEssential\BankTransferMatcherPro\Reporting\ReportQueryRepository;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'wbm_ci' !== DB_NAME ) {
	throw new RuntimeException( 'Exception aging fixture requires disposable wbm_ci.' );
}
global $wpdb;
$admin = get_user_by( 'login', 'wbm_ci' );
wp_set_current_user( $admin->ID );
( new OverpaymentExceptionRepository( $wpdb ) )->install();
( new MismatchCaseRepository( $wpdb ) )->install();
( new CorrectionCaseRepository( $wpdb ) )->install();
$account = ( new BankAccountRepository( $wpdb ) )->create(
	'Finance exception reporting fixture', 'Synthetic', 'USD', '7171', $admin->ID
);
$cases = [];
$source = 900000 + wp_rand( 1, 9999 );
$hash = hash( 'sha256', 'report-exception/' . wp_generate_uuid4() );
$day = gmdate( 'Y-m-d H:i:s' );
$old = gmdate( 'Y-m-d H:i:s', time() - 4 * 86400 );
try {
	$exceptions = $wpdb->prefix . 'wbm_pro_exceptions';
	if ( 1 !== $wpdb->insert( $exceptions, [
		'transaction_row_id' => $source, 'bank_account_id' => $account,
		'source_fingerprint' => $hash, 'order_id' => $source + 1,
		'credit_minor' => 9000, 'expected_minor' => 7000,
		'credit_unallocated_minor' => 9000, 'order_outstanding_minor' => 7000,
		'overpayment_minor' => 2000, 'currency' => 'USD', 'exponent' => 2,
		'exception_code' => 'overpayment', 'status' => 'open',
		'request_key' => hash( 'sha256', 'overpay-' . $hash ),
		'created_by' => $admin->ID, 'reason' => 'Synthetic 4-day-old overpayment hold for report.',
		'created_at' => $old,
	] ) ) throw new RuntimeException( 'Could not stage old overpayment case.' );
	$cases[] = [ $exceptions, (int) $wpdb->insert_id ];
	$mismatch = $wpdb->prefix . 'wbm_pro_mismatch_cases';
	if ( 1 !== $wpdb->insert( $mismatch, [
		'transaction_row_id' => $source + 1, 'order_id' => $source + 2,
		'source_fingerprint' => $hash, 'source_bank_account_id' => $account,
		'expected_bank_account_id' => $account, 'source_amount_minor' => 9000,
		'order_total_minor' => 7000, 'order_outstanding_minor' => 7000,
		'source_currency' => 'USD', 'order_currency' => 'USD',
		'source_exponent' => 2, 'order_exponent' => 2,
		'reason_codes_json' => '["payment_reference_mismatch"]',
		'facts_hash' => $hash, 'cleared_confirmed' => 0,
		'status' => 'reviewed', 'request_key' => hash( 'sha256', 'mismatch-' . $hash ),
		'created_by' => $admin->ID, 'created_at' => $day,
	] ) ) throw new RuntimeException( 'Could not stage mismatch report case.' );
	$cases[] = [ $mismatch, (int) $wpdb->insert_id ];
	$correction = $wpdb->prefix . 'wbm_pro_correction_cases';
	if ( 1 !== $wpdb->insert( $correction, [
		'allocation_id' => $source, 'order_id' => $source + 3,
		'transaction_row_id' => $source + 2, 'bank_account_id' => $account,
		'source_fingerprint' => $hash, 'amount_minor' => 9000,
		'credit_minor' => 9000, 'expected_minor' => 9000,
		'currency' => 'USD', 'exponent' => 2,
		'allocation_state' => 'approved', 'facts_sha256' => $hash,
		'case_type' => 'wrong_order', 'status' => 'open',
		'request_key' => hash( 'sha256', 'correction-' . $hash ),
		'created_by' => $admin->ID, 'reason' => 'Synthetic reported wrong-order credit allocation requiring review.',
		'created_at' => $day,
	] ) ) throw new RuntimeException( 'Could not stage correction hold.' );
	$cases[] = [ $correction, (int) $wpdb->insert_id ];
	$scope = [
		'report' => 'exception_aging', 'from' => gmdate( 'Y-m-d', time() - 7 * 86400 ),
		'to' => gmdate( 'Y-m-d' ), 'bank_account_id' => $account, 'currency' => 'USD',
	];
	$report = new ReportQueryRepository( $wpdb );
	$all = $report->run( ReportDefinition::from_array( $scope ) );
	$open = $report->run( ReportDefinition::from_array( array_replace(
		$scope, [ 'state' => 'open' ]
	) ) );
	if ( count( $all['rows'] ) !== 3 || count( $open['rows'] ) !== 2 ||
		$open['page_totals']['USD/2/open']['count'] !== 2 ||
		$open['page_totals']['USD/2/open']['amount_minor'] !== 0 ||
		! $all['non_additive_case_exposure'] || $all['payment_authorized'] ||
		$all['ledger_mutated'] ) {
		throw new RuntimeException( 'Exception case totals incorrectly double-counted disputed funds.' );
	}
	$types = array_column( $all['rows'], 'case_type' );
	sort( $types );
	if ( $types !== [ 'correction', 'mismatch', 'overpayment' ] ) {
		throw new RuntimeException( 'Missing exception class in combined report.' );
	}
	$old_case = array_values( array_filter( $all['rows'],
		static fn ( array $row ): bool => $row['case_type'] === 'overpayment'
	) );
	if ( count( $old_case ) !== 1 || $old_case[0]['age_days'] < 3 ||
		( $all['age_bucket_counts']['USD/overpayment/open/3-7d'] ?? 0 ) !== 1 ||
		( $all['age_bucket_counts']['USD/correction/open/0-2d'] ?? 0 ) !== 1 ) {
		throw new RuntimeException( 'Exception age did not use UTC immutable case creation date.' );
	}
	$seen = [];
	$cursor = 0;
	do {
		$page = $report->run( ReportDefinition::from_array( array_replace( $scope, [
			'limit' => 1, 'before_id' => $cursor,
		] ) ) );
		foreach ( $page['rows'] as $row ) {
			if ( isset( $seen[ $row['id'] ] ) ) throw new RuntimeException( 'Repeated an exception in keyset pages.' );
			$seen[ $row['id'] ] = true;
		}
		$cursor = $page['next_before_id'];
	} while ( $page['has_more'] );
	if ( count( $seen ) !== 3 ) throw new RuntimeException( 'Case pagination omitted part of the audit.' );
	echo "PASS finance exception aging across three case ledgers, non-additive counts, UTC aging and stable virtual-ID cursor\n";
} finally {
	foreach ( $cases as [ $table, $id ] ) {
		$wpdb->delete( $table, [ 'id' => $id ], [ '%d' ] );
	}
	$wpdb->delete( $wpdb->prefix . 'wbm_pro_bank_accounts', [ 'id' => $account ], [ '%d' ] );
}
