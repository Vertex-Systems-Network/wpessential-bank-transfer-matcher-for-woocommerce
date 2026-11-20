<?php
namespace WPEssential\BankTransferMatcherPro\Reporting;

use InvalidArgumentException;

/**
 * Pure exact-integer totals by ISO currency, currency exponent AND state.
 * Proposed money must never silently become approved, committed or paid.
 */
final class PerCurrencyTotals {
	/**
	 * @param iterable<array<string,mixed>> $rows
	 * @return array<string,array{currency:string,exponent:int,state:string,amount_minor:int,count:int}>
	 */
	public static function summarize( iterable $rows ): array {
		$totals = [];
		$seen = 0;
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ++$seen > 20000 ) {
				throw new InvalidArgumentException( 'Unbounded or malformed report rows.' );
			}
			$currency = $row['currency'] ?? null;
			$state = $row['state'] ?? null;
			if ( ! is_string( $currency ) ||
				1 !== preg_match( '/\A[A-Z]{3}\z/', $currency ) ||
				! is_string( $state ) ||
				1 !== preg_match( '/\A[a-z][a-z0-9_-]{0,39}\z/', $state ) ) {
				throw new InvalidArgumentException( 'Invalid currency or segregated financial report state.' );
			}
			$exponent = self::unsigned( $row['exponent'] ?? null );
			$amount = self::unsigned( $row['amount_minor'] ?? null );
			if ( $exponent > 3 ) {
				throw new InvalidArgumentException( 'Unsupported minor-unit currency precision.' );
			}
			$key = $currency . '/' . $exponent . '/' . $state;
			if ( ! isset( $totals[$key] ) ) {
				$totals[$key] = [
					'currency' => $currency, 'exponent' => $exponent,
					'state' => $state, 'amount_minor' => 0, 'count' => 0,
				];
			}
			$previous = $totals[$key]['amount_minor'];
			if ( $amount > PHP_INT_MAX - $previous ) {
				throw new InvalidArgumentException( 'Financial report amount overflow.' );
			}
			$totals[$key]['amount_minor'] = $previous + $amount;
			++$totals[$key]['count'];
		}
		ksort( $totals, SORT_STRING );
		return $totals;
	}

	private static function unsigned( mixed $value ): int {
		if ( is_int( $value ) && $value >= 0 ) return $value;
		if ( ! is_string( $value ) ||
			1 !== preg_match( '/\A(?:0|[1-9][0-9]*)\z/', $value ) ||
			strlen( $value ) > strlen( (string) PHP_INT_MAX ) ||
			( strlen( $value ) === strlen( (string) PHP_INT_MAX ) &&
				strcmp( $value, (string) PHP_INT_MAX ) > 0 ) ) {
			throw new InvalidArgumentException( 'Report money must be a safe unsigned minor-unit integer.' );
		}
		return (int) $value;
	}
}
