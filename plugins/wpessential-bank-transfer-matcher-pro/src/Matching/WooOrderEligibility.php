<?php

namespace WPEssential\BankTransferMatcherPro\Matching;

use InvalidArgumentException;

/** Converts current WooCommerce order facts into the pure eligibility contract. */
final class WooOrderEligibility {
	public function context(
		int $transaction_id,
		int $amount_minor,
		string $currency,
		int $bank_account_id,
		bool $duplicate_suspect,
		object $order,
		bool $currency_supported = true,
	): EligibilityContext {
		foreach ( [ 'get_id', 'get_status', 'get_payment_method', 'is_paid' ] as $method ) {
			if ( ! method_exists( $order, $method ) ) throw new InvalidArgumentException( 'A WooCommerce order is required.' );
		}
		$status = (string) $order->get_status();
		return new EligibilityContext(
			$transaction_id,
			(int) $order->get_id(),
			$amount_minor,
			$currency,
			$bank_account_id,
			$amount_minor > 0,
			$duplicate_suspect,
			'bacs' === $order->get_payment_method(),
			in_array( $status, [ 'pending', 'on-hold' ], true ),
			(bool) $order->is_paid(),
			$currency_supported,
		);
	}
}
