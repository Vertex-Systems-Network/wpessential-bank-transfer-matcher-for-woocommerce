<?php

use WPEssential\BankTransferMatcherPro\Import\CanonicalStatementRow;
use WPEssential\BankTransferMatcherPro\Import\CsvParser;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'wbm_ci' !== DB_NAME ) throw new RuntimeException( 'Run against the disposable wbm_ci database.' );
$parser = new CsvParser();
$csv = "\xEF\xBB\xBFDate,Amount,Currency,Reference,Description\r\n2026-11-18,25.00,USD,TX-100,\"Customer, transfer\"\r\n2026-11-17,-4.50,USD,REV-1,\"Reversal\ncontinued\"\r\n";
$rows = $parser->parse( $csv );
if ( 2 !== count( $rows ) || 2 !== $rows[0]->row_number || 'Customer, transfer' !== $rows[0]->value( 'Description' ) || "Reversal\ncontinued" !== $rows[1]->value( 'Description' ) ) {
	throw new RuntimeException( 'Quoted CSV fields, BOM or raw provenance were parsed incorrectly.' );
}
$semicolon = $parser->parse( "Date;Amount;Currency\n2026-11-18;10,50;EUR\n", ';' );
if ( '10,50' !== $semicolon[0]->value( 'Amount' ) ) throw new RuntimeException( 'Explicit semicolon delimiter changed the raw decimal value.' );
$canonical = new CanonicalStatementRow( 2, '2026-11-18', 2500, 'USD', 2, ' tx-100 ', 'Customer   transfer' );
$same = new CanonicalStatementRow( 99, '2026-11-18', 2500, 'USD', 2, 'TX-100', 'customer transfer' );
if ( $canonical->fingerprint !== $same->fingerprint || 2500 !== $canonical->amount_minor ) throw new RuntimeException( 'Canonical row fingerprint or exact minor units are unstable.' );

$reject = static function ( string $bytes, string $message, string $delimiter = ',' ) use ( $parser ): void {
	try { $parser->parse( $bytes, $delimiter ); }
	catch ( InvalidArgumentException $error ) { return; }
	throw new RuntimeException( $message );
};
$reject( "Date,Date\n2026-11-18,2026-11-18\n", 'Duplicate headers were accepted.' );
$reject( "Date,Amount\n2026-11-18\n", 'Uneven columns were accepted.' );
$reject( "Date,Amount\n2026-11-18,\0bad\n", 'Binary CSV input was accepted.' );
$reject( "Date,Amount\n", 'Header-only CSV input was accepted.' );
$reject( "Date|Amount\n2026-11-18|1.00\n", 'Unsupported delimiter was accepted.', '|' );
try { new CanonicalStatementRow( 2, '18/11/2026', 2500, 'USD', 2, 'TX', 'Description' ); }
catch ( InvalidArgumentException $error ) { echo "PASS streaming CSV raw rows, quoting, UTF-8 guards and exact canonical representation\n"; return; }
throw new RuntimeException( 'Non-canonical date was accepted.' );
