<?php

namespace WPEssential\BankTransferMatcherPro\Matching;

/**
 * Pure pre-match gate. It does not write candidates, allocations or WooCommerce state.
 */
final class EligibilityPolicy {
	public function evaluate( EligibilityContext $context ): EligibilityDecision {
		$reasons = [];
		if ( ! $context->is_credit ) {
			$reasons[] = ExplanationCode::CREDIT_REQUIRED;
		}
		if ( $context->is_duplicate_suspect ) {
			$reasons[] = ExplanationCode::DUPLICATE_SUSPECT;
		}
		if ( ! $context->currency_supported ) {
			$reasons[] = ExplanationCode::SUPPORTED_CURRENCY_REQUIRED;
		}
		if ( ! $context->is_bacs_order ) {
			$reasons[] = ExplanationCode::BACS_ORDER_REQUIRED;
		}
		if ( ! $context->is_order_open ) {
			$reasons[] = ExplanationCode::ORDER_OPEN_REQUIRED;
		}
		if ( $context->is_order_paid ) {
			$reasons[] = ExplanationCode::ORDER_UNPAID_REQUIRED;
		}
		if ( [] !== $reasons ) {
			return new EligibilityDecision( false, $reasons );
		}
		return new EligibilityDecision( true, [ ExplanationCode::ELIGIBLE ] );
	}
}
