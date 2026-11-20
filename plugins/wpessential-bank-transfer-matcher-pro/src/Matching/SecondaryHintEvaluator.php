<?php

namespace WPEssential\BankTransferMatcherPro\Matching;

/**
 * Adds transparent ranking hints without changing primary-rule status.
 * Hints can never turn blocked/review_required into suggested.
 */
final class SecondaryHintEvaluator {
	public function assess( MatchCandidate $candidate, SecondaryHintFacts $facts ): HintAssessment {
		$codes = $candidate->explanations;
		$score = 0;
		if ( $facts->booking_date_distance_days <= 3 ) {
			$codes[] = ExplanationCode::DATE_PROXIMITY_HINT;
			++$score;
		}
		if ( $facts->description_contains_order_token ) {
			$codes[] = ExplanationCode::ORDER_TOKEN_HINT;
			++$score;
		}
		if ( $facts->description_contains_customer_name ) {
			$codes[] = ExplanationCode::CUSTOMER_NAME_HINT;
			++$score;
		}

		return new HintAssessment(
			new MatchCandidate( $candidate->transaction_id, $candidate->order_id, $candidate->status, $codes ),
			$score,
		);
	}
}
