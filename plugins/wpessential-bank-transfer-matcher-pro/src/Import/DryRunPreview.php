<?php

namespace WPEssential\BankTransferMatcherPro\Import;

use InvalidArgumentException;

final class DryRunPreview {
	public function __construct( private readonly CsvParser $parser = new CsvParser() ) {}

	public function preview( string $bytes, MappingProfile $profile ): DryRunResult {
		$raw_rows = $this->parser->parse( $bytes, $profile->delimiter );
		$rows = array(); $fingerprints = array(); $references = array();
		foreach ( $raw_rows as $raw ) {
			try {
				$canonical = $profile->map( $raw );
				$rows[] = new DryRunRow( $raw->row_number, $canonical, null );
				$fingerprints[ $canonical->fingerprint ][] = count( $rows ) - 1;
				$key = self::reference_key( $canonical->reference );
				if ( '' !== $key ) $references[ $key ][ $canonical->fingerprint ][] = count( $rows ) - 1;
			} catch ( InvalidArgumentException $error ) {
				$rows[] = new DryRunRow( $raw->row_number, null, $error->getMessage() );
			}
		}
		$warnings = array_fill( 0, count( $rows ), array() );
		foreach ( $fingerprints as $indexes ) if ( count( $indexes ) > 1 ) foreach ( $indexes as $index ) $warnings[ $index ][] = 'duplicate_exact';
		foreach ( $references as $fingerprint_groups ) {
			if ( count( $fingerprint_groups ) < 2 ) continue;
			foreach ( $fingerprint_groups as $indexes ) foreach ( $indexes as $index ) $warnings[ $index ][] = 'reference_reused';
		}
		foreach ( $rows as $index => $row ) if ( [] !== $warnings[ $index ] ) $rows[ $index ] = $row->with_warnings( $warnings[ $index ] );
		return new DryRunResult( $rows );
	}

	private static function reference_key( string $reference ): string { return strtoupper( preg_replace( '/\s+/u', '', trim( $reference ) ) ?? trim( $reference ) ); }
}
