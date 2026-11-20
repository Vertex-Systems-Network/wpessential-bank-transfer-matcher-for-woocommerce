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
 * Read-only bounded Pro reporting, with no REST/customer access, no payment
 * hooks and no bank source mutations. T002 gradually adds report providers.
 */
final class ReportQueryRepository {
	public function __construct( private readonly wpdb $db ) {}

	/**
	 * Results and exact totals are scoped to this PAGE, not the full database.
	 * This is an informational as-of-query view, NOT an accounting authorization.
	 * @return array<string,mixed>
	 */
	public function run( ReportDefinition $definition ): array {
		if ( get_current_user_id() < 1 || ! current_user_can( ProCaps::IMPORT ) ||
			! current_user_can( FreeCaps::APPROVE ) ) {
			throw new RuntimeException( 'Financial reports require explicit finance permissions.' );
		}
		if ( in_array( $definition->report, [ 'outstanding_orders', 'partial_funding' ], true ) ) {
			return ( new OrderFundingReportRepository( $this->db ) )->run( $definition );
		}
		if ( 'unmatched_credits' === $definition->report ) {
			return ( new UnmatchedCreditReportRepository( $this->db ) )->run( $definition );
		}
		$from = $definition->from . ' 00:00:00';
		$end = ( new DateTimeImmutable( $definition->to . ' 00:00:00', new DateTimeZone( 'UTC' ) ) )
			->modify( '+1 day' )->format( 'Y-m-d H:i:s' );
		$report = $definition->report;
		$table = match ( $report ) {
			'allocation_audit' => $this->db->prefix . 'wbm_pro_allocations',
			'import_batches' => $this->db->prefix . 'wbm_pro_import_batches',
			default => throw new InvalidArgumentException( 'This report provider is not yet available.' ),
		};
		$exists = $this->db->get_var( $this->db->prepare( 'SHOW TABLES LIKE %s', $table ) );
		$this->check_db();
		if ( $exists !== $table ) {
			throw new RuntimeException( 'Required report source schema is not installed.' );
		}
		$fields = $report === 'allocation_audit'
			? 'id, order_id, transaction_row_id, bank_account_id, amount_minor,
			   currency, exponent, state, created_at'
			: 'id, account_id AS bank_account_id, currency, status AS state,
			   valid_count, error_count, warning_count, created_at';
		$conditions = [ 'created_at >= %s', 'created_at < %s' ];
		$params = [ $from, $end ];
		if ( $definition->bank_account_id > 0 ) {
			$conditions[] = ( $report === 'allocation_audit' ? 'bank_account_id' : 'account_id' ) . ' = %d';
			$params[] = $definition->bank_account_id;
		}
		if ( 'ALL' !== $definition->currency ) {
			$conditions[] = 'currency = %s';
			$params[] = $definition->currency;
		}
		if ( 'all' !== $definition->state ) {
			$conditions[] = ( $report === 'allocation_audit' ? 'state' : 'status' ) . ' = %s';
			$params[] = $definition->state;
		}
		if ( $definition->before_id > 0 ) {
			$conditions[] = 'id < %d';
			$params[] = $definition->before_id;
		}
		$params[] = $definition->limit + 1;
		$where = implode( ' AND ', $conditions );
		$records = $this->db->get_results( $this->db->prepare(
			"SELECT $fields FROM $table WHERE $where ORDER BY id DESC LIMIT %d", ...$params
		), ARRAY_A );
		$this->check_db();
		if ( ! is_array( $records ) ) throw new RuntimeException( 'Bounded finance report query failed.' );
		$has_more = count( $records ) > $definition->limit;
		$records = array_slice( $records, 0, $definition->limit );
		$rows = [];
		$amounts = [];
		foreach ( $records as $record ) {
			$id = self::unsigned( $record['id'] );
			$account = self::unsigned( $record['bank_account_id'] );
			$currency = (string) $record['currency'];
			$state = (string) $record['state'];
			if ( $id < 1 || $account < 1 ||
				! in_array( $state, $report === 'allocation_audit'
					? [ 'proposed', 'approved', 'committed', 'reversed' ]
					: [ 'pending', 'processing', 'completed', 'failed', 'cancelled' ], true ) ) {
				throw new InvalidArgumentException( 'Report source state or identifier is malformed.' );
			}
			$base = [
				'id' => $id, 'bank_account_id' => $account,
				'currency' => $currency, 'state' => $state,
				'created_at_utc' => (string) $record['created_at'],
			];
			if ( 'allocation_audit' === $report ) {
				$exponent = self::unsigned( $record['exponent'] );
				$amount = self::unsigned( $record['amount_minor'] );
				if ( $amount < 1 || $exponent > 3 ) {
					throw new InvalidArgumentException( 'Approved or proposed allocation amount is invalid.' );
				}
				$base += [
					'order_id' => self::unsigned( $record['order_id'] ),
					'source_id' => self::unsigned( $record['transaction_row_id'] ),
					'amount_minor' => $amount, 'exponent' => $exponent,
				];
			} else {
				// Batch counts are counts, NEVER money or proof of cleared funds.
				$base += [
					'valid_count' => self::unsigned( $record['valid_count'] ),
					'error_count' => self::unsigned( $record['error_count'] ),
					'warning_count' => self::unsigned( $record['warning_count'] ),
					'amount_minor' => 0, 'exponent' => 0,
				];
			}
			$amounts[] = [
				'amount_minor' => $base['amount_minor'], 'currency' => $currency,
				'exponent' => $base['exponent'], 'state' => $state,
			];
			$rows[] = $base;
		}
		$totals = PerCurrencyTotals::summarize( $amounts );
		return [
			'definition' => $definition->canonical(),
			'fingerprint' => $definition->fingerprint(),
			'generated_at_utc' => gmdate( 'Y-m-d H:i:s' ),
			'rows' => $rows, 'page_totals' => $totals,
			'has_more' => $has_more,
			'next_before_id' => $has_more && $rows ? $rows[count( $rows ) - 1]['id'] : 0,
			'payment_authorized' => false,
			'ledger_mutated' => false,
		];
	}

	private static function unsigned( mixed $value ): int {
		if ( is_int( $value ) && $value >= 0 ) return $value;
		if ( ! is_string( $value ) ||
			1 !== preg_match( '/\A(?:0|[1-9][0-9]*)\z/', $value ) ||
			strlen( $value ) > strlen( (string) PHP_INT_MAX ) ||
			( strlen( $value ) === strlen( (string) PHP_INT_MAX ) &&
				strcmp( $value, (string) PHP_INT_MAX ) > 0 ) ) {
			throw new InvalidArgumentException( 'Invalid unsigned report source value.' );
		}
		return (int) $value;
	}

	private function check_db(): void {
		if ( '' !== $this->db->last_error ) throw new RuntimeException( 'Read-only report source unavailable.' );
	}
}
