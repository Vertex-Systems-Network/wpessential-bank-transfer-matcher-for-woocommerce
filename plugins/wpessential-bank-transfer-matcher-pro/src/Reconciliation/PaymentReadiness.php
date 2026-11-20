<?php
namespace WPEssential\BankTransferMatcherPro\Reconciliation;

use InvalidArgumentException;

/**
 * Pure eligibility assessment. NOT an authorization, WooCommerce transition,
 * evidence verifier, or automatic order payment mechanism.
 */
final class PaymentReadiness {
	/**
	 * @param array{
	 *   expected_minor:int,free_allocated_minor:int,pro_approved_minor:int,
	 *   pro_approved_row_count:int,pending_allocation_reviews:int,
	 *   unresolved_financial_exceptions:int,currency:string,exponent:int,
	 *   gateway:string,order_status:string,order_is_paid:bool,
	 *   bank_sources_verified:bool,manual_evidence_verified:bool,
	 *   maker_checker_verified:bool,receiving_accounts_verified:bool
	 * } $facts
	 * @return array{ready_for_explicit_finalization:bool,reasons:list<string>,
	 *   review_only:true,auto_pay_allowed:false,order_payment_changed:false}
	 */
	public static function inspect( array $facts ): array {
		foreach ( [ 'expected_minor', 'free_allocated_minor', 'pro_approved_minor',
			'pro_approved_row_count', 'pending_allocation_reviews',
			'unresolved_financial_exceptions', 'exponent' ] as $field ) {
			if ( ! is_int( $facts[$field] ?? null ) ) {
				throw new InvalidArgumentException( 'Expected exact integer for financial readiness: ' . $field );
			}
		}
		foreach ( [ 'order_is_paid', 'bank_sources_verified', 'manual_evidence_verified',
			'maker_checker_verified', 'receiving_accounts_verified' ] as $field ) {
			if ( ! is_bool( $facts[$field] ?? null ) ) {
				throw new InvalidArgumentException( 'Expected verified boolean readiness fact: ' . $field );
			}
		}
		foreach ( [ 'currency', 'gateway', 'order_status' ] as $field ) {
			if ( ! is_string( $facts[$field] ?? null ) ) {
				throw new InvalidArgumentException( 'Invalid readiness identity: ' . $field );
			}
		}
		if ( $facts['expected_minor'] < 1 || $facts['free_allocated_minor'] < 0 ||
			$facts['pro_approved_minor'] < 0 || $facts['pro_approved_row_count'] < 0 ||
			$facts['pending_allocation_reviews'] < 0 || $facts['unresolved_financial_exceptions'] < 0 ||
			$facts['exponent'] < 0 || $facts['exponent'] > 3 ||
			1 !== preg_match( '/\A[A-Z]{3}\z/', $facts['currency'] ) ) {
			throw new InvalidArgumentException( 'Invalid financial readiness limits or currency precision.' );
		}
		$expected = $facts['expected_minor'];
		$free = $facts['free_allocated_minor'];
		$pro = $facts['pro_approved_minor'];
		$reasons = [];
		if ( $free > $expected || $pro > $expected - $free ) {
			throw new InvalidArgumentException( 'Combined Free/Pro allocations overdraw the order.' );
		}
		if ( $facts['gateway'] !== 'bacs' ) $reasons[] = 'non_bacs_gateway';
		if ( ! in_array( $facts['order_status'], [ 'pending', 'on-hold' ], true ) ) {
			$reasons[] = 'ineligible_order_status';
		}
		if ( $facts['order_is_paid'] ) $reasons[] = 'order_already_paid';
		if ( $pro === 0 || $facts['pro_approved_row_count'] === 0 ) {
			$reasons[] = 'no_reviewed_pro_credits';
		}
		if ( $free + $pro !== $expected ) $reasons[] = 'outstanding_order_balance';
		if ( $facts['pending_allocation_reviews'] > 0 ) $reasons[] = 'pending_allocation_review';
		if ( $facts['unresolved_financial_exceptions'] > 0 ) $reasons[] = 'unresolved_financial_exception';
		if ( ! $facts['bank_sources_verified'] ) $reasons[] = 'bank_source_provenance_unverified';
		if ( $free > 0 && ! $facts['manual_evidence_verified'] ) $reasons[] = 'manual_evidence_unverified';
		if ( ! $facts['maker_checker_verified'] ) $reasons[] = 'maker_checker_verification_required';
		if ( ! $facts['receiving_accounts_verified'] ) $reasons[] = 'receiving_bank_confirmation_required';
		return [
			'ready_for_explicit_finalization' => [] === $reasons,
			'reasons' => $reasons, 'review_only' => true,
			'auto_pay_allowed' => false, 'order_payment_changed' => false,
		];
	}
}
