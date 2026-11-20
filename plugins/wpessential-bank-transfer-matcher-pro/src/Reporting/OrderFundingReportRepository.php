<?php
namespace WPEssential\BankTransferMatcherPro\Reporting;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use WPEssential\BankTransferMatcher\Access\Capabilities as FreeCaps;
use WPEssential\BankTransferMatcher\Domain\Money;
use WPEssential\BankTransferMatcherPro\Access\Capabilities as ProCaps;
use WPEssential\BankTransferMatcherPro\Reconciliation\OrderReceivingAccountRepository;
use wpdb;

/**
 * Read-only BACS funding reports, entirely through WooCommerce CRUD (HPOS safe).
 * Never interprets an allocation or a customer claim as WooCommerce paid status.
 */
final class OrderFundingReportRepository {
	private const PAGE_SIZE = 100;
	private const MAX_SCAN = 3000;

	public function __construct( private readonly wpdb $db ) {}

	/** @return array<string,mixed> */
	public function run( ReportDefinition $scope ): array {
		if ( ! in_array( $scope->report, [ 'outstanding_orders', 'partial_funding' ], true ) ) {
			throw new InvalidArgumentException( 'Not an order-funding report.' );
		}
		if ( get_current_user_id() < 1 || ! current_user_can( ProCaps::IMPORT ) ||
			! current_user_can( FreeCaps::APPROVE ) ) {
			throw new RuntimeException( 'Financial reports require finance permission.' );
		}
		if ( ! function_exists( 'wc_get_orders' ) ) {
			throw new RuntimeException( 'WooCommerce order storage unavailable.' );
		}
		$utc = new DateTimeZone( 'UTC' );
		$from = DateTimeImmutable::createFromFormat( '!Y-m-d', $scope->from, $utc );
		$to = DateTimeImmutable::createFromFormat( '!Y-m-d', $scope->to, $utc );
		if ( ! $from || ! $to ) throw new InvalidArgumentException( 'Invalid report boundary.' );
		$start = $from->getTimestamp();
		$end = $to->modify( '+1 day' )->getTimestamp();
		$rows = [];
		$over_limit = false;
		$scanned = 0;
		// Report SQL consumers must not assume wp_posts in HPOS stores. The
		// hard scan bound prevents a wide unfiltered query from being presented
		// as a silently complete report on a large store.
		for ( $page = 1; $page <= self::MAX_SCAN / self::PAGE_SIZE + 1; ++$page ) {
			$orders = wc_get_orders( [
				'status' => [ 'pending', 'on-hold' ],
				// Push exact UTC creation bounds and BACS into WooCommerce's
				// native order query, for both classic and HPOS storage. Without
				// these filters unrelated orders can exhaust MAX_SCAN before any
				// matching financial order is inspected.
				'payment_method' => 'bacs',
				'date_created' => $start . '...' . ( $end - 1 ),
				'limit' => self::PAGE_SIZE,
				'page' => $page,
				'orderby' => 'ID', 'order' => 'DESC',
				'return' => 'objects',
			] );
			if ( ! is_array( $orders ) ) {
				throw new RuntimeException( 'WooCommerce financial order search failed.' );
			}
			if ( [] === $orders ) break;
			if ( $scanned + count( $orders ) > self::MAX_SCAN ) {
				throw new RuntimeException( 'Report order scan is too broad. Narrow the date or account filters.' );
			}
			$scanned += count( $orders );
			foreach ( $orders as $order ) {
				if ( ! $order instanceof \WC_Order ) {
					throw new RuntimeException( 'Unexpected WooCommerce order record.' );
				}
				$id = (int) $order->get_id();
				if ( $id < 1 || ( $scope->before_id > 0 && $id >= $scope->before_id ) ||
					'bacs' !== $order->get_payment_method() ||
					$order->is_paid() || ! $order->has_status( [ 'pending', 'on-hold' ] ) ) continue;
				$created = $order->get_date_created();
				if ( ! $created ) continue;
				$timestamp = $created->getTimestamp();
				if ( $timestamp < $start || $timestamp >= $end ) continue;
				$currency = (string) $order->get_currency();
				if ( 'ALL' !== $scope->currency && $currency !== $scope->currency ) continue;
				$account = ( new OrderReceivingAccountRepository( $this->db ) )
					->expected_account_id( $id );
				if ( $scope->bank_account_id > 0 && $account !== $scope->bank_account_id ) continue;
				$status = (string) $order->get_status();
				if ( 'all' !== $scope->state && $status !== $scope->state ) continue;
				$precision = wc_get_price_decimals();
				$total = Money::from_decimal( (string) $order->get_total( 'edit' ),
					$currency, $precision )->minor_units;
				if ( $total <= 0 ) continue;
				$funded = $this->funded_minor( $id, $total, $currency, $precision );
				$due = $total - $funded;
				if ( $due <= 0 ||
					( 'partial_funding' === $scope->report && 0 === $funded ) ) continue;
				$rows[] = [
					'id' => $id, 'order_id' => $id, 'bank_account_id' => $account,
					'currency' => $currency, 'exponent' => $precision,
					'state' => $status, 'expected_minor' => $total,
					'funded_minor' => $funded, 'amount_minor' => $due,
					'created_at_utc' => gmdate( 'Y-m-d H:i:s', $timestamp ),
					'receiving_account_confirmed' => $account > 0,
					'woocommerce_paid' => false,
				];
				if ( count( $rows ) > $scope->limit ) $over_limit = true;
			}
			if ( count( $orders ) < self::PAGE_SIZE ) break;
		}
		$rows = array_slice( $rows, 0, $scope->limit );
		$totals = PerCurrencyTotals::summarize( $rows );
		return [
			'definition' => $scope->canonical(),
			'fingerprint' => $scope->fingerprint(),
			'generated_at_utc' => gmdate( 'Y-m-d H:i:s' ),
			'rows' => $rows, 'page_totals' => $totals,
			'has_more' => $over_limit,
			'next_before_id' => $over_limit && $rows ? $rows[count( $rows ) - 1]['id'] : 0,
			'payment_authorized' => false, 'ledger_mutated' => false,
		];
	}

	private function funded_minor( int $order_id, int $total, string $currency, int $precision ): int {
		$used = 0;
		foreach ( [
			[ $this->db->prefix . 'wbm_manual_allocations', '' ],
			[ $this->db->prefix . 'wbm_pro_allocations',
				"AND state IN ('approved', 'committed')" ],
		] as [ $table, $condition ] ) {
			$exists = $this->db->get_var( $this->db->prepare( 'SHOW TABLES LIKE %s', $table ) );
			$this->check_db();
			if ( $exists !== $table ) continue;
			$allocations = $this->db->get_results( $this->db->prepare(
				"SELECT expected_minor, amount_minor, currency, exponent
				 FROM $table WHERE order_id = %d $condition", $order_id
			), ARRAY_A );
			$this->check_db();
			if ( ! is_array( $allocations ) ) throw new RuntimeException( 'Order funding rows unavailable.' );
			foreach ( $allocations as $allocation ) {
				$value = self::unsigned( $allocation['amount_minor'] );
				if ( self::unsigned( $allocation['expected_minor'] ) !== $total ||
					$allocation['currency'] !== $currency ||
					self::unsigned( $allocation['exponent'] ) !== $precision ||
					$value < 1 || $value > $total - $used ) {
					throw new InvalidArgumentException( 'Order has changed or overdrawn funding evidence.' );
				}
				$used += $value;
			}
		}
		return $used;
	}

	private static function unsigned( mixed $value ): int {
		if ( is_int( $value ) && $value >= 0 ) return $value;
		if ( ! is_string( $value ) ||
			1 !== preg_match( '/\A(?:0|[1-9][0-9]*)\z/', $value ) ||
			strlen( $value ) > strlen( (string) PHP_INT_MAX ) ||
			( strlen( $value ) === strlen( (string) PHP_INT_MAX ) &&
				strcmp( $value, (string) PHP_INT_MAX ) > 0 ) ) {
			throw new InvalidArgumentException( 'Unsafe order funding integer.' );
		}
		return (int) $value;
	}

	private function check_db(): void {
		if ( '' !== $this->db->last_error ) throw new RuntimeException( 'Order funding query failed.' );
	}
}
