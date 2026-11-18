<?php

use WPEssential\BankTransferMatcherPro\Admin\MappingProfilesPage;
use WPEssential\BankTransferMatcherPro\Import\MappingProfileRepository;
use WPEssential\BankTransferMatcherPro\Import\RawStatementRow;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'wbm_ci' !== DB_NAME ) throw new RuntimeException( 'Run against the disposable wbm_ci database.' );
$old = get_option( 'wpessential_wbm_pro_mapping_profiles', null );
$admin = get_user_by( 'login', 'wbm_ci' );
try {
	wp_set_current_user( $admin->ID );
	$profile = MappingProfilesPage::process_create( [
		'name' => 'EU fixture', 'delimiter' => ';', 'date_header' => 'Booking Date', 'date_format' => 'd/m/Y',
		'amount_header' => 'Credit', 'decimal_separator' => ',', 'thousands_separator' => '.', 'exponent' => '2',
		'currency_header' => '', 'fixed_currency' => 'eur', 'reference_header' => 'Reference', 'description_header' => 'Description',
	] );
	if ( is_wp_error( $profile ) ) throw new RuntimeException( 'Valid mapping profile was rejected: ' . $profile->get_error_message() );
	$canonical = $profile->map( new RawStatementRow( 2, [ 'Booking Date' => '18/11/2026', 'Credit' => '1.234,50', 'Reference' => 'TX-1', 'Description' => 'Incoming transfer' ] ) );
	if ( '2026-11-18' !== $canonical->posted_date || 123450 !== $canonical->amount_minor || 'EUR' !== $canonical->currency || 2 !== $canonical->exponent ) throw new RuntimeException( 'EU locale mapping changed exact canonical values.' );
	$negative = $profile->map( new RawStatementRow( 3, [ 'Booking Date' => '17/11/2026', 'Credit' => '-4,5', 'Reference' => 'REV-1', 'Description' => 'Reversal' ] ) );
	if ( -450 !== $negative->amount_minor ) throw new RuntimeException( 'Signed amount or fractional padding is incorrect.' );

	$reject_map = static function ( array $fields, string $message ) use ( $profile ): void {
		try { $profile->map( new RawStatementRow( 4, $fields ) ); } catch ( InvalidArgumentException $error ) { return; }
		throw new RuntimeException( $message );
	};
	$reject_map( [ 'Booking Date' => '31/02/2026', 'Credit' => '1,00', 'Reference' => '', 'Description' => '' ], 'Impossible date was accepted.' );
	$reject_map( [ 'Booking Date' => '18/11/2026', 'Credit' => '12,345', 'Reference' => '', 'Description' => '' ], 'Amount beyond configured precision was accepted.' );
	$reject_map( [ 'Booking Date' => '18/11/2026', 'Credit' => '12.34,50', 'Reference' => '', 'Description' => '' ], 'Invalid thousands grouping was accepted.' );

	$ambiguous = MappingProfilesPage::process_create( [ 'name' => 'Ambiguous', 'delimiter' => ',', 'date_header' => 'Date', 'date_format' => 'Y-m-d', 'amount_header' => 'Amount', 'decimal_separator' => '.', 'thousands_separator' => '.', 'exponent' => '2', 'currency_header' => 'Currency', 'fixed_currency' => 'USD' ] );
	if ( ! is_wp_error( $ambiguous ) || 'wbm_pro_invalid_mapping' !== $ambiguous->get_error_code() ) throw new RuntimeException( 'Ambiguous separators/currency sources were accepted.' );
	$stored = ( new MappingProfileRepository() )->all();
	if ( 1 !== count( $stored ) || $profile->id !== $stored[0]->id ) throw new RuntimeException( 'Mapping profile persistence round trip failed.' );
	ob_start(); MappingProfilesPage::render(); $html = ob_get_clean();
	if ( ! str_contains( $html, 'EU fixture' ) || ! str_contains( $html, 'wbm_pro_save_mapping_profile' ) || ! str_contains( $html, 'rejected instead of guessed' ) ) throw new RuntimeException( 'Mapping editor does not expose the saved profile and strict behavior.' );
	wp_set_current_user( 0 );
	$forbidden = MappingProfilesPage::process_create( [] );
	if ( ! is_wp_error( $forbidden ) || 403 !== $forbidden->get_error_data() ) throw new RuntimeException( 'Anonymous mapping profile creation was accepted.' );
	echo "PASS mapping profile editor, persistence and strict locale/date/amount/currency validation\n";
} finally {
	if ( null === $old ) delete_option( 'wpessential_wbm_pro_mapping_profiles' ); else update_option( 'wpessential_wbm_pro_mapping_profiles', $old, false );
}
