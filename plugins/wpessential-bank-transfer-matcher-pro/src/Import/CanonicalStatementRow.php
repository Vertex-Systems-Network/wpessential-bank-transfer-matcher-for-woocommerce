<?php

namespace WPEssential\BankTransferMatcherPro\Import;

use InvalidArgumentException;

final readonly class CanonicalStatementRow {
	public string $fingerprint;

	public function __construct(
		public int $source_row,
		public string $posted_date,
		public int $amount_minor,
		public string $currency,
		public int $exponent,
		public string $reference,
		public string $description,
	) {
		if ( $source_row < 2 || 1 !== preg_match( '/\A\d{4}-\d{2}-\d{2}\z/', $posted_date ) || $amount_minor === 0 ||
			1 !== preg_match( '/\A[A-Z]{3}\z/', $currency ) || $exponent < 0 || $exponent > 6 || strlen( $reference ) > 255 || strlen( $description ) > 2000 ) {
			throw new InvalidArgumentException( 'Canonical statement row values are invalid.' );
		}
		$this->fingerprint = hash( 'sha256', implode( "\x1f", array( $posted_date, (string) $amount_minor, $currency, (string) $exponent, self::normalize( $reference ), self::normalize( $description ) ) ) );
	}

	private static function normalize( string $value ): string { return strtoupper( trim( preg_replace( '/\s+/u', ' ', $value ) ?? $value ) ); }
}
