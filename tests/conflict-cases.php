<?php

use WPEssential\BankTransferMatcherPro\Matching\ConflictCaseGenerator;
use WPEssential\BankTransferMatcherPro\Matching\ConflictFacts;
use WPEssential\BankTransferMatcherPro\Matching\ExplanationCode;

require dirname( __DIR__ ) . '/plugins/wpessential-bank-transfer-matcher-pro/vendor/autoload.php';

$generator = new ConflictCaseGenerator();
$cases = $generator->generate( new ConflictFacts( 9, 12, true, true, true, true, true, true ) );
if ( 3 !== count( $cases ) || 'duplicate_suspect' !== $cases[0]->type || 'reversal' !== $cases[1]->type || 'conflict' !== $cases[2]->type ) {
	throw new RuntimeException( 'Conflict cases were not deterministically separated.' );
}
if ( 'critical' !== $cases[1]->priority || [ ExplanationCode::REVERSAL_DETECTED ] !== $cases[1]->explanations ) {
	throw new RuntimeException( 'Reversal case priority or reason is unsafe.' );
}
if ( [
	ExplanationCode::REFERENCE_CONFLICT,
	ExplanationCode::AMOUNT_CONFLICT,
	ExplanationCode::CURRENCY_CONFLICT,
	ExplanationCode::ACCOUNT_CONFLICT,
] !== $cases[2]->explanations ) {
	throw new RuntimeException( 'Conflict explanation order changed.' );
}
if ( [] !== $generator->generate( new ConflictFacts( 10, null, false, false, false, false, false, false ) ) ) {
	throw new RuntimeException( 'Clean evidence generated a review case.' );
}
echo "PASS duplicate, reversal and conflict case generation\n";
