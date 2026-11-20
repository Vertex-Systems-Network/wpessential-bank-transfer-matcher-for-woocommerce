<?php
namespace WPEssential\BankTransferMatcherPro\Reporting;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use WPEssential\BankTransferMatcher\Access\Capabilities as FreeCaps;
use WPEssential\BankTransferMatcherPro\Access\Capabilities as ProCaps;
use wpdb;

/**
 * Combined exception cases, NOT distinct money. Multiple cases can refer to
 * the same credit, so case counts are additive but disputed amounts are not.
 */
final class ExceptionAgingReportRepository {
	public function __construct( private readonly wpdb $db ) {}

	/** @return array<string,mixed> */
	public function run( ReportDefinition $scope ): array {
		if ( $scope->report !== 'exception_aging' ) {
			throw new InvalidArgumentException( 'Not an exception-aging report.' );
		}
		if ( get_current_user_id() < 1 || ! current_user_can( FreeCaps::APPROVE ) ||
			! current_user_can( ProCaps::IMPORT ) ) {
			throw new RuntimeException( 'Exception reports require finance authorization.' );
		}
		$schemas = [
			[ 'wbm_pro_exceptions', 0, 'bank_account_id', 'currency', 'exponent', 'overpayment_minor', 'overpayment' ],
			[ 'wbm_pro_mismatch_cases', 1, 'source_bank_account_id', 'source_currency', 'source_exponent', 'source_amount_minor', 'mismatch' ],
			[ 'wbm_pro_correction_cases', 2, 'bank_account_id', 'currency', 'exponent', 'amount_minor', 'correction' ],
		];
		$first = $scope->from . ' 00:00:00';
		$last = ( new DateTimeImmutable( $scope->to . ' 00:00:00',
			new DateTimeZone( 'UTC' ) ) )->modify( '+1 day' )->format( 'Y-m-d H:i:s' );
		$collected = [];
		$now = time();
		foreach ( $schemas as [ $suffix, $type, $account_column, $currency_column,
			$exponent_column, $amount_column, $kind ] ) {
			$table = $this->db->prefix . $suffix;
			$exists = $this->db->get_var( $this->db->prepare( 'SHOW TABLES LIKE %s', $table ) );
			$this->check_db();
			if ( $exists !== $table ) continue;
			$where = [ 'created_at >= %s', 'created_at < %s' ];
			$args = [ $first, $last ];
			if ( $scope->bank_account_id > 0 ) {
				$where[] = "$account_column = %d";
				$args[] = $scope->bank_account_id;
			}
			if ( 'ALL' !== $scope->currency ) {
				$where[] = "$currency_column = %s";
				$args[] = $scope->currency;
			}
			if ( 'all' !== $scope->state ) {
				$where[] = 'status = %s';
				$args[] = $scope->state;
			}
			// IDs overlap across three unrelated tables. A stable virtual ID,
			// case_id * 4 + table_slot, allows one global keyset cursor.
			if ( $scope->before_id > 0 ) {
				$remaining = $scope->before_id - 1 - $type;
				if ( $remaining < 0 ) continue;
				$where[] = 'id <= %d';
				$args[] = intdiv( $remaining, 4 );
			}
			$args[] = $scope->limit + 1;
			$results = $this->db->get_results( $this->db->prepare(
				"SELECT id, order_id, $account_column AS account_id,
				 $currency_column AS currency, $exponent_column AS exponent,
				 $amount_column AS disputed_minor, status AS state, created_at
				 FROM $table WHERE " . implode( ' AND ', $where ) .
				" ORDER BY id DESC LIMIT %d", ...$args
			), ARRAY_A );
			$this->check_db();
			if ( ! is_array( $results ) ) throw new RuntimeException( 'Exception source query failed.' );
			foreach ( $results as $row ) {
				$id = self::positive( $row['id'] );
				if ( $id > intdiv( PHP_INT_MAX - 3, 4 ) ) {
					throw new InvalidArgumentException( 'Exception case ID exceeds safe combined cursor range.' );
				}
				$virtual = $id * 4 + $type;
				$currency = (string) $row['currency'];
				$state = (string) $row['state'];
				$exponent = self::nonnegative( $row['exponent'] );
				if ( ! preg_match( '/\A[A-Z]{3}\z/', $currency ) ||
					! in_array( $state, [ 'open', 'reviewed' ], true ) ||
					$exponent > 3 ) {
					throw new InvalidArgumentException( 'Malformed financial exception state or currency.' );
				}
				$created = DateTimeImmutable::createFromFormat(
					'!Y-m-d H:i:s', (string) $row['created_at'], new DateTimeZone( 'UTC' )
				);
				if ( ! $created ) throw new InvalidArgumentException( 'Exception date is invalid.' );
				$days = max( 0, intdiv( $now - $created->getTimestamp(), 86400 ) );
				$age_band = $days <= 2 ? '0-2d' : ( $days <= 7 ? '3-7d' : ( $days <= 30 ? '8-30d' : '31+d' ) );
				$collected[] = [
					'id' => $virtual, 'case_id' => $id, 'case_type' => $kind,
					'order_id' => self::positive( $row['order_id'] ),
					'bank_account_id' => self::positive( $row['account_id'] ),
					'currency' => $currency, 'exponent' => $exponent,
					'state' => $state, 'age_days' => $days, 'age_band' => $age_band,
					'disputed_minor_non_additive' => self::nonnegative( $row['disputed_minor'] ),
					// Case amount is NOT a ledger amount: two cases can flag
					// the exact same imported bank credit.
					'amount_minor' => 0,
					'created_at_utc' => $created->format( 'Y-m-d H:i:s' ),
					'financial_hold' => true,
				];
			}
		}
		usort( $collected, static fn ( array $a, array $b ): int => $b['id'] <=> $a['id'] );
		$has_more = count( $collected ) > $scope->limit;
		$rows = array_slice( $collected, 0, $scope->limit );
		$age_counts = [];
		foreach ( $rows as $case ) {
			$bucket = $case['currency'] . '/' . $case['case_type'] . '/' . $case['state'] . '/' . $case['age_band'];
			$age_counts[$bucket] = ( $age_counts[$bucket] ?? 0 ) + 1;
		}
		ksort( $age_counts, SORT_STRING );
		return [
			'definition' => $scope->canonical(),
			'fingerprint' => $scope->fingerprint(),
			'generated_at_utc' => gmdate( 'Y-m-d H:i:s' ),
			'rows' => $rows, 'page_totals' => PerCurrencyTotals::summarize( $rows ),
			'age_bucket_counts' => $age_counts,
			'has_more' => $has_more,
			'next_before_id' => $has_more && $rows ? $rows[count( $rows ) - 1]['id'] : 0,
			'non_additive_case_exposure' => true,
			'payment_authorized' => false, 'ledger_mutated' => false,
		];
	}

	private static function positive( mixed $value ): int {
		$result = self::nonnegative( $value );
		if ( $result < 1 ) throw new InvalidArgumentException( 'Missing report case identifier.' );
		return $result;
	}

	private static function nonnegative( mixed $value ): int {
		if ( is_int( $value ) && $value >= 0 ) return $value;
		if ( ! is_string( $value ) ||
			1 !== preg_match( '/\A(?:0|[1-9][0-9]*)\z/', $value ) ||
			strlen( $value ) > strlen( (string) PHP_INT_MAX ) ||
			( strlen( $value ) === strlen( (string) PHP_INT_MAX ) &&
				strcmp( $value, (string) PHP_INT_MAX ) > 0 ) ) {
			throw new InvalidArgumentException( 'Invalid exception financial integer.' );
		}
		return (int) $value;
	}

	private function check_db(): void {
		if ( '' !== $this->db->last_error ) throw new RuntimeException( 'Exception reporting source unavailable.' );
	}
}
