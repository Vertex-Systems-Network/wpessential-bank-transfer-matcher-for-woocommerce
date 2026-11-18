<?php

use WPEssential\BankTransferMatcherPro\Import\DryRunPreview;
use WPEssential\BankTransferMatcherPro\Import\MappingProfile;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'wbm_ci' !== DB_NAME ) throw new RuntimeException( 'Run against the disposable wbm_ci database.' );
$profile = MappingProfile::from_array( [
	'id' => 'dry-run-fixture', 'name' => 'Dry run fixture', 'delimiter' => ',', 'date_header' => 'Date', 'date_format' => 'Y-m-d',
	'amount_header' => 'Amount', 'decimal_separator' => '.', 'thousands_separator' => ',', 'exponent' => 2,
	'currency_header' => 'Currency', 'fixed_currency' => '', 'reference_header' => 'Reference', 'description_header' => 'Description',
] );
$csv = <<<'CSV'
Date,Amount,Currency,Reference,Description
2026-11-18,"1,250.00",USD,TX-100,Incoming credit
2026-11-18,"1,250.00",USD,tx-100,Incoming   credit
2026-11-18,1251.00,USD,TX-100,Different amount
2026-02-30,10.00,USD,BAD-DATE,Invalid date
2026-11-18,10.999,USD,BAD-AMOUNT,Invalid precision
2026-11-17,-5.00,USD,,Reversal
CSV;
$before = $GLOBALS['wpdb']->num_queries;
$result = ( new DryRunPreview() )->preview( $csv, $profile );
if ( 6 !== count( $result->rows ) || 4 !== $result->valid_count || 2 !== $result->error_count || 3 !== $result->warning_count ) throw new RuntimeException( 'Dry-run summary counts are incorrect.' );
if ( [ 'duplicate_exact', 'reference_reused' ] !== $result->rows[0]->warnings || [ 'duplicate_exact', 'reference_reused' ] !== $result->rows[1]->warnings || [ 'reference_reused' ] !== $result->rows[2]->warnings ) throw new RuntimeException( 'Exact duplicate or reused-reference warnings are incorrect.' );
if ( null === $result->rows[3]->error || ! str_contains( $result->rows[3]->error, 'invalid date' ) || null === $result->rows[4]->error || ! str_contains( $result->rows[4]->error, 'precision' ) ) throw new RuntimeException( 'Row-level mapping errors are missing.' );
if ( -500 !== $result->rows[5]->canonical?->amount_minor || [] !== $result->rows[5]->warnings ) throw new RuntimeException( 'Valid reversal row was changed or incorrectly warned.' );
if ( $before !== $GLOBALS['wpdb']->num_queries ) throw new RuntimeException( 'Dry run wrote to or queried the database.' );
try { ( new DryRunPreview() )->preview( "Date,Date\n2026-11-18,2026-11-18\n", $profile ); }
catch ( InvalidArgumentException $error ) { echo "PASS side-effect-free dry run, row errors, exact duplicate and reused-reference warnings\n"; return; }
throw new RuntimeException( 'Structural CSV error was downgraded to a row warning.' );
