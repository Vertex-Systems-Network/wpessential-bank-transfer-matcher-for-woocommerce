<?php

namespace WPEssential\BankTransferMatcher\Admin;

use InvalidArgumentException;
use OverflowException;
use WC_Order;
use WPEssential\BankTransferMatcher\Domain\Money;

defined( 'ABSPATH' ) || exit;

final class OrderBalanceFilter {
	// Reuse the exact row calculation within a bounded candidate set.
	public const MAX_CANDIDATES = 250;

	private readonly ?int $minimum;
	private readonly ?int $maximum;

	public function __construct(
		private readonly OrderSummary $summary,
		private readonly string $state,
		string $currency,
		string $minimum,
		string $maximum
	) {
		if ( ( '' !== $minimum || '' !== $maximum ) && '' === $currency ) {
			throw new InvalidArgumentException( 'Outstanding amount filters require a currency.' );
		}
		$this->minimum = self::parse_amount( $minimum, $currency );
		$this->maximum = self::parse_amount( $maximum, $currency );
		if ( null !== $this->minimum && null !== $this->maximum && $this->minimum > $this->maximum ) {
			throw new InvalidArgumentException( 'Invalid outstanding amount range.' );
		}
	}

	public function matches( WC_Order $order ): bool {
		try {
			$balance = $this->summary->balance( $order );
		} catch ( OrderBalanceException|InvalidArgumentException|OverflowException $error ) {
			return 'exception' === $this->state && null === $this->minimum && null === $this->maximum;
		}

		$state = OrderSummary::state( $balance );
		if ( '' !== $this->state && $state !== $this->state ) {
			return false;
		}
		if ( null === $this->minimum && null === $this->maximum ) {
			return true;
		}
		return 'exception' !== $state &&
			( null === $this->minimum || $balance['outstanding'] >= $this->minimum ) &&
			( null === $this->maximum || $balance['outstanding'] <= $this->maximum );
	}

	private static function parse_amount( string $value, string $currency ): ?int {
		if ( '' === $value ) {
			return null;
		}
		if ( strlen( $value ) > 30 || '-' === $value[0] ) {
			throw new InvalidArgumentException( 'Invalid outstanding amount.' );
		}
		return Money::from_decimal( $value, $currency, wc_get_price_decimals() )->minor_units;
	}
}
