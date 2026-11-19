<?php

namespace WPEssential\BankTransferMatcherPro\Import;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class MappingProfile {
	private const DATE_FORMATS = array( 'Y-m-d', 'd/m/Y', 'm/d/Y', 'd.m.Y' );
	private const DELIMITERS = array( ',', ';', "\t" );

	public function __construct(
		public string $id, public string $name, public string $delimiter,
		public string $date_header, public string $date_format,
		public string $amount_header, public string $decimal_separator, public string $thousands_separator,
		public int $exponent, public string $currency_header, public string $fixed_currency,
		public string $reference_header, public string $description_header, public string $transaction_id_header = '',
	) {
		$headers = array_filter( array( $date_header, $amount_header, $currency_header, $reference_header, $description_header, $transaction_id_header ), static fn ( string $value ): bool => '' !== $value );
		if ( '' === $id || strlen( $id ) > 64 || '' === trim( $name ) || strlen( $name ) > 80 || ! in_array( $delimiter, self::DELIMITERS, true ) ||
			! in_array( $date_format, self::DATE_FORMATS, true ) || '' === $date_header || '' === $amount_header || count( $headers ) !== count( array_unique( $headers ) ) ||
			! in_array( $decimal_separator, array( '.', ',' ), true ) || ! in_array( $thousands_separator, array( '', '.', ',', ' ' ), true ) || $decimal_separator === $thousands_separator ||
			$exponent < 0 || $exponent > 6 || ( '' === $currency_header ) === ( '' === $fixed_currency ) ||
			( '' !== $fixed_currency && 1 !== preg_match( '/\A[A-Z]{3}\z/', $fixed_currency ) ) ) throw new InvalidArgumentException( 'Mapping profile settings are invalid or ambiguous.' );
		foreach ( $headers as $header ) if ( strlen( $header ) > 100 ) throw new InvalidArgumentException( 'Mapped CSV headers must be 100 characters or fewer.' );
	}

	public static function from_array( array $data ): self {
		return new self(
			self::text( $data, 'id' ), self::text( $data, 'name' ), self::text( $data, 'delimiter' ),
			self::text( $data, 'date_header' ), self::text( $data, 'date_format' ), self::text( $data, 'amount_header' ),
			self::text( $data, 'decimal_separator' ), self::text( $data, 'thousands_separator' ), (int) ( $data['exponent'] ?? -1 ),
			self::text( $data, 'currency_header' ), strtoupper( self::text( $data, 'fixed_currency' ) ), self::text( $data, 'reference_header' ), self::text( $data, 'description_header' ), self::text( $data, 'transaction_id_header' )
		);
	}

	public function to_array(): array { return get_object_vars( $this ); }

	public function map( RawStatementRow $row ): CanonicalStatementRow {
		$date = $this->required( $row, $this->date_header );
		$parsed = DateTimeImmutable::createFromFormat( '!' . $this->date_format, $date );
		$errors = DateTimeImmutable::getLastErrors();
		if ( false === $parsed || ( is_array( $errors ) && ( $errors['warning_count'] || $errors['error_count'] ) ) || $parsed->format( $this->date_format ) !== $date ) throw new InvalidArgumentException( 'Row ' . $row->row_number . ' has an invalid date.' );
		$currency = '' !== $this->currency_header ? strtoupper( $this->required( $row, $this->currency_header ) ) : $this->fixed_currency;
		if ( 1 !== preg_match( '/\A[A-Z]{3}\z/', $currency ) ) throw new InvalidArgumentException( 'Row ' . $row->row_number . ' has an invalid ISO currency.' );
		$reference = '' === $this->reference_header ? '' : (string) $row->value( $this->reference_header );
		$description = '' === $this->description_header ? '' : (string) $row->value( $this->description_header );
		$transaction_id = '' === $this->transaction_id_header ? '' : $this->required( $row, $this->transaction_id_header );
		return new CanonicalStatementRow( $row->row_number, $parsed->format( 'Y-m-d' ), $this->minor_units( $this->required( $row, $this->amount_header ), $row->row_number ), $currency, $this->exponent, $reference, $description, $transaction_id );
	}

	private function required( RawStatementRow $row, string $header ): string {
		$value = $row->value( $header );
		if ( null === $value || '' === trim( $value ) ) throw new InvalidArgumentException( sprintf( 'Row %d is missing mapped column %s.', $row->row_number, $header ) );
		return trim( $value );
	}

	private function minor_units( string $raw, int $row ): int {
		$value = trim( $raw ); $negative = false;
		if ( str_starts_with( $value, '-' ) || str_starts_with( $value, '+' ) ) { $negative = '-' === $value[0]; $value = substr( $value, 1 ); }
		if ( '' === $value || substr_count( $value, $this->decimal_separator ) > 1 ) throw new InvalidArgumentException( "Row $row has an invalid amount." );
		$parts = explode( $this->decimal_separator, $value ); $integer = $parts[0]; $fraction = $parts[1] ?? '';
		if ( '' !== $this->thousands_separator && str_contains( $integer, $this->thousands_separator ) ) {
			$separator = preg_quote( $this->thousands_separator, '/' );
			if ( 1 !== preg_match( '/\A\d{1,3}(?:' . $separator . '\d{3})*\z/', $integer ) ) throw new InvalidArgumentException( "Row $row has invalid thousands grouping." );
			$integer = str_replace( $this->thousands_separator, '', $integer );
		}
		if ( 1 !== preg_match( '/\A\d+\z/', $integer ) || ( '' !== $fraction && 1 !== preg_match( '/\A\d+\z/', $fraction ) ) || strlen( $fraction ) > $this->exponent ) throw new InvalidArgumentException( "Row $row has an amount outside the configured precision." );
		$digits = ltrim( $integer . str_pad( $fraction, $this->exponent, '0' ), '0' ); $digits = '' === $digits ? '0' : $digits; $max = (string) PHP_INT_MAX;
		if ( strlen( $digits ) > strlen( $max ) || ( strlen( $digits ) === strlen( $max ) && strcmp( $digits, $max ) > 0 ) ) throw new InvalidArgumentException( "Row $row amount is too large." );
		$minor = (int) $digits; return $negative ? -$minor : $minor;
	}

	private static function text( array $data, string $key ): string { return is_string( $data[ $key ] ?? null ) ? trim( $data[ $key ] ) : ''; }
}
