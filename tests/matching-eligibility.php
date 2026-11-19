<?php

use WPEssential\BankTransferMatcherPro\Matching\EligibilityContext;
use WPEssential\BankTransferMatcherPro\Matching\EligibilityPolicy;
use WPEssential\BankTransferMatcherPro\Matching\ExplanationCode;
use WPEssential\BankTransferMatcherPro\Matching\MatchCandidate;

require dirname( __DIR__ ) . '/plugins/wpessential-bank-transfer-matcher-pro/vendor/autoload.php';

$policy = new EligibilityPolicy();
$eligible = new EligibilityContext( 10, 20, 12500, 'USD', 3, true, false, true, true, false );
$decision = $policy->evaluate( $eligible );
$candidate = MatchCandidate::from_eligibility( $eligible, $decision );
if ( ! $decision->eligible || ExplanationCode::ELIGIBLE !== $decision->primary_code() || 'review_required' !== $candidate->status ) {
	throw new RuntimeException( 'Eligible candidate contract failed.' );
}
$blocked = new EligibilityContext( 11, 21, 12500, 'USD', 3, false, true, false, false, true, false );
$blocked_decision = $policy->evaluate( $blocked );
$expected = [
	ExplanationCode::CREDIT_REQUIRED,
	ExplanationCode::DUPLICATE_SUSPECT,
	ExplanationCode::SUPPORTED_CURRENCY_REQUIRED,
	ExplanationCode::BACS_ORDER_REQUIRED,
	ExplanationCode::ORDER_OPEN_REQUIRED,
	ExplanationCode::ORDER_UNPAID_REQUIRED,
];
if ( $blocked_decision->eligible || $expected !== $blocked_decision->explanations || 'blocked' !== MatchCandidate::from_eligibility( $blocked, $blocked_decision )->status ) {
	throw new RuntimeException( 'Blocked candidate reasons are incomplete or nondeterministic.' );
}
try {
	new EligibilityContext( 1, 2, 0, 'USD', 3, true, false, true, true, false );
	throw new RuntimeException( 'Zero-value transaction accepted.' );
} catch ( InvalidArgumentException $expected_exception ) {
}
echo "PASS matching candidate, explanation and eligibility contracts\n";
