<?php

namespace WPEssential\BankTransferMatcherPro\Import;

use InvalidArgumentException;

final readonly class RawStatementRow {
	/** @param array<string, string> $fields */
	public function __construct( public int $row_number, public array $fields ) {
		if ( $row_number < 2 || array_is_list( $fields ) || [] === $fields ) throw new InvalidArgumentException( 'A raw statement row requires its CSV row number and named fields.' );
		foreach ( $fields as $header => $value ) if ( '' === $header || ! is_string( $value ) ) throw new InvalidArgumentException( 'Raw statement fields must use non-empty header names and string values.' );
	}

	public function value( string $header ): ?string { return $this->fields[ $header ] ?? null; }
}
