<?php

use WPEssential\BankTransferMatcher\Claims\AttachmentDownloads;
use WPEssential\BankTransferMatcher\Claims\ClaimAttachment;
use WPEssential\BankTransferMatcher\Claims\ClaimRepository;
use WPEssential\BankTransferMatcher\Domain\Money;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'wbm_ci' !== DB_NAME ) {
	throw new RuntimeException( 'Run against the disposable wbm_ci database.' );
}

$blob = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=', true );
$receipt = ClaimAttachment::validate( 'receipt.png', $blob );
foreach ( [
	[ 'receipt.pdf', $blob ],
	[ 'receipt.png', 'invalid image' ],
	[ 'receipt.png', str_repeat( 'x', ClaimAttachment::MAX_BYTES + 1 ) ],
] as [ $filename, $content ] ) {
	try {
		ClaimAttachment::validate( $filename, $content );
	} catch ( InvalidArgumentException ) {
		continue;
	}
	throw new RuntimeException( 'Failed to reject invalid attachment.' );
}

if ( ClaimAttachment::enabled() || null !== ClaimAttachment::from_upload( [] ) ) {
	throw new RuntimeException( 'Unexpected default upload settings.' );
}
update_option( 'wpessential_wbm_claim_uploads', 'yes' );
try {
	ClaimAttachment::from_upload( [ 'name' => 'receipt.png', 'tmp_name' => __FILE__, 'error' => UPLOAD_ERR_OK ] );
	throw new RuntimeException( 'Accepted a non-uploaded local file.' );
} catch ( InvalidArgumentException ) {
	// The HTTP upload path must reject ordinary filesystem paths.
} finally {
	delete_option( 'wpessential_wbm_claim_uploads' );
}

global $wpdb;
$repo = new ClaimRepository( $wpdb );
$order = wc_create_order();
if ( ! $order instanceof WC_Order ) {
	throw new RuntimeException( 'Could not create fixture order.' );
}
$id = $order->get_id();
$claim = [
	'date' => wp_date( 'Y-m-d' ),
	'amount' => new Money( 100, 'USD', 2 ),
	'reference' => '',
	'sender' => '',
	'note' => '',
];
try {
	$repo->save( $id, $claim, $receipt );
	$repo->save( $id, $claim, null );
	$attachment = $repo->attachment( $id );
	if ( 'image/png' !== $attachment['attachment_mime'] || $blob !== $attachment['attachment_bytes'] || ! $repo->has_attachment( $id ) ) {
		throw new RuntimeException( 'Attachment was not stored privately or a duplicate changed it.' );
	}

	wp_set_current_user( 0 );
	$action = 'wpessential_wbm_download_receipt:' . $id;
	if ( AttachmentDownloads::authorized( $id, wp_create_nonce( $action ) ) ) {
		throw new RuntimeException( 'Guest could authorize a receipt download.' );
	}
	wp_set_current_user( 1 );
	if ( ! AttachmentDownloads::authorized( $id, wp_create_nonce( $action ) ) ||
		AttachmentDownloads::authorized( $id, wp_create_nonce( $action . 'other' ) ) ) {
		throw new RuntimeException( 'Reviewer capability or nonce scope failed.' );
	}

	$table = $wpdb->prefix . 'wbm_payment_claims';
	$wpdb->query( $wpdb->prepare( "UPDATE `$table` SET attachment_expires = '2000-01-01 00:00:00' WHERE order_id = %d", $id ) );
	if ( null !== $repo->attachment( $id ) || $repo->has_attachment( $id ) ) {
		throw new RuntimeException( 'Expired receipt remained accessible.' );
	}
	do_action( AttachmentDownloads::CRON );
	$bytes = $wpdb->get_var( $wpdb->prepare( "SELECT attachment_bytes FROM `$table` WHERE order_id = %d", $id ) );
	if ( ! $repo->exists( $id ) || null !== $bytes || ! wp_next_scheduled( AttachmentDownloads::CRON ) ) {
		throw new RuntimeException( 'Retention did not purge only the attachment or the event was missing.' );
	}
	echo "PASS attachment validation, private bytes, duplicate safety, reviewer access and retention\n";
} finally {
	$wpdb->delete( $wpdb->prefix . 'wbm_payment_claims', [ 'order_id' => $id ], [ '%d' ] );
	$order->delete( true );
}
