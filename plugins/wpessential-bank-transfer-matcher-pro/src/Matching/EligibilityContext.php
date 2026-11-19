<?php

namespace WPEssential\BankTransferMatcherPro\Matching;

use InvalidArgumentException;

final readonly class EligibilityContext {
	public function __construct(
		public int $transaction_id,
		public int $order_id,
		public int $amount_minor,
		public string $currency,
		public int $bank_account_id,
		public bool $is_credit,
		public bool $is_duplicate_suspect,
		public bool $is_bacs_order,
		public bool $is_order_open,
		public bool $is_order_paid,
		public bool $currency_supported = true,
	) {
		if ( $transaction_id < 1 || $order_id < 1 || $bank_account_id < 1 ) {
			throw new InvalidArgumentException( 'Matching identifiers must be positive.' );
		}
		if ( $amount_minor < 1 ) {
			throw new InvalidArgumentException( 'A candidate transaction must have a positive minor-unit amount.' );
		}
		if ( ! preg_match( '/^[A-Z]{3}$/', $currency ) ) {
			throw new InvalidArgumentException( 'Currency must be an uppercase ISO-style code.' );
		}
	}
}
