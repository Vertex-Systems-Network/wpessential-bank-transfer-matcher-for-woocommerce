<?php

namespace WPEssential\BankTransferMatcherPro\Import;

use InvalidArgumentException;
use RuntimeException;

final class CsvParser {
	public const MAX_ROWS = 10000;
	public const MAX_COLUMNS = 100;
	public const MAX_CELL_BYTES = 10000;

	/** @return list<RawStatementRow> */
	public function parse( string $bytes, string $delimiter = ',' ): array {
		if ( '' === $bytes ) throw new InvalidArgumentException( 'The CSV file is empty.' );
		if ( str_contains( $bytes, "\0" ) || 1 !== preg_match( '//u', $bytes ) ) throw new InvalidArgumentException( 'The CSV file must contain valid UTF-8 text without binary data.' );
		if ( ! in_array( $delimiter, array( ',', ';', "\t" ), true ) ) throw new InvalidArgumentException( 'The CSV delimiter is unsupported.' );
		if ( str_starts_with( $bytes, "\xEF\xBB\xBF" ) ) $bytes = substr( $bytes, 3 );
		$stream = fopen( 'php://temp', 'w+b' );
		if ( false === $stream || false === fwrite( $stream, $bytes ) || ! rewind( $stream ) ) throw new RuntimeException( 'Could not open the CSV input.' );
		try { return $this->read( $stream, $delimiter ); }
		finally { fclose( $stream ); }
	}

	/**
	 * Use the exact same fail-closed header checks for dry-run parsing,
	 * private-import preflight and resumable queued import workers.
	 *
	 * @param array<int, null|string>|false $values
	 * @return list<string>
	 */
	public static function validated_headers( array|false $values ): array {
		if ( false === $values || [] === $values || count( $values ) > self::MAX_COLUMNS ) {
			throw new InvalidArgumentException( 'CSV headers are missing or too numerous.' );
		}
		$headers = array();
		foreach ( $values as $index => $value ) {
			$header = (string) $value;
			if ( 0 === $index ) {
				$header = preg_replace( '/^\\xEF\\xBB\\xBF/', '', $header ) ?? $header;
			}
			$header = trim( $header );
			if ( '' === $header || strlen( $header ) > self::MAX_CELL_BYTES ||
				str_contains( $header, "\0" ) || 1 !== preg_match( '//u', $header ) ) {
				throw new InvalidArgumentException( 'CSV headers contain an invalid or oversized field.' );
			}
			$headers[] = $header;
		}
		if ( count( $headers ) !== count( array_unique( $headers ) ) ) {
			throw new InvalidArgumentException( 'CSV headers must be non-empty and unique.' );
		}
		return $headers;
	}

	/** @param resource $stream @return list<RawStatementRow> */
	private function read( $stream, string $delimiter ): array {
		$headers = self::validated_headers( fgetcsv( $stream, null, $delimiter, '"', '' ) );
		$rows = array(); $line = 1;
		while ( false !== ( $values = fgetcsv( $stream, null, $delimiter, '"', '' ) ) ) {
			++$line;
			if ( array( null ) === $values || ( 1 === count( $values ) && '' === $values[0] ) ) continue;
			if ( count( $values ) !== count( $headers ) ) throw new InvalidArgumentException( sprintf( 'CSV row %d has a different column count.', $line ) );
			if ( count( $rows ) >= self::MAX_ROWS ) throw new InvalidArgumentException( 'The CSV row limit was exceeded.' );
			$fields = array();
			foreach ( $headers as $index => $header ) {
				$value = (string) $values[ $index ];
				if ( strlen( $value ) > self::MAX_CELL_BYTES ) throw new InvalidArgumentException( sprintf( 'CSV row %d contains a field that is too large.', $line ) );
				$fields[ $header ] = $value;
			}
			$rows[] = new RawStatementRow( $line, $fields );
		}
		if ( [] === $rows ) throw new InvalidArgumentException( 'The CSV file has no data rows.' );
		return $rows;
	}
}
