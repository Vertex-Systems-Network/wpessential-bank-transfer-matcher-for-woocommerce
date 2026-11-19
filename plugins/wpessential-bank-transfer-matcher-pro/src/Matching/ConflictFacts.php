<?php

namespace WPEssential\BankTransferMatcherPro\Matching;

use InvalidArgumentException;

final readonly class ConflictFacts {
	public function __construct(
		public int $transaction_id,
		public ?int $order_id,
		public bool $duplicate_suspect,
		public bool $reversal,
		public bool $reference_conflict,
		public bool $amount_conflict,
		public bool $currency_conflict,
		public bool $account_conflict,
	) {
		if ( $transaction_id < 1 || ( null !== $order_id && $order_id < 1 ) ) {
			throw new InvalidArgumentException( 'Conflict identifiers must be positive.' );
		}
	}
}
