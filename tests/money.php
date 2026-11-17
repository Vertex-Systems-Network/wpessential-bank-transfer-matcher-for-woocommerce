<?php

use WPEssential\BankTransferMatcher\Domain\Money;

require dirname( __DIR__ ) . '/plugins/wpessential-bank-transfer-matcher/vendor/autoload.php';

if (
	30 !== Money::from_decimal( '0.10', 'USD', 2 )->plus( Money::from_decimal( '0.20', 'USD', 2 ) )->minor_units ||
	-17 !== Money::from_decimal( '-17', 'JPY', 0 )->minor_units ||
	1005 !== Money::from_decimal( '1.005', 'BHD', 3 )->minor_units ||
	PHP_INT_MIN !== Money::from_decimal( (string) PHP_INT_MIN, 'JPY', 0 )->minor_units ||
	0 !== Money::from_decimal( '000.00', 'USD', 2 )->minor_units
) {
	throw new RuntimeException( 'Exact minor unit assertion failed.' );
}

$rejected = [
	[ InvalidArgumentException::class, static fn () => Money::from_decimal( '1.01', 'JPY', 0 ) ],
	[ InvalidArgumentException::class, static fn () => Money::from_decimal( '1.0', 'USD', -1 ) ],
	[ InvalidArgumentException::class, static fn () => Money::from_decimal( '1,00', 'USD', 2 ) ],
	[ InvalidArgumentException::class, static fn () => ( new Money( 1, 'USD', 2 ) )->plus( new Money( 1, 'JPY', 0 ) ) ],
	[ OverflowException::class, static fn () => Money::from_decimal( PHP_INT_MAX . '0', 'JPY', 0 ) ],
	[ OverflowException::class, static fn () => ( new Money( PHP_INT_MAX, 'JPY', 0 ) )->plus( new Money( 1, 'JPY', 0 ) ) ],
	[ OverflowException::class, static fn () => ( new Money( PHP_INT_MIN, 'JPY', 0 ) )->plus( new Money( -1, 'JPY', 0 ) ) ],
];

foreach ( $rejected as [ $exception, $operation ] ) {
	try {
		$operation();
	} catch ( Throwable $error ) {
		if ( $error instanceof $exception ) {
			continue;
		}
		throw $error;
	}
	throw new RuntimeException( 'Expected amount rejection was not raised.' );
}

echo "PASS exact money and invalid/overflow cases\n";
