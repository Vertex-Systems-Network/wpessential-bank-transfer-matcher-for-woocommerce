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

	/** @param resource $stream @return list<RawStatementRow> */
	private function read( $stream, string $delimiter ): array {
		$headers = fgetcsv( $stream, null, $delimiter, '"', '' );
		if ( false === $headers || [] === $headers || count( $headers ) > self::MAX_COLUMNS ) throw new InvalidArgumentException( 'The CSV header is missing or has too many columns.' );
		$headers = array_map( static fn ( $value ): string => trim( (string) $value ), $headers );
		if ( in_array( '', $headers, true ) || count( array_unique( $headers ) ) !== count( $headers ) ) throw new InvalidArgumentException( 'CSV headers must be non-empty and unique.' );
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
