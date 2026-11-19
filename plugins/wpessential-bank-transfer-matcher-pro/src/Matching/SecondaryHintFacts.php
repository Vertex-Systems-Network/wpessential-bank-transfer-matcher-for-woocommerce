<?php

namespace WPEssential\BankTransferMatcherPro\Matching;

use InvalidArgumentException;

final readonly class SecondaryHintFacts {
	public function __construct(
		public int $booking_date_distance_days,
		public bool $description_contains_order_token,
		public bool $description_contains_customer_name,
	) {
		if ( $booking_date_distance_days < 0 ) {
			throw new InvalidArgumentException( 'Date distance cannot be negative.' );
		}
	}
}
