<?php
namespace WPEssential\BankTransferMatcherPro\Reconciliation;

use InvalidArgumentException;

/** Read-only minor-unit ledger recomputation; never an approval or payment authorization. */
final class LedgerBalance {
	/**
	 * @param list<array<string,mixed>> $rows Ledger rows involving the selected order or bank credit.
	 * @param list<array<string,mixed>> $manual_rows Existing Free manual allocations for this order.
	 * @return array<string,int|string>
	 */
	public static function summarize(
		int $order_id, int $transaction_row_id, int $expected_minor, int $credit_minor,
		string $currency, int $exponent, int $bank_account_id, array $rows, array $manual_rows = []
	): array {
		if ( min( $order_id, $transaction_row_id, $expected_minor, $credit_minor, $bank_account_id ) < 1 ||
			! preg_match( '/\A[A-Z]{3}\z/', $currency ) || $exponent < 0 || $exponent > 3 ) {
			throw new InvalidArgumentException( 'Unsupported ledger balance input.' );
		}
		$manual_used = 0;
		foreach ( $manual_rows as $row ) {
			if ( self::number( $row['expected_minor'] ?? null ) !== $expected_minor ||
				( $row['currency'] ?? null ) !== $currency || self::number( $row['exponent'] ?? null ) !== $exponent ) {
				throw new InvalidArgumentException( 'Manual allocation differs from the order snapshot.' );
			}
			$amount = self::number( $row['amount_minor'] ?? null );
			if ( $amount < 1 || $amount > $expected_minor - $manual_used ) {
				throw new InvalidArgumentException( 'Invalid Free allocation amount.' );
			}
			$manual_used += $amount;
		}
		$order_used = 0;
		$credit_used = 0;
		foreach ( $rows as $row ) {
			$order = self::number( $row['order_id'] ?? null );
			$source = self::number( $row['transaction_row_id'] ?? null );
			$account = self::number( $row['bank_account_id'] ?? null );
			$amount = self::number( $row['amount_minor'] ?? null );
			$source_total = self::number( $row['credit_minor'] ?? null );
			$order_total = self::number( $row['expected_minor'] ?? null );
			$status = $row['state'] ?? null;
			if ( min( $order, $source, $account, $amount, $source_total, $order_total ) < 1 ||
				! in_array( $status, [ 'proposed', 'approved', 'committed', 'reversed' ], true ) ||
				( $row['currency'] ?? null ) !== $currency || self::number( $row['exponent'] ?? null ) !== $exponent ||
				( $order !== $order_id && $source !== $transaction_row_id ) ) {
				throw new InvalidArgumentException( 'Invalid or unrelated allocation record.' );
			}
			if ( $source === $transaction_row_id && ( $source_total !== $credit_minor || $account !== $bank_account_id ) ) {
				throw new InvalidArgumentException( 'Bank source snapshot changed.' );
			}
			if ( ! in_array( $status, [ 'approved', 'committed' ], true ) ) continue;
			if ( $order === $order_id ) {
				if ( $order_total !== $expected_minor || $amount > $expected_minor - $manual_used - $order_used ) {
					throw new InvalidArgumentException( 'Changed or over-allocated order.' );
				}
				$order_used += $amount;
			}
			if ( $source === $transaction_row_id ) {
				if ( $amount > $credit_minor - $credit_used ) {
					throw new InvalidArgumentException( 'Bank credit was over-allocated.' );
				}
				$credit_used += $amount;
			}
		}
		return [
			'order_allocated_minor' => $order_used, 'manual_allocated_minor' => $manual_used,
			'order_outstanding_minor' => $expected_minor - $manual_used - $order_used,
			'credit_allocated_minor' => $credit_used, 'credit_unallocated_minor' => $credit_minor - $credit_used,
			'currency' => $currency, 'exponent' => $exponent,
		];
	}

	/** Reject floats and unsigned database values beyond PHP_INT_MAX rather than casting them. */
	private static function number( mixed $value ): int {
		if ( is_int( $value ) && $value >= 0 ) return $value;
		if ( ! is_string( $value ) || ! preg_match( '/\A(?:0|[1-9][0-9]*)\z/', $value ) ||
			strlen( $value ) > strlen( (string) PHP_INT_MAX ) ||
			( strlen( $value ) === strlen( (string) PHP_INT_MAX ) && strcmp( $value, (string) PHP_INT_MAX ) > 0 ) ) {
			throw new InvalidArgumentException( 'Invalid unsigned integer money or identifier.' );
		}
		return (int) $value;
	}
}
