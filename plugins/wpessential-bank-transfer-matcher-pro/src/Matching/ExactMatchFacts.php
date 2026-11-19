<?php

namespace WPEssential\BankTransferMatcherPro\Matching;

use InvalidArgumentException;

final readonly class ExactMatchFacts {
	public function __construct(
		public string $transaction_reference,
		public string $order_reference,
		public int $transaction_amount_minor,
		public int $order_outstanding_minor,
		public string $transaction_currency,
		public string $order_currency,
		public int $transaction_bank_account_id,
		public int $order_bank_account_id,
		public int $eligible_reference_match_count,
	) {
		if ( $transaction_amount_minor < 1 || $order_outstanding_minor < 1 || $transaction_bank_account_id < 1 || $order_bank_account_id < 1 ) {
			throw new InvalidArgumentException( 'Exact matching requires positive amounts and account identifiers.' );
		}
		if ( $eligible_reference_match_count < 0 ) {
			throw new InvalidArgumentException( 'Reference match count cannot be negative.' );
		}
	}

	public static function normalize_reference( string $reference ): string {
		$normalized = strtoupper( preg_replace( '/[^A-Z0-9]+/i', '', trim( $reference ) ) ?? '' );
		if ( '' === $normalized ) {
			throw new InvalidArgumentException( 'A non-empty payment reference is required.' );
		}
		return $normalized;
	}
}
