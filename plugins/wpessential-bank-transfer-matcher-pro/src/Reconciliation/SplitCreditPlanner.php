<?php
namespace WPEssential\BankTransferMatcherPro\Reconciliation;

use InvalidArgumentException;

/**
 * Advisory allocation preview: one imported bank credit distributed to distinct
 * orders. This is NOT a verifier, ledger writer, approval or WooCommerce paid action.
 */
final class SplitCreditPlanner {
	/**
	 * @param list<array{order_id:int,outstanding_minor:int,allocate_minor:int,currency:string,exponent:int}> $orders
	 * @return array{planned_minor:int,credit_remaining_minor:int,fully_allocated:bool,review_only:true,orders:list<array{order_id:int,allocate_minor:int,order_remaining_minor:int}>}
	 */
	public static function preview( int $credit_minor, int $already_allocated_minor,
		string $currency, int $exponent, array $orders ): array {
		if ( $credit_minor < 1 || $already_allocated_minor < 0 || $already_allocated_minor > $credit_minor ||
			! preg_match( '/\A[A-Z]{3}\z/', $currency ) || $exponent < 0 || $exponent > 3 ) {
			throw new InvalidArgumentException( 'Invalid bank credit or currency precision.' );
		}
		$available = $credit_minor - $already_allocated_minor;
		$planned = 0;
		$seen = [];
		$lines = [];
		foreach ( $orders as $row ) {
			if ( ! is_array( $row ) || ! is_int( $row['order_id'] ?? null ) ||
				! is_int( $row['outstanding_minor'] ?? null ) ||
				! is_int( $row['allocate_minor'] ?? null ) ||
				! is_int( $row['exponent'] ?? null ) ||
				! is_string( $row['currency'] ?? null ) ) {
				throw new InvalidArgumentException( 'Split allocations require exact integer minor units.' );
			}
			$id = $row['order_id'];
			$amount = $row['allocate_minor'];
			$owed = $row['outstanding_minor'];
			if ( $id < 1 || isset( $seen[ $id ] ) || $owed < 1 || $amount < 1 ||
				$amount > $owed || $amount > $available ||
				$row['currency'] !== $currency || $row['exponent'] !== $exponent ) {
				throw new InvalidArgumentException( 'Duplicate order, inconsistent currency, or over-allocated source/order.' );
			}
			$seen[ $id ] = true;
			$available -= $amount;
			$planned += $amount;
			$lines[] = [
				'order_id' => $id, 'allocate_minor' => $amount,
				'order_remaining_minor' => $owed - $amount,
			];
		}
		return [
			'planned_minor' => $planned,
			'credit_remaining_minor' => $available,
			'fully_allocated' => 0 === $available,
			'review_only' => true,
			'orders' => $lines,
		];
	}
}
