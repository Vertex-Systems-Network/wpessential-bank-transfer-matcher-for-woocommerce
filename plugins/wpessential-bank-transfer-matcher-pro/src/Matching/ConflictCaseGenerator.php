<?php

namespace WPEssential\BankTransferMatcherPro\Matching;

/** Pure case generation; imported evidence and WooCommerce state remain unchanged. */
final class ConflictCaseGenerator {
	/** @return list<GeneratedCase> */
	public function generate( ConflictFacts $facts ): array {
		$cases = [];
		if ( $facts->duplicate_suspect ) {
			$cases[] = new GeneratedCase(
				'duplicate_suspect',
				$facts->transaction_id,
				$facts->order_id,
				'high',
				[ ExplanationCode::DUPLICATE_SUSPECT ],
			);
		}
		if ( $facts->reversal ) {
			$cases[] = new GeneratedCase(
				'reversal',
				$facts->transaction_id,
				$facts->order_id,
				'critical',
				[ ExplanationCode::REVERSAL_DETECTED ],
			);
		}
		$conflicts = [];
		foreach ( [
			[ $facts->reference_conflict, ExplanationCode::REFERENCE_CONFLICT ],
			[ $facts->amount_conflict, ExplanationCode::AMOUNT_CONFLICT ],
			[ $facts->currency_conflict, ExplanationCode::CURRENCY_CONFLICT ],
			[ $facts->account_conflict, ExplanationCode::ACCOUNT_CONFLICT ],
		] as [ $present, $code ] ) {
			if ( $present ) {
				$conflicts[] = $code;
			}
		}
		if ( [] !== $conflicts ) {
			$cases[] = new GeneratedCase( 'conflict', $facts->transaction_id, $facts->order_id, 'high', $conflicts );
		}
		return $cases;
	}
}
