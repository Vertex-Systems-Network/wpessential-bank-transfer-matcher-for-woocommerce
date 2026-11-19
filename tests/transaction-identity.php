<?php

use WPEssential\BankTransferMatcherPro\Import\CanonicalStatementRow;
use WPEssential\BankTransferMatcherPro\Import\MappingProfile;
use WPEssential\BankTransferMatcherPro\Import\RawStatementRow;
use WPEssential\BankTransferMatcherPro\Import\TransactionIdentity;

require dirname( __DIR__ ) . '/plugins/wpessential-bank-transfer-matcher-pro/vendor/autoload.php';

$service = new TransactionIdentity();
$row = new CanonicalStatementRow( 2, '2026-11-18', 12500, 'USD', 2, 'REF-1', 'Incoming', '000123' );
$same = new CanonicalStatementRow( 3, '2026-11-18', 12500, 'USD', 2, 'REF-1', 'Incoming', '000123' );
$changed = new CanonicalStatementRow( 4, '2026-11-18', 12600, 'USD', 2, 'REF-1', 'Incoming', '000123' );
$other_id = new CanonicalStatementRow( 5, '2026-11-18', 12500, 'USD', 2, 'REF-1', 'Incoming', '000124' );
$trusted = $service->identify( 7, $row, true );
if ( 'exact_repeat' !== $service->compare( $trusted, $service->identify( 7, $same, true ) ) ||
	'id_conflict' !== $service->compare( $trusted, $service->identify( 7, $changed, true ) ) ||
	'distinct' !== $service->compare( $trusted, $service->identify( 7, $other_id, true ) ) ||
	'distinct' !== $service->compare( $trusted, $service->identify( 8, $same, true ) ) ||
	'possible_duplicate' !== $service->compare( $service->identify( 7, $row ), $service->identify( 7, $same ) ) ) throw new RuntimeException( 'Transaction identity safety policy failed.' );
if ( $trusted['key'] === $service->identify( 7, $row )['key'] || $trusted['fingerprint'] !== $service->identify( 7, $row )['fingerprint'] ) throw new RuntimeException( 'Bank ID and advisory fingerprint scopes overlap.' );

$profile = MappingProfile::from_array( [ 'id' => 'id-fixture', 'name' => 'ID fixture', 'delimiter' => ',', 'date_header' => 'Date', 'date_format' => 'Y-m-d', 'amount_header' => 'Amount', 'decimal_separator' => '.', 'thousands_separator' => '', 'exponent' => 2, 'currency_header' => '', 'fixed_currency' => 'USD', 'reference_header' => 'Reference', 'description_header' => '', 'transaction_id_header' => 'Bank ID' ] );
$mapped = $profile->map( new RawStatementRow( 2, [ 'Date' => '2026-11-18', 'Amount' => '125.00', 'Reference' => 'REF-1', 'Bank ID' => '000123' ] ) );
if ( '000123' !== $mapped->transaction_id || 'Bank ID' !== MappingProfile::from_array( $profile->to_array() )->transaction_id_header ) throw new RuntimeException( 'Bank ID mapping lost leading zeros.' );
try { $profile->map( new RawStatementRow( 3, [ 'Date' => '2026-11-18', 'Amount' => '125.00', 'Reference' => 'REF-1', 'Bank ID' => '' ] ) ); throw new RuntimeException( 'Empty configured bank ID accepted.' ); }
catch ( InvalidArgumentException $expected ) {}
echo "PASS scoped bank transaction ID, conflict and advisory fingerprint policy\n";
