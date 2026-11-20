<?php
use WPEssential\BankTransferMatcherPro\Privacy\FinanceRetentionPreferences;

require dirname( __DIR__ ) .
	'/plugins/wpessential-bank-transfer-matcher-pro/src/Privacy/FinanceRetentionPreferences.php';

$defaults = FinanceRetentionPreferences::defaults();
if ( $defaults['automatic_cleanup'] !== false ||
	$defaults['private_source_days'] !== 0 ||
	$defaults['diagnostic_audit_days'] !== 90 ||
	$defaults['finance_export_audit_days'] < 180 ) {
	throw new RuntimeException( 'Retention policy default was unexpectedly destructive.' );
}
$submitted = array_replace( $defaults, [
	'automatic_cleanup' => '1', 'private_source_days' => '30',
	'diagnostic_audit_days' => '30',
] );
$fixed = FinanceRetentionPreferences::normalize( $submitted );
if ( $fixed['automatic_cleanup'] !== true ||
	$fixed['private_source_days'] !== 30 ||
	$fixed['diagnostic_audit_days'] !== 30 ||
	FinanceRetentionPreferences::normalize( $defaults ) !== $defaults ) {
	throw new RuntimeException( 'Retention policy lost explicit consent or finite windows.' );
}
foreach ( [
	array_replace( $defaults, [ 'automatic_cleanup' => 'yes' ] ),
	array_replace( $defaults, [ 'private_source_days' => '-1' ] ),
	array_replace( $defaults, [ 'private_source_days' => '1' ] ),
	array_replace( $defaults, [ 'diagnostic_audit_days' => 1 ] ),
	array_replace( $defaults, [ 'notification_audit_days' => '90.0' ] ),
	array_replace( $defaults, [ 'recovery_audit_days' => 10000 ] ),
	array_replace( $defaults, [ 'unknown' => 'write-anywhere' ] ),
	[ 'automatic_cleanup' => true ],
] as $bad ) {
	try {
		FinanceRetentionPreferences::normalize( $bad );
		throw new RuntimeException( 'Unsafe or incomplete finance retention policy was accepted.' );
	} catch ( InvalidArgumentException $expected ) {}
}
echo "PASS retention default-off, private import source never-deleted default, approved immutable finite windows and unsafe-policy denial\n";
