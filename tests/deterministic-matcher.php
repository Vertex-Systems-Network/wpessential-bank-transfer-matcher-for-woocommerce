<?php

use WPEssential\BankTransferMatcherPro\Matching\DeterministicMatcher;
use WPEssential\BankTransferMatcherPro\Matching\EligibilityContext;
use WPEssential\BankTransferMatcherPro\Matching\EligibilityPolicy;
use WPEssential\BankTransferMatcherPro\Matching\ExactMatchFacts;
use WPEssential\BankTransferMatcherPro\Matching\ExplanationCode;

require dirname( __DIR__ ) . '/plugins/wpessential-bank-transfer-matcher-pro/vendor/autoload.php';

$context = new EligibilityContext( 10, 20, 12500, 'USD', 3, true, false, true, true, false );
$eligibility = ( new EligibilityPolicy() )->evaluate( $context );
$matcher = new DeterministicMatcher();
$exact = $matcher->match( $context, $eligibility, new ExactMatchFacts( ' WBM-001 ', 'wbm001', 12500, 12500, 'USD', 'USD', 3, 3, 1 ) );
if ( 'suggested' !== $exact->status || [ ExplanationCode::EXACT_REFERENCE, ExplanationCode::EXACT_AMOUNT, ExplanationCode::EXACT_CURRENCY, ExplanationCode::EXACT_ACCOUNT ] !== $exact->explanations ) {
	throw new RuntimeException( 'Unique exact match was not deterministic.' );
}
$ambiguous = $matcher->match( $context, $eligibility, new ExactMatchFacts( 'WBM-001', 'WBM-001', 12500, 12500, 'USD', 'USD', 3, 3, 2 ) );
if ( 'review_required' !== $ambiguous->status || ExplanationCode::AMBIGUOUS !== $ambiguous->explanations[4] ) {
	throw new RuntimeException( 'Non-unique reference was suggested.' );
}
$mismatch = $matcher->match( $context, $eligibility, new ExactMatchFacts( 'WBM-001', 'WBM-002', 12500, 12000, 'USD', 'EUR', 3, 4, 0 ) );
if ( 'review_required' !== $mismatch->status || [ ExplanationCode::AMBIGUOUS ] !== $mismatch->explanations ) {
	throw new RuntimeException( 'Mismatch was promoted.' );
}
echo "PASS unique exact deterministic matching rules\n";
