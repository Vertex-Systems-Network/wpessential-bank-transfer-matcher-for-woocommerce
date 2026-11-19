<?php
namespace WPEssential\BankTransferMatcherPro\Reconciliation;

use InvalidArgumentException;

/**
 * Strict review-only assessment of bank source and order mismatches.
 * No source evidence can trigger WooCommerce payment on this basis.
 */
final class MismatchReview {
	/**
	 * @param array{order_id:int,method:string,status:string,currency:string,exponent:int,
	 *   outstanding_minor:int,reference:string,expected_bank_account_id:int} $order
	 * @param array{source_id:int,bank_account_id:int,currency:string,exponent:int,
	 *   credit_minor:int,reference:string,unique:bool,cleared_confirmed:bool} $source
	 * @return array{codes:list<string>,classification:string,review_only:true,
	 *   auto_pay_allowed:false,order_payment_changed:false}
	 */
	public static function inspect( array $order, array $source ): array {
		foreach ( [ 'order_id', 'exponent', 'outstanding_minor', 'expected_bank_account_id' ] as $key ) {
			if ( ! is_int( $order[$key] ?? null ) ) throw new InvalidArgumentException( 'Invalid order snapshot: ' . $key );
		}
		foreach ( [ 'source_id', 'bank_account_id', 'exponent', 'credit_minor' ] as $key ) {
			if ( ! is_int( $source[$key] ?? null ) ) throw new InvalidArgumentException( 'Invalid imported source snapshot: ' . $key );
		}
		foreach ( [ 'method', 'status', 'currency', 'reference' ] as $key ) {
			if ( ! is_string( $order[$key] ?? null ) ) throw new InvalidArgumentException( 'Invalid order field: ' . $key );
		}
		foreach ( [ 'currency', 'reference' ] as $key ) {
			if ( ! is_string( $source[$key] ?? null ) ) throw new InvalidArgumentException( 'Invalid bank source field: ' . $key );
		}
		if ( ! is_bool( $source['unique'] ?? null ) || ! is_bool( $source['cleared_confirmed'] ?? null ) ||
			$order['order_id'] < 1 || $source['source_id'] < 1 || $source['bank_account_id'] < 1 ||
			$order['expected_bank_account_id'] < 0 || $order['outstanding_minor'] < 0 ||
			$source['credit_minor'] < 1 || $order['exponent'] < 0 || $order['exponent'] > 3 ||
			$source['exponent'] < 0 || $source['exponent'] > 3 ||
			! preg_match( '/\A[A-Z]{3}\z/', $order['currency'] ) ||
			! preg_match( '/\A[A-Z]{3}\z/', $source['currency'] ) ) {
			throw new InvalidArgumentException( 'Invalid monetary identity or currency precision.' );
		}
		$order_reference = self::normalize( $order['reference'] );
		$source_reference = self::normalize( $source['reference'] );
		if ( '' === $order_reference || '' === $source_reference ) {
			throw new InvalidArgumentException( 'A bank and order payment reference are required.' );
		}
		$codes = [];
		if ( 'bacs' !== $order['method'] ) $codes[] = 'unsupported_gateway';
		if ( ! in_array( $order['status'], [ 'pending', 'on-hold' ], true ) ) $codes[] = 'ineligible_order_status';
		if ( ! $source['unique'] ) $codes[] = 'ambiguous_bank_source';
		if ( ! $source['cleared_confirmed'] ) $codes[] = 'unconfirmed_bank_clearance';
		if ( 0 === $order['expected_bank_account_id'] ) {
			$codes[] = 'receiving_bank_account_unconfirmed';
		} elseif ( $source['bank_account_id'] !== $order['expected_bank_account_id'] ) {
			$codes[] = 'receiving_bank_account_mismatch';
		}
		if ( $order['currency'] !== $source['currency'] ) $codes[] = 'currency_mismatch';
		if ( $order['exponent'] !== $source['exponent'] ) $codes[] = 'currency_precision_mismatch';
		if ( $order_reference !== $source_reference ) $codes[] = 'payment_reference_mismatch';
		if ( 0 === $order['outstanding_minor'] ) {
			$codes[] = 'no_outstanding_order_balance';
		} elseif ( $source['credit_minor'] > $order['outstanding_minor'] ) {
			$codes[] = 'credit_exceeds_outstanding';
		} elseif ( $source['credit_minor'] < $order['outstanding_minor'] ) {
			$codes[] = 'partial_bank_credit';
		}
		return [
			'codes' => $codes, 'classification' => [] === $codes ? 'exact_candidate_review' : 'mismatch_review',
			'review_only' => true, 'auto_pay_allowed' => false, 'order_payment_changed' => false,
		];
	}
	private static function normalize( string $reference ): string {
		return strtoupper( preg_replace( '/[^A-Z0-9]+/i', '', trim( $reference ) ) ?? '' );
	}
}
