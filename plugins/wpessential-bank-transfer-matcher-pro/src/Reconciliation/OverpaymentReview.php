<?php
namespace WPEssential\BankTransferMatcherPro\Reconciliation;

use InvalidArgumentException;

/**
 * Side-effect-free calculation of imported-credit overpayments and exceptions.
 * Never authorizes allocation, refunds, FX conversion or WooCommerce payment.
 */
final class OverpaymentReview {
	/**
	 * @return array{
	 *   currency:string,exponent:int,proposed_minor:int,
	 *   order_outstanding_before_minor:int,order_outstanding_after_minor:int,
	 *   credit_available_before_minor:int,credit_unallocated_after_minor:int,
	 *   overpayment_minor:int,classification:string,
	 *   exception_codes:list<string>,review_only:true,order_payment_changed:false
	 * }
	 */
	public static function assess( int $expected_minor, int $order_allocated_minor,
		int $credit_minor, int $credit_allocated_minor, int $proposed_minor,
		string $currency, int $exponent ): array {
		if ( $expected_minor < 1 || $credit_minor < 1 ||
			$order_allocated_minor < 0 || $credit_allocated_minor < 0 ||
			$order_allocated_minor > $expected_minor || $credit_allocated_minor > $credit_minor ||
			$proposed_minor < 0 || $exponent < 0 || $exponent > 3 ||
			1 !== preg_match( '/\A[A-Z]{3}\z/', $currency ) ) {
			throw new InvalidArgumentException( 'Invalid exact-money review snapshot.' );
		}
		$order_due = $expected_minor - $order_allocated_minor;
		$credit_available = $credit_minor - $credit_allocated_minor;
		if ( $proposed_minor > $order_due || $proposed_minor > $credit_available ) {
			throw new InvalidArgumentException( 'Review request would over-allocate an order or bank credit.' );
		}
		$order_after = $order_due - $proposed_minor;
		$credit_after = $credit_available - $proposed_minor;
		$overpayment = max( 0, $credit_available - $order_due );
		$exceptions = [];
		if ( $order_due === 0 ) {
			$exceptions[] = 'no_order_balance';
		}
		if ( $overpayment > 0 ) {
			$exceptions[] = 'overpayment';
		}
		if ( $credit_after > 0 ) {
			$exceptions[] = 'unallocated_credit_remainder';
		}
		if ( $order_after > 0 ) {
			$exceptions[] = 'partial_order_funding';
		}
		$classification = 'balanced';
		if ( $overpayment > 0 ) {
			$classification = 'overpayment_review';
		} elseif ( $credit_after > 0 ) {
			$classification = 'unallocated_credit_review';
		} elseif ( $order_after > 0 ) {
			$classification = 'partial_order_review';
		}
		return [
			'currency' => $currency, 'exponent' => $exponent,
			'proposed_minor' => $proposed_minor,
			'order_outstanding_before_minor' => $order_due,
			'order_outstanding_after_minor' => $order_after,
			'credit_available_before_minor' => $credit_available,
			'credit_unallocated_after_minor' => $credit_after,
			'overpayment_minor' => $overpayment,
			'classification' => $classification,
			'exception_codes' => $exceptions,
			'review_only' => true,
			'order_payment_changed' => false,
		];
	}
}
