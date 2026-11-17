<?php

namespace WPEssential\BankTransferMatcher\Domain;

final readonly class Currency {
	public function __construct( public string $code, public int $exponent ) {
		if ( 1 !== preg_match( '/\A[A-Z]{3}\z/', $code ) || 0 > $exponent || 4 < $exponent ) {
			throw new \InvalidArgumentException( 'Invalid currency code or currency precision.' );
		}
	}
}
