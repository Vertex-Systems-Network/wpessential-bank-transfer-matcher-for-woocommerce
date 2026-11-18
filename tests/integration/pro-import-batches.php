<?php

use WPEssential\BankTransferMatcherPro\Import\BankAccountRepository;
use WPEssential\BankTransferMatcherPro\Import\ImportBatchRepository;
use WPEssential\BankTransferMatcherPro\Import\MappingProfile;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'wbm_ci' !== DB_NAME ) throw new RuntimeException( 'Run against disposable wbm_ci only.' );
global $wpdb;
$actor = get_user_by( 'login', 'wbm_ci' );
$account = ( new BankAccountRepository( $wpdb ) )->create( 'Import fixture', 'Fixture Bank', 'USD', '1234', $actor->ID );
$repo = new ImportBatchRepository( $wpdb );
$profile = MappingProfile::from_array( [ 'id' => 'fixture', 'name' => 'Fixture', 'delimiter' => ',', 'date_header' => 'Date', 'date_format' => 'Y-m-d', 'amount_header' => 'Amount', 'decimal_separator' => '.', 'thousands_separator' => '', 'exponent' => 2, 'currency_header' => '', 'fixed_currency' => 'USD', 'reference_header' => 'Reference', 'description_header' => '' ] );
try {
	$id = $repo->create( $account, $profile, hash( 'sha256', 'synthetic fixture bytes' ), 23, $actor->ID );
	$rows = $repo->history();
	if ( (int) $rows[0]['id'] !== $id || 'pending' !== $rows[0]['status'] || 'USD' !== $rows[0]['currency'] ) throw new RuntimeException( 'Batch history lost provenance.' );
	if ( $repo->snapshot( $id ) !== $profile->to_array() ) throw new RuntimeException( 'Profile snapshot changed.' );
	$repo->transition( $id, 'pending', 'processing' );
	$repo->transition( $id, 'processing', 'completed', 2, 1, 1 );
	if ( (int) $repo->history()[0]['valid_count'] !== 2 ) throw new RuntimeException( 'Batch counts were not retained.' );
	try { $repo->transition( $id, 'processing', 'failed' ); throw new RuntimeException( 'Stale worker was accepted.' ); }
	catch ( RuntimeException $error ) { if ( 'Stale worker was accepted.' === $error->getMessage() ) throw $error; }
	try { $repo->create( $account, $profile, 'invalid', 23, $actor->ID ); throw new RuntimeException( 'Invalid hash was accepted.' ); }
	catch ( InvalidArgumentException $expected ) {}
	if ( count( $repo->history( 1, 1 ) ) !== 1 || [] !== $repo->history( 2, 1 ) && (int) $repo->history( 2, 1 )[0]['id'] === $id ) throw new RuntimeException( 'History pagination failed.' );
	echo "PASS Pro import batch provenance, immutable snapshot, state CAS and history\n";
} finally {
	$wpdb->delete( $wpdb->prefix . 'wbm_pro_import_batches', [ 'id' => $id ?? 0 ], [ '%d' ] );
	$wpdb->delete( $wpdb->prefix . 'wbm_pro_bank_accounts', [ 'id' => $account ], [ '%d' ] );
}
