<?php

use WPEssential\BankTransferMatcherPro\Matching\ExplanationCode;
use WPEssential\BankTransferMatcherPro\Matching\MatchCandidate;
use WPEssential\BankTransferMatcherPro\Matching\SecondaryHintEvaluator;
use WPEssential\BankTransferMatcherPro\Matching\SecondaryHintFacts;

require dirname( __DIR__ ) . '/plugins/wpessential-bank-transfer-matcher-pro/vendor/autoload.php';

$evaluator = new SecondaryHintEvaluator();
$ambiguous = new MatchCandidate( 1, 2, 'review_required', [ ExplanationCode::EXACT_AMOUNT, ExplanationCode::AMBIGUOUS ] );
$assessed = $evaluator->assess( $ambiguous, new SecondaryHintFacts( 0, true, true ) );
if ( 'review_required' !== $assessed->candidate->status || 3 !== $assessed->rank_score ) {
	throw new RuntimeException( 'Secondary hints promoted an ambiguous candidate.' );
}
if ( [
	ExplanationCode::EXACT_AMOUNT,
	ExplanationCode::AMBIGUOUS,
	ExplanationCode::DATE_PROXIMITY_HINT,
	ExplanationCode::ORDER_TOKEN_HINT,
	ExplanationCode::CUSTOMER_NAME_HINT,
] !== $assessed->candidate->explanations ) {
	throw new RuntimeException( 'Secondary hint explanations are not deterministic.' );
}
$blocked = new MatchCandidate( 1, 3, 'blocked', [ ExplanationCode::DUPLICATE_SUSPECT ] );
if ( 'blocked' !== $evaluator->assess( $blocked, new SecondaryHintFacts( 2, true, true ) )->candidate->status ) {
	throw new RuntimeException( 'Secondary hints promoted a blocked candidate.' );
}
$none = $evaluator->assess( $ambiguous, new SecondaryHintFacts( 4, false, false ) );
if ( 0 !== $none->rank_score || $ambiguous->explanations !== $none->candidate->explanations ) {
	throw new RuntimeException( 'Out-of-window hints affected ranking.' );
}
echo "PASS bounded secondary hints and ambiguity prevention\n";
