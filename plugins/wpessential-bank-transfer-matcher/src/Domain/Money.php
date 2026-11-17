<?php
/**
 * Exact currency-aware amounts for reconciliation.
 *
 * @package WPEssential\BankTransferMatcher
 */

namespace WPEssential\BankTransferMatcher\Domain;

use InvalidArgumentException;
use OverflowException;

final class Money {
	public function __construct(
		public readonly int $minor_units,
		public readonly string $currency,
		public readonly int $exponent
	) {
		new Currency( $currency, $exponent );
	}

	public static function from_decimal( string $amount, string $currency, int $exponent ): self {
		new Currency( $currency, $exponent );
		if ( 1 !== preg_match( '/\A(-?)([0-9]+)(?:\.([0-9]+))?\z/', $amount, $parts ) ) {
			throw new InvalidArgumentException( 'Amount must be a canonical decimal.' );
		}

		$fraction = $parts[3] ?? '';
		if ( $exponent < strlen( $fraction ) ) {
			throw new InvalidArgumentException( 'Amount exceeds the configured currency precision.' );
		}

		$digits  = ltrim( $parts[2] . str_pad( $fraction, $exponent, '0' ), '0' );
		$limit   = '-' === $parts[1] ? substr( (string) PHP_INT_MIN, 1 ) : (string) PHP_INT_MAX;
		$length  = strlen( $digits );
		$maximum = strlen( $limit );
		if ( $length > $maximum || ( $length === $maximum && 0 < strcmp( $digits, $limit ) ) ) {
			throw new OverflowException( 'Amount exceeds the integer range.' );
		}

		return new self( (int) ( $parts[1] . $digits ), $currency, $exponent );
	}

	public function plus( self $other ): self {
		if ( $this->currency !== $other->currency || $this->exponent !== $other->exponent ) {
			throw new InvalidArgumentException( 'Currency and precision must match.' );
		}

		if ( ( 0 < $other->minor_units && PHP_INT_MAX - $other->minor_units < $this->minor_units ) ||
			( 0 > $other->minor_units && PHP_INT_MIN - $other->minor_units > $this->minor_units ) ) {
			throw new OverflowException( 'Total exceeds the integer range.' );
		}

		return new self( $this->minor_units + $other->minor_units, $this->currency, $this->exponent );
	}
}
