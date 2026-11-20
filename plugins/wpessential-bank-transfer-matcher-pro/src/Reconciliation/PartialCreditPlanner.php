<?php
namespace WPEssential\BankTransferMatcherPro\Reconciliation;

use InvalidArgumentException;

/**
 * Read-only preview of merchant-selected credits funding one order.
 * Does not accept a bank file as verified or create/approve ledger allocations.
 */
final class PartialCreditPlanner {
	/**
	 * @param list<array{source_id:int,available_minor:int,allocate_minor:int,currency:string,exponent:int}> $credits
	 * @return array{planned_minor:int,order_outstanding_minor:int,fully_covered:bool,credits:list<array{source_id:int,allocate_minor:int,source_remaining_minor:int}>,review_only:true}
	 */
	public static function preview(
		int $expected_minor, int $already_allocated_minor, string $currency, int $exponent, array $credits
	): array {
		if ( $expected_minor < 1 || $already_allocated_minor < 0 ||
			$already_allocated_minor > $expected_minor || ! preg_match( '/\A[A-Z]{3}\z/', $currency ) ||
			$exponent < 0 || $exponent > 3 ) {
			throw new InvalidArgumentException( 'Invalid order balance or currency precision.' );
		}
		$remaining = $expected_minor - $already_allocated_minor;
		$planned = 0;
		$seen = [];
		$preview = [];
		foreach ( $credits as $credit ) {
			if ( ! is_array( $credit ) || ! is_int( $credit['source_id'] ?? null ) ||
				! is_int( $credit['available_minor'] ?? null ) || ! is_int( $credit['allocate_minor'] ?? null ) ||
				! is_int( $credit['exponent'] ?? null ) || ! is_string( $credit['currency'] ?? null ) ) {
				throw new InvalidArgumentException( 'Invalid or non-integer bank credit preview.' );
			}
			$id = $credit['source_id'];
			$amount = $credit['allocate_minor'];
			$available = $credit['available_minor'];
			if ( $id < 1 || isset( $seen[ $id ] ) || $available < 1 || $amount < 1 ||
				$credit['currency'] !== $currency || $credit['exponent'] !== $exponent ||
				$amount > $available || $amount > $remaining ) {
				throw new InvalidArgumentException( 'Duplicate, mismatched or over-allocated bank credit.' );
			}
			$seen[ $id ] = true;
			$remaining -= $amount;
			$planned += $amount;
			$preview[] = [
				'source_id' => $id, 'allocate_minor' => $amount,
				'source_remaining_minor' => $available - $amount,
			];
		}
		return [
			'planned_minor' => $planned,
			'order_outstanding_minor' => $remaining,
			'fully_covered' => 0 === $remaining,
			'credits' => $preview,
			'review_only' => true,
		];
	}
}
