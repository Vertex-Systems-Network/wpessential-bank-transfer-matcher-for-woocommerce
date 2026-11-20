<?php
use WPEssential\BankTransferMatcher\Domain\Money;
use WPEssential\BankTransferMatcher\Domain\EntityId;
use WPEssential\BankTransferMatcher\Domain\PaymentReference;
use WPEssential\BankTransferMatcher\Orders\ReferenceRepository;
use WPEssential\BankTransferMatcher\Evidence\AllocationRepository;
use WPEssential\BankTransferMatcher\Evidence\EvidenceRepository;
use WPEssential\BankTransferMatcherPro\Import\BankAccountRepository;
use WPEssential\BankTransferMatcherPro\Reconciliation\OrderReceivingAccountRepository;
use WPEssential\BankTransferMatcherPro\Reporting\ReportDefinition;
use WPEssential\BankTransferMatcherPro\Reporting\ReportQueryRepository;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'wbm_ci' !== DB_NAME ) {
	throw new RuntimeException( 'Disposable native WooCommerce report fixture only.' );
}
global $wpdb;
$admin = get_user_by( 'login', 'wbm_ci' );
wp_set_current_user( $admin->ID );
$orders = [];
$usd = ( new BankAccountRepository( $wpdb ) )->create(
	'Reporting account for BACS orders', 'Synthetic', 'USD', '3456', $admin->ID
);
$manual_id = 0;
try {
	foreach ( [
		[ 'bacs', '50.00', 'USD', true ],
		[ 'bacs', '100.00', 'USD', true ],
		[ 'cod', '100.00', 'USD', false ],
		[ 'bacs', '15.00', 'USD', false ],
	] as [ $method, $total, $currency, $confirmed_bank ] ) {
		$order = wc_create_order();
		$order->set_payment_method( $method );
		$order->set_currency( $currency );
		$order->set_total( $total );
		$order->set_status( 'on-hold' );
		$order->save();
		$orders[] = $order;
		if ( $method === 'bacs' ) {
			$reference = PaymentReference::issue();
			( new ReferenceRepository( $wpdb ) )->reserve(
				new EntityId( $order->get_id() ), $reference
			);
			if ( $confirmed_bank ) {
				( new OrderReceivingAccountRepository( $wpdb ) )->confirm(
					$order->get_id(), $usd, $admin->ID,
					'Merchant confirmed the USD receiving account for read-only reporting.'
				);
			}
			if ( $total === '100.00' ) {
				$manual = ( new EvidenceRepository( $wpdb ) )->record(
					$order->get_id(), $admin->ID, gmdate( 'Y-m-d' ),
					new Money( 4000, 'USD', 2 ), $reference->value,
					'Independent synthetic manual bank evidence for partial funding report.'
				);
				$manual_id = $manual['id'];
				( new AllocationRepository( $wpdb ) )->allocate(
					$order->get_id(), $manual_id, $admin->ID,
					new Money( 10000, 'USD', 2 ), new Money( 4000, 'USD', 2 ),
					'Merchant manually allocated a unique synthetic bank credit.'
				);
			}
		}
	}
	$reader = new ReportQueryRepository( $wpdb );
	$scope = [ 'report' => 'outstanding_orders', 'from' => gmdate( 'Y-m-d' ),
		'to' => gmdate( 'Y-m-d' ), 'currency' => 'USD', 'bank_account_id' => $usd ];
	$report_queries_seen = 0;
	$report_query_guard = static function ( array $args ) use ( &$report_queries_seen, $scope ): array {
		if ( ( $args['status'] ?? null ) !== [ 'pending', 'on-hold' ] ) return $args;
		++$report_queries_seen;
		// This filter fires on WooCommerce order queries under classic and HPOS.
		// The production scan bound must apply only after gateway/date pruning.
		$start = strtotime( $scope['from'] . ' 00:00:00 UTC' );
		$end = strtotime( $scope['to'] . ' 00:00:00 UTC' ) + DAY_IN_SECONDS - 1;
		if ( ( $args['payment_method'] ?? null ) !== 'bacs' ||
			( $args['date_created'] ?? null ) !== $start . '...' . $end ) {
			throw new RuntimeException( 'BACS report scanned unrelated or outside-window orders.' );
		}
		return $args;
	};
	add_filter( 'woocommerce_order_query_args', $report_query_guard, 20 );
	$full = $reader->run( ReportDefinition::from_array( $scope ) );
	if ( count( $full['rows'] ) !== 2 ||
		$full['page_totals']['USD/2/on-hold']['amount_minor'] !== 11000 ||
		$full['payment_authorized'] || $full['ledger_mutated'] ) {
		throw new RuntimeException( 'Outstanding BACS report did not preserve exact unpaid order balances.' );
	}
	$without_account_filter = $reader->run( ReportDefinition::from_array(
		array_replace( $scope, [ 'bank_account_id' => 0 ] )
	) );
	$unbound = array_values( array_filter( $without_account_filter['rows'],
		static fn ( array $row ): bool => !$row['receiving_account_confirmed']
	) );
	if ( count( $unbound ) !== 1 ||
		$unbound[0]['order_id'] !== $orders[3]->get_id() ||
		$unbound[0]['bank_account_id'] !== 0 ||
		$without_account_filter['page_totals']['USD/2/on-hold']['amount_minor'] !== 12500 ) {
		throw new RuntimeException( 'Unknown receiving bank was silently inferred from an imported source.' );
	}
	$partial = $reader->run( ReportDefinition::from_array( array_replace( $scope, [
		'report' => 'partial_funding',
	] ) ) );
	if ( count( $partial['rows'] ) !== 1 ||
		$partial['rows'][0]['order_id'] !== $orders[1]->get_id() ||
		$partial['rows'][0]['funded_minor'] !== 4000 ||
		$partial['rows'][0]['amount_minor'] !== 6000 ||
		$partial['page_totals']['USD/2/on-hold']['amount_minor'] !== 6000 ) {
		throw new RuntimeException( 'Partially funded order was overstated or counted as paid.' );
	}
	$first = $reader->run( ReportDefinition::from_array( array_replace( $scope, [ 'limit' => 1 ] ) ) );
	$next = $reader->run( ReportDefinition::from_array( array_replace( $scope, [
		'limit' => 1, 'before_id' => $first['next_before_id'],
	] ) ) );
	if ( count( $first['rows'] ) !== 1 || ! $first['has_more'] ||
		count( $next['rows'] ) !== 1 || $next['has_more'] ||
		$first['rows'][0]['id'] === $next['rows'][0]['id'] ) {
		throw new RuntimeException( 'WooCommerce order report keyset pagination repeated financial orders.' );
	}
	if ( $report_queries_seen < 4 ) {
		throw new RuntimeException( 'Expected scoped order queries were not intercepted.' );
	}
	foreach ( $orders as $order ) {
		if ( wc_get_order( $order->get_id() )->is_paid() ) {
			throw new RuntimeException( 'A read-only report changed a WooCommerce payment.' );
		}
	}
	echo "PASS native " . ( wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled() ? 'HPOS' : 'classic' ) .
		" BACS outstanding/partial funding, scoped account, exact money and safe pagination\n";
} finally {
	if ( isset( $report_query_guard ) ) remove_filter( 'woocommerce_order_query_args', $report_query_guard, 20 );
	wp_set_current_user( $admin->ID );
	foreach ( $orders as $order ) {
		$wpdb->delete( $wpdb->prefix . 'wbm_manual_allocations',
			[ 'order_id' => $order->get_id() ], [ '%d' ] );
		$wpdb->delete( $wpdb->prefix . 'wbm_bank_evidence',
			[ 'order_id' => $order->get_id() ], [ '%d' ] );
		$wpdb->delete( $wpdb->prefix . 'wbm_pro_order_bank_events',
			[ 'order_id' => $order->get_id() ], [ '%d' ] );
		$wpdb->delete( $wpdb->prefix . 'wbm_pro_order_receiving_accounts',
			[ 'order_id' => $order->get_id() ], [ '%d' ] );
		$order->delete( true );
	}
	$wpdb->delete( $wpdb->prefix . 'wbm_pro_bank_accounts', [ 'id' => $usd ], [ '%d' ] );
}
