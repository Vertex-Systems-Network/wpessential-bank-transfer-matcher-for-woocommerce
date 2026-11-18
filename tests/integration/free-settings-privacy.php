<?php

use WPEssential\BankTransferMatcher\Admin\SettingsPage;
use WPEssential\BankTransferMatcher\Claims\ClaimRepository;
use WPEssential\BankTransferMatcher\Domain\Money;
use WPEssential\BankTransferMatcher\Privacy\PersonalData;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'wbm_ci' !== DB_NAME ) {
	throw new RuntimeException( 'Run against the disposable wbm_ci database.' );
}
global $wpdb;
$admin = get_user_by( 'login', 'wbm_ci' );
wp_set_current_user( $admin->ID );
$old_option = get_option( 'wpessential_wbm_claim_uploads', null );
$order = wc_create_order( [ 'status' => 'on-hold' ] );
$order->set_payment_method( 'bacs' );
$order->set_billing_email( 'wbm-privacy-fixture@example.test' );
$order->set_total( '15.00' );
$order->save();
try {
	( new ClaimRepository( $wpdb ) )->save( $order->get_id(), [ 'date' => gmdate( 'Y-m-d' ), 'amount' => Money::from_decimal( '15.00', $order->get_currency(), wc_get_price_decimals() ), 'reference' => 'PRIVACY-TEST', 'sender' => 'Private sender', 'note' => 'Private note' ] );
	$exporters = PersonalData::exporters( [] );
	$erasers = PersonalData::erasers( [] );
	if ( ! isset( $exporters['wbm-claims'], $erasers['wbm-claims'] ) ) {
		throw new RuntimeException( 'Privacy callbacks are missing.' );
	}
	$export = PersonalData::export( 'wbm-privacy-fixture@example.test' );
	if ( count( $export['data'] ) !== 1 || ! str_contains( wp_json_encode( $export ), 'Private sender' ) || str_contains( wp_json_encode( $export ), 'attachment_bytes' ) ) {
		throw new RuntimeException( 'Customer claim export is incomplete or exposes receipt bytes.' );
	}
	$erase = PersonalData::erase( 'wbm-privacy-fixture@example.test' );
	$after = PersonalData::export( 'wbm-privacy-fixture@example.test' );
	if ( ! $erase['items_removed'] || ! $erase['items_retained'] || str_contains( wp_json_encode( $after ), 'Private sender' ) || ! str_contains( wp_json_encode( $after ), 'PRIVACY-TEST' ) ) {
		throw new RuntimeException( 'Erasure did not redact personal fields while retaining financial provenance.' );
	}
	if ( PersonalData::export( 'other@example.test' )['data'] || PersonalData::erase( 'other@example.test' )['items_removed'] ) {
		throw new RuntimeException( 'Privacy request crossed customer boundaries.' );
	}
	update_option( 'wpessential_wbm_claim_uploads', 'yes' );
	ob_start();
	SettingsPage::render();
	$html = ob_get_clean();
	if ( ! str_contains( $html, 'name="wbm_claim_uploads"' ) || ! str_contains( $html, 'wbm_shop_caps[]' ) || ! str_contains( $html, '_wpnonce' ) || ! str_contains( $html, 'Export personal data' ) ) {
		throw new RuntimeException( 'Settings controls or privacy links are absent.' );
	}
	echo "PASS settings controls and targeted personal data export/erasure\n";
} finally {
	if ( null === $old_option ) {
		delete_option( 'wpessential_wbm_claim_uploads' );
	} else {
		update_option( 'wpessential_wbm_claim_uploads', $old_option );
	}
	$wpdb->delete( $wpdb->prefix . 'wbm_payment_claims', [ 'order_id' => $order->get_id() ], [ '%d' ] );
	$order->delete( true );
}
