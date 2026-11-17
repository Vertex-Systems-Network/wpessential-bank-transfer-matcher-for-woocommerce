<?php

namespace WPEssential\BankTransferMatcher\Domain;

final readonly class PaymentReference {
	public function __construct( public string $value ) {
		if ( 1 !== preg_match( '/\A[A-Z0-9-]{1,64}\z/', $value ) ) {
			throw new \InvalidArgumentException( 'Invalid issued payment reference.' );
		}
	}

	public static function issue( string $prefix = 'WBM' ): self {
		if ( 1 !== preg_match( '/\A[A-Z0-9]+(?:-[A-Z0-9]+)*\z/', $prefix ) || 39 < strlen( $prefix ) ) {
			throw new \InvalidArgumentException( 'Invalid payment reference prefix.' );
		}

		return new self( $prefix . '-' . strtoupper( bin2hex( random_bytes( 12 ) ) ) );
	}
}
