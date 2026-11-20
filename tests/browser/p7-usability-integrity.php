<?php
/**
 * P7-T008 post-browser database invariant check: genuine WooCommerce CRUD.
 * Only a disposable CI MariaDB database; no financial writes or approvals.
 */
use WPEssential\BankTransferMatcher\Claims\ClaimRepository;
use WPEssential\BankTransferMatcher\Orders\ReferenceRepository;
use WPEssential\BankTransferMatcher\Domain\EntityId;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'wbm_ci' !== DB_NAME ||
	getenv( 'GITHUB_ACTIONS' ) !== 'true' ) {
	throw new RuntimeException( 'P7 role integrity checks require isolated CI WordPress/MariaDB.' );
}
$source = getenv( 'P7_BROWSER_FIXTURE_FILE' );
if ( ! is_string( $source ) || ! str_starts_with( $source, sys_get_temp_dir() . '/' ) ) {
	throw new RuntimeException( 'Untrusted browser evidence file.' );
}
$fixture = json_decode( (string) file_get_contents( $source ), true, 512, JSON_THROW_ON_ERROR );
if ( ! is_array( $fixture ) || ( $fixture['disposable'] ?? false ) !== true ) {
	throw new RuntimeException( 'Only synthetic CI fixture IDs are allowed.' );
}
global $wpdb;
$claims = new ClaimRepository( $wpdb );
$references = new ReferenceRepository( $wpdb );
foreach ( [ 'order_id', 'unclaimed_order_id' ] as $field ) {
	$id = $fixture[$field] ?? 0;
	if ( ! is_int( $id ) || $id < 1 ) throw new RuntimeException( 'Invalid synthetic order ID.' );
	$order = wc_get_order( $id );
	if ( ! $order || 'bacs' !== $order->get_payment_method() ||
		! $order->has_status( 'on-hold' ) || $order->is_paid() ||
		null !== $order->get_date_paid() ) {
		throw new RuntimeException( 'Browser workflow unexpectedly changed unpaid BACS financial state: ' . $field );
	}
	if ( ! $references->find( new EntityId( $id ) ) ) {
		throw new RuntimeException( 'Synthetic checkout payment reference was lost.' );
	}
	$has_claim = $claims->exists( $id );
	if ( ( 'order_id' === $field ) !== $has_claim ) {
		throw new RuntimeException( 'Browser unexpectedly created, removed or altered customer claim presence.' );
	}
}
echo "PASS P7-T008 DB invariants: both genuine BACS orders remain on-hold, unpaid and referenced; claimed order unchanged and guest remains unclaimed\n";
