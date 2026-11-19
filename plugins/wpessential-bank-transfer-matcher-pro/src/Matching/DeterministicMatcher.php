<?php

namespace WPEssential\BankTransferMatcherPro\Matching;

/** Exact-only matcher. It emits advisory candidates and performs no writes. */
final class DeterministicMatcher {
	public function match( EligibilityContext $context, EligibilityDecision $eligibility, ExactMatchFacts $facts ): MatchCandidate {
		if ( ! $eligibility->eligible ) {
			return MatchCandidate::from_eligibility( $context, $eligibility );
		}

		$reasons = [];
		$reference_exact = ExactMatchFacts::normalize_reference( $facts->transaction_reference )
			=== ExactMatchFacts::normalize_reference( $facts->order_reference );
		if ( $reference_exact ) {
			$reasons[] = ExplanationCode::EXACT_REFERENCE;
		}
		if ( $facts->transaction_amount_minor === $facts->order_outstanding_minor ) {
			$reasons[] = ExplanationCode::EXACT_AMOUNT;
		}
		if ( $facts->transaction_currency === $facts->order_currency ) {
			$reasons[] = ExplanationCode::EXACT_CURRENCY;
		}
		if ( $facts->transaction_bank_account_id === $facts->order_bank_account_id ) {
			$reasons[] = ExplanationCode::EXACT_ACCOUNT;
		}

		$all_exact = 4 === count( $reasons );
		$unique = 1 === $facts->eligible_reference_match_count;
		if ( ! $unique ) {
			$reasons[] = ExplanationCode::AMBIGUOUS;
		}
		if ( [] === $reasons ) {
			$reasons[] = ExplanationCode::AMBIGUOUS;
		}

		return new MatchCandidate(
			$context->transaction_id,
			$context->order_id,
			$all_exact && $unique ? 'suggested' : 'review_required',
			$reasons,
		);
	}
}
